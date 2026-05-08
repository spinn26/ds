<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Controller;
use App\Models\ChatTicket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    // Kept here (not in User) because staffList() builds a SQL LIKE over
    // the comma-separated role column; the flat list is the shape it needs.
    private static array $staffRoles = ['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections'];

    private function userName(Request $request): string
    {
        $u = $request->user();
        return trim(($u->lastName ?? '') . ' ' . ($u->firstName ?? ''));
    }

    // ==================== TICKETS ====================

    /** List tickets (staff see all, partners see own) */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isStaff = $request->user()->isStaff();

        $query = DB::table('chat_tickets');

        if (!$isStaff) {
            // Partner sees chats they created OR chats addressed to them
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere('recipient_id', $user->id);
            });
        } else {
            // Стафф: видимость по роли. Раньше любой стафф видел все
            // тикеты (поддержка читала юристические переписки и наоборот).
            // Теперь — только department, что матчит его роль; плюс
            // личное участие через OR (создатель / получатель / assigned).
            $roles = array_map('trim', explode(',', $user->role ?? ''));
            $allowed = TicketService::visibleCategoriesForRoles($roles);
            // Расширяем allowed legacy-алиасами, чтобы старые тикеты
            // с department=technical/billing/sales не выпадали из выдачи.
            $expanded = $allowed;
            foreach (TicketService::CATEGORY_ALIASES as $legacy => $modern) {
                if (in_array($modern, $allowed, true)) $expanded[] = $legacy;
            }
            $query->where(function ($q) use ($user, $expanded) {
                if (! empty($expanded)) {
                    $q->whereIn('department', $expanded);
                }
                $q->orWhere('created_by', $user->id)
                  ->orWhere('recipient_id', $user->id)
                  ->orWhere('assigned_to', $user->id);
            });
        }

        // Filters
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('priority')) $query->where('priority', $request->priority);
        if ($request->filled('department')) $query->where('department', TicketService::normalizeCategory($request->department));
        if ($request->filled('assigned_to')) $query->where('assigned_to', $request->assigned_to);
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('subject', 'ilike', $s)
                  ->orWhere('customer_name', 'ilike', $s)
                  ->orWhere('id', 'ilike', $s);
            });
        }

        $total = $query->count();
        $tickets = $query
            ->orderByRaw('pinned_at DESC NULLS LAST') // pinned first, nulls at the end
            ->orderByDesc('last_message_at')
            ->offset(max(0, ($request->input('page', 1) - 1) * 25))
            ->limit(25)
            ->get();

        // Batch unread counts with a single LEFT JOIN to chat_read_status
        // (was N+1: one COUNT per ticket).
        $ticketIds = $tickets->pluck('id')->filter();
        $unreadMap = collect();
        $lastMsgMap = collect();

        if ($ticketIds->isNotEmpty()) {
            $unreadMap = DB::table('chat_messages as cm')
                ->leftJoin('chat_read_status as rs', function ($join) use ($user) {
                    $join->on('rs.ticket_id', '=', 'cm.ticket_id')
                         ->where('rs.user_id', '=', $user->id);
                })
                ->whereIn('cm.ticket_id', $ticketIds)
                ->where('cm.sender_id', '!=', $user->id)
                ->where('cm.is_system', false)
                ->where(function ($q) {
                    $q->whereNull('rs.last_read_at')
                      ->orWhereColumn('cm.created_at', '>', 'rs.last_read_at');
                })
                ->groupBy('cm.ticket_id')
                ->select('cm.ticket_id', DB::raw('count(*) as unread'))
                ->pluck('unread', 'cm.ticket_id');

            // Last message preview per ticket — для отображения карточки в
            // sidebar в стиле Telegram/Slack: subject + первые 80 символов
            // последнего сообщения + кто его автор.
            $latestIds = DB::table('chat_messages')
                ->whereIn('ticket_id', $ticketIds)
                ->selectRaw('MAX(id) as id')
                ->groupBy('ticket_id')
                ->pluck('id');
            if ($latestIds->isNotEmpty()) {
                $lastMsgMap = DB::table('chat_messages')
                    ->whereIn('id', $latestIds)
                    ->select('ticket_id', 'sender_id', 'sender_name', 'content', 'is_agent', 'attachment_name', 'is_system', 'created_at')
                    ->get()
                    ->keyBy('ticket_id');
            }
        }

        $data = $tickets->map(function ($t) use ($unreadMap, $lastMsgMap, $user) {
            $t->unread = $unreadMap[$t->id] ?? 0;
            $lm = $lastMsgMap[$t->id] ?? null;
            if ($lm) {
                $preview = $lm->content
                    ? mb_substr($lm->content, 0, 80)
                    : ($lm->attachment_name ? '📎 ' . $lm->attachment_name : '');
                $t->last_message_preview = $preview;
                $t->last_message_from_me = (int) $lm->sender_id === (int) $user->id;
                $t->last_message_is_system = (bool) $lm->is_system;
                $t->last_message_sender = $lm->sender_name;
            } else {
                $t->last_message_preview = null;
                $t->last_message_from_me = false;
                $t->last_message_is_system = false;
                $t->last_message_sender = null;
            }
            return $t;
        });

        return response()->json([
            'data' => $data,
            'total' => $total,
            'last_page' => max(1, ceil($total / 25)),
        ]);
    }

    /** Create ticket */
    public function store(Request $request): JsonResponse
    {
        // Валидация принимает и новые ключи (TicketService::CATEGORIES),
        // и legacy-алиасы (technical/billing/sales/accounting) — фронт
        // на разных страницах пока шлёт разное; нормализуем перед INSERT.
        $allowedDepartments = array_merge(
            array_keys(TicketService::CATEGORIES),
            array_keys(TicketService::CATEGORY_ALIASES),
        );
        $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string|max:10000',
            'department' => 'required|in:' . implode(',', $allowedDepartments),
            'priority' => 'nullable|in:critical,high,medium,low',
            'message' => 'required|string|max:10000',
            'recipient_id' => 'nullable|integer|exists:WebUser,id',
            'context_type' => 'nullable|string|max:50',
            'context_id' => 'nullable|string|max:50',
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'string|max:50',
        ]);

        // Нормализуем legacy-ключ к актуальному (technical → support и т.д.).
        // Stored всегда modern key, чтобы фильтрация по роли работала
        // одинаково на новых и старых тикетах после backfill.
        $request->merge([
            'department' => TicketService::normalizeCategory($request->input('department')),
        ]);

        // Защита от XSS на write-side. Сейчас фронт рендерит content через
        // обычный {{ }} (text-биндинг), но если в будущем кто-то добавит
        // v-html или экспорт в HTML-PDF — дыра уже закрыта на уровне БД.
        $request->merge([
            'subject' => strip_tags((string) $request->input('subject')),
            'description' => $request->input('description') !== null
                ? strip_tags((string) $request->input('description')) : null,
            'message' => strip_tags((string) $request->input('message')),
        ]);

        $user = $request->user();
        $name = $this->userName($request);
        $now = now();

        // Resolve recipient name. recipient_id уже валиден exists:WebUser,id.
        $recipientId = $request->input('recipient_id');
        $recipientName = null;
        if ($recipientId) {
            $recipient = DB::table('WebUser')->where('id', $recipientId)->first();
            $recipientName = $recipient ? trim(($recipient->lastName ?? '') . ' ' . ($recipient->firstName ?? '')) : null;
        }

        // Если тикет создан из конкретного раздела (StartChatButton со
        // страниц Контракты/Клиенты/Транзакции/Реквизиты/Комиссии/Акцепт),
        // обогащаем первое сообщение деталями из БД — оператор видит
        // о чём речь без открытия отдельной вкладки.
        $messageBody = (string) $request->message;
        $contextSummary = $this->buildContextSummary(
            (string) $request->input('context_type', ''),
            (string) $request->input('context_id', ''),
        );
        if ($contextSummary !== '') {
            $messageBody = trim($messageBody) . "\n\n" . $contextSummary;
        }

        // Transaction: the ticket row is meaningless without its first message,
        // so either both land or neither does.
        $ticketId = DB::transaction(function () use ($request, $user, $name, $recipientId, $recipientName, $now, $messageBody) {
            $id = DB::table('chat_tickets')->insertGetId([
                'subject' => $request->subject,
                'description' => $request->description,
                'status' => 'new',
                'priority' => $request->input('priority', 'medium'),
                'department' => $request->department,
                'created_by' => $user->id,
                'customer_name' => $name,
                'customer_email' => $user->email,
                'recipient_id' => $recipientId,
                'recipient_name' => $recipientName,
                'context_type' => $request->input('context_type'),
                'context_id' => $request->input('context_id'),
                'tags' => $request->tags ? json_encode($request->tags) : null,
                'messages_count' => 1,
                'last_message_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('chat_messages')->insert([
                'ticket_id' => $id,
                'sender_id' => $user->id,
                'sender_name' => $name,
                'content' => $messageBody,
                'is_agent' => $request->user()->isStaff(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $id;
        });

        // Notify via socket
        try {
            app(\App\Services\SocketService::class)->emit('chat:new-ticket', null, [
                'ticketId' => $ticketId,
                'subject' => $request->subject,
                'department' => $request->department,
                'customerName' => $name,
            ]);
        } catch (\Exception $e) {
            Log::warning('chat socket emit failed: new-ticket', ['ticket_id' => $ticketId, 'exception' => $e->getMessage()]);
        }

        $ticket = DB::table('chat_tickets')->where('id', $ticketId)->first();

        return response()->json(['ticket' => $ticket], 201);
    }

    /** Get ticket with messages */
    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = ChatTicket::find($id);
        if (!$ticket) return response()->json(['message' => 'Не найден'], 404);

        if ($request->user()->cannot('view', $ticket)) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $userId = $request->user()->id;

        $rawMessages = DB::table('chat_messages')
            ->where('ticket_id', $id)
            ->orderBy('created_at')
            ->get();

        // Batch-load reply targets to avoid N+1.
        $replyIds = $rawMessages->pluck('reply_to_id')->filter()->unique();
        $replies = $replyIds->isNotEmpty()
            ? DB::table('chat_messages')->whereIn('id', $replyIds)->get()->keyBy('id')
            : collect();

        // Batch-load reactions
        $messageIds = $rawMessages->pluck('id');
        $reactionsByMessage = $messageIds->isNotEmpty()
            ? DB::table('chat_message_reactions')
                ->whereIn('message_id', $messageIds)
                ->get()
                ->groupBy('message_id')
            : collect();

        $messages = $rawMessages->map(function ($m) use ($replies, $reactionsByMessage, $userId) {
            $replyTo = null;
            if ($m->reply_to_id && isset($replies[$m->reply_to_id])) {
                $r = $replies[$m->reply_to_id];
                $replyTo = [
                    'id' => $r->id,
                    'senderName' => $r->sender_name,
                    'content' => mb_substr((string) $r->content, 0, 140),
                ];
            }
            // Aggregate reactions: { '👍': { count: 3, mine: true }, ... }
            $rxs = $reactionsByMessage->get($m->id, collect());
            $reactions = [];
            foreach ($rxs as $r) {
                if (! isset($reactions[$r->emoji])) {
                    $reactions[$r->emoji] = ['emoji' => $r->emoji, 'count' => 0, 'mine' => false];
                }
                $reactions[$r->emoji]['count']++;
                if ((int) $r->user_id === (int) $userId) {
                    $reactions[$r->emoji]['mine'] = true;
                }
            }

            return [
                'id' => $m->id,
                'content' => $m->content,
                'senderId' => $m->sender_id,
                'senderName' => $m->sender_name,
                'isAgent' => (bool) $m->is_agent,
                'isSystem' => (bool) $m->is_system,
                // Защищённый endpoint: путь скрыт, фронт скачивает по id.
                // Партнёр без доступа к тикету получит 403 на /api/v1/chat/messages/{id}/attachment.
                'attachmentPath' => $m->attachment_path ? "/api/v1/chat/messages/{$m->id}/attachment" : null,
                'attachmentName' => $m->attachment_name,
                'createdAt' => $m->created_at,
                'editedAt' => $m->edited_at ?? null,
                'replyTo' => $replyTo,
                'reactions' => array_values($reactions),
            ];
        });

        // Mark current user as read
        DB::table('chat_read_status')->updateOrInsert(
            ['ticket_id' => $id, 'user_id' => $userId],
            ['last_read_at' => now()]
        );

        // Latest last_read_at of any OTHER participant — powers ✓✓ receipts.
        $otherLastReadAt = DB::table('chat_read_status')
            ->where('ticket_id', $id)
            ->where('user_id', '!=', $userId)
            ->max('last_read_at');

        // Partner context — staff-only, lets the agent see who they're talking to
        $partnerContext = null;
        if ($request->user()->isStaff()) {
            $partnerContext = $this->buildPartnerContext((int) $ticket->created_by);
        }

        return response()->json([
            'ticket' => $ticket,
            'messages' => $messages,
            'otherLastReadAt' => $otherLastReadAt,
            'partnerContext' => $partnerContext,
        ]);
    }

    /** Send message to ticket */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $ticket = ChatTicket::find($id);
        if (!$ticket) return response()->json(['message' => 'Не найден'], 404);

        if ($request->user()->cannot('view', $ticket)) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $request->validate([
            'message' => 'nullable|string|max:10000',
            'attachment' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,gif|max:10240',
            'reply_to_id' => 'nullable|integer',
            // Идемпотентный клиент-id для дедупа в фронте (опционально).
            'client_message_id' => 'nullable|string|max:64',
        ]);

        // Снимаем теги из контента — backend хранит plain text.
        if ($request->filled('message')) {
            $request->merge(['message' => strip_tags((string) $request->input('message'))]);
        }

        $user = $request->user();
        $name = $this->userName($request);
        $isAgent = $request->user()->isStaff();
        $now = now();
        $clientMessageId = (string) ($request->input('client_message_id') ?? '');

        // Дедуп: если та же пара (ticket, client_message_id) уже сохранена —
        // возвращаем существующий id, ничего не пишем повторно. Защищает от
        // дублей при retry / двойном click / гонке HTTP+socket.
        if ($clientMessageId !== '') {
            $existingId = DB::table('chat_messages')
                ->where('ticket_id', $id)
                ->where('client_message_id', $clientMessageId)
                ->value('id');
            if ($existingId) {
                return response()->json(['id' => (int) $existingId, 'deduplicated' => true]);
            }
        }

        // Аттач кладём в ПРИВАТНОЕ хранилище. Скачивание — только через
        // защищённый endpoint downloadAttachment с auth-проверкой доступа
        // к тикету (см. блокер «IDOR на attachments»).
        $attachmentPath = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentPath = $file->store("chat/{$id}", 'local');
            $attachmentName = $file->getClientOriginalName();
        }

        if (!$request->message && !$attachmentPath) {
            return response()->json(['message' => 'Сообщение или файл обязательны'], 422);
        }

        // Validate reply target belongs to the same ticket
        $replyToId = null;
        if ($request->filled('reply_to_id')) {
            $exists = DB::table('chat_messages')
                ->where('id', $request->reply_to_id)
                ->where('ticket_id', $id)
                ->exists();
            if ($exists) {
                $replyToId = (int) $request->reply_to_id;
            }
        }

        // Атомарно: insert сообщения + update счётчиков и статуса тикета.
        // Socket-emit ВЫНЕСЕН за пределы транзакции — иначе при rollback
        // клиенты получили бы сообщение, которого нет в БД.
        $msgId = DB::transaction(function () use ($id, $user, $name, $isAgent, $request,
            $attachmentPath, $attachmentName, $replyToId, $clientMessageId, $now, $ticket) {
            $msgId = DB::table('chat_messages')->insertGetId([
                'ticket_id' => $id,
                'sender_id' => $user->id,
                'sender_name' => $name,
                'content' => $request->message ?? '',
                'is_agent' => $isAgent,
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
                'reply_to_id' => $replyToId,
                'client_message_id' => $clientMessageId !== '' ? $clientMessageId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $update = ['messages_count' => DB::raw('messages_count + 1'),
                'last_message_at' => $now, 'updated_at' => $now];
            if ($ticket->status === 'new' && $isAgent) {
                $update['status'] = 'open';
                $update['assigned_to'] = $ticket->assigned_to ?? $user->id;
                $update['assigned_name'] = $ticket->assigned_name ?? $name;
            }
            DB::table('chat_tickets')->where('id', $id)->update($update);

            return $msgId;
        });

        // Socket emit ПОСЛЕ commit. Фронт дедуплицирует через clientMessageId.
        try {
            app(\App\Services\SocketService::class)->emit('chat:new-message', "ticket:{$id}", [
                'id' => $msgId,
                'clientMessageId' => $clientMessageId !== '' ? $clientMessageId : null,
                'ticketId' => $id,
                'senderId' => $user->id,
                'senderName' => $name,
                'content' => $request->message,
                'isAgent' => $isAgent,
                'createdAt' => $now->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::warning('chat socket emit failed: new-message', ['ticket_id' => $id, 'message_id' => $msgId, 'exception' => $e->getMessage()]);
        }

        // Personal notification to the other side of the ticket.
        // Раньше тут было $ticket->user_id — этого поля не существует в
        // chat_tickets, поэтому уведомления партнёру никогда не уходили.
        // Реальные поля схемы: created_by (автор), recipient_id (другая
        // сторона приватного staff↔partner), assigned_to (назначенный staff).
        if ($isAgent) {
            // Staff отвечает → шлём партнёру: автор тикета, либо вторая сторона
            // приватного диалога если автор это сам staff.
            $recipientId = (int) ($ticket->created_by ?? 0);
            if ($recipientId === (int) $user->id) {
                $recipientId = (int) ($ticket->recipient_id ?? 0);
            }
        } else {
            // Партнёр пишет → шлём назначенному staff, либо в личный диалог.
            $recipientId = (int) ($ticket->assigned_to ?? $ticket->recipient_id ?? 0);
        }
        if ($recipientId && $recipientId !== (int) $user->id) {
            NotificationController::create(
                $recipientId,
                'chat',
                $isAgent ? 'Ответ по обращению' : 'Новое сообщение в чате',
                mb_substr($request->message ?? '', 0, 120) ?: 'Отправлено вложение',
                $isAgent ? "/chat?ticket={$id}" : "/manage/chat?ticket={$id}"
            );
        }

        return response()->json(['id' => $msgId]);
    }

    /**
     * Edit own message within 5 minutes of creation.
     */
    public function editMessage(Request $request, int $messageId): JsonResponse
    {
        $request->validate(['content' => 'required|string|max:5000']);

        $msg = DB::table('chat_messages')->where('id', $messageId)->first();
        if (! $msg) return response()->json(['message' => 'Сообщение не найдено'], 404);

        $userId = $request->user()->id;
        if ((int) $msg->sender_id !== (int) $userId) {
            return response()->json(['message' => 'Можно редактировать только свои сообщения'], 403);
        }

        $createdAt = \Carbon\Carbon::parse($msg->created_at);
        if ($createdAt->diffInMinutes(now()) > 5) {
            return response()->json(['message' => 'Изменить можно только в течение 5 минут'], 422);
        }

        if ($msg->is_system) {
            return response()->json(['message' => 'Системные сообщения не редактируются'], 422);
        }

        DB::table('chat_messages')->where('id', $messageId)->update([
            'content' => $request->content,
            'edited_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(\App\Services\SocketService::class)->emit('chat:message-edited', "ticket:{$msg->ticket_id}", [
                'id' => $messageId,
                'ticketId' => $msg->ticket_id,
                'content' => $request->content,
                'editedAt' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::warning('chat socket emit failed: message-edited', ['message_id' => $messageId, 'exception' => $e->getMessage()]);
        }

        return response()->json(['id' => $messageId, 'editedAt' => now()->toIso8601String()]);
    }

    /**
     * Toggle pinned_at on a ticket. Pin is per-ticket (shared across users),
     * not per-viewer — the assumption is pinning marks "важное" across the team.
     */
    public function togglePin(Request $request, int $id): JsonResponse
    {
        $ticket = ChatTicket::find($id);
        if (! $ticket) return response()->json(['message' => 'Не найден'], 404);

        if ($request->user()->cannot('view', $ticket)) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $newValue = $ticket->pinned_at ? null : now();
        DB::table('chat_tickets')->where('id', $id)->update([
            'pinned_at' => $newValue,
            'updated_at' => now(),
        ]);

        try {
            app(\App\Services\SocketService::class)->emit('chat:ticket-updated', null, [
                'ticketId' => $id,
                'pinnedAt' => $newValue ? $newValue->toIso8601String() : null,
            ]);
        } catch (\Exception $e) {
            Log::warning('chat socket emit failed: ticket-updated (pin)', ['ticket_id' => $id, 'exception' => $e->getMessage()]);
        }

        return response()->json([
            'id' => $id,
            'pinnedAt' => $newValue ? $newValue->toIso8601String() : null,
        ]);
    }

    /**
     * Toggle a reaction on a message. If the same (message, user, emoji) tuple
     * exists — remove it; otherwise insert.
     */
    public function toggleReaction(Request $request, int $messageId): JsonResponse
    {
        $request->validate(['emoji' => 'required|string|max:16']);

        $msg = DB::table('chat_messages')->where('id', $messageId)->first();
        if (! $msg) return response()->json(['message' => 'Сообщение не найдено'], 404);

        // Authorize: must have access to the ticket
        $userId = $request->user()->id;
        $ticket = ChatTicket::find($msg->ticket_id);
        if (! $ticket) return response()->json(['message' => 'Не найден'], 404);
        if ($request->user()->cannot('view', $ticket)) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $emoji = $request->input('emoji');
        $existing = DB::table('chat_message_reactions')
            ->where('message_id', $messageId)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            DB::table('chat_message_reactions')->where('id', $existing->id)->delete();
            $action = 'removed';
        } else {
            DB::table('chat_message_reactions')->insert([
                'message_id' => $messageId,
                'user_id' => $userId,
                'emoji' => $emoji,
                'created_at' => now(),
            ]);
            $action = 'added';
        }

        // Broadcast so everyone in the room updates live
        try {
            app(\App\Services\SocketService::class)->emit('chat:reaction-toggled', "ticket:{$msg->ticket_id}", [
                'messageId' => $messageId,
                'ticketId' => $msg->ticket_id,
                'emoji' => $emoji,
                'userId' => $userId,
                'action' => $action,
            ]);
        } catch (\Exception $e) {
            Log::warning('chat socket emit failed: reaction-toggled', ['message_id' => $messageId, 'exception' => $e->getMessage()]);
        }

        return response()->json(['action' => $action]);
    }

    /** Change ticket status */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isStaff()) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }

        $request->validate([
            'status' => 'required|in:new,open,pending,resolved,closed',
            'priority' => 'nullable|in:critical,high,medium,low',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:64',
        ]);

        $existing = DB::table('chat_tickets')->where('id', $id)->first();
        if (! $existing) return response()->json(['message' => 'Не найден'], 404);

        $update = ['updated_at' => now()];
        $statusChanged = $existing->status !== $request->status;
        $update['status'] = $request->status;
        if ($request->status === 'closed') {
            $update['closed_at'] = now();
            $update['closed_by'] = $request->user()->id;
        }

        $priorityChanged = false;
        if ($request->filled('priority') && $request->priority !== $existing->priority) {
            $update['priority'] = $request->priority;
            $priorityChanged = true;
        }

        $tagsChanged = false;
        if ($request->has('tags')) {
            $update['tags'] = json_encode(array_values($request->input('tags', [])), JSON_UNESCAPED_UNICODE);
            $tagsChanged = ($update['tags'] !== ($existing->tags ?? null));
        }

        DB::table('chat_tickets')->where('id', $id)->update($update);

        // Audit log: одна строка на каждое реально изменённое поле.
        if ($statusChanged) {
            $this->logTicketChange($id, 'status', $existing->status, $request->status, $request->user());
        }
        if ($priorityChanged) {
            $this->logTicketChange($id, 'priority', $existing->priority, $request->priority, $request->user());
        }
        if ($tagsChanged) {
            $this->logTicketChange($id, 'tags', $existing->tags, $update['tags'], $request->user());
        }

        // System message only for status changes (other changes are silent)
        if ($statusChanged) {
            $statusLabels = ['new' => 'Новый', 'open' => 'Открыт', 'pending' => 'Ожидание', 'resolved' => 'Решён', 'closed' => 'Закрыт'];
            DB::table('chat_messages')->insert([
                'ticket_id' => $id,
                'sender_id' => $request->user()->id,
                'sender_name' => $this->userName($request),
                'content' => 'Статус изменён → ' . ($statusLabels[$request->status] ?? $request->status),
                'is_system' => true,
                'is_agent' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Broadcast so other staff see the change live (Kanban board, ticket list)
        if ($statusChanged || $priorityChanged || $tagsChanged) {
            try {
                $payload = ['ticketId' => $id];
                if ($statusChanged) $payload['status'] = $request->status;
                if ($priorityChanged) $payload['priority'] = $request->priority;
                if ($tagsChanged) $payload['tags'] = $update['tags'];
                app(\App\Services\SocketService::class)->emit('chat:ticket-updated', null, $payload);
            } catch (\Exception $e) {
                Log::warning('chat socket emit failed: ticket-updated (status)', ['ticket_id' => $id, 'exception' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => 'Обновлено',
            'statusChanged' => $statusChanged,
            'priorityChanged' => $priorityChanged,
            'tagsChanged' => $tagsChanged,
        ]);
    }

    /**
     * CSAT (Customer Satisfaction) — оценка тикета партнёром после resolve.
     * 1-5 звёзд + опциональный комментарий. Доступно только создателю
     * тикета (или recipient_id для приватных staff↔partner) и только когда
     * статус resolved/closed. Ставить можно один раз.
     */
    public function submitCsat(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $ticket = DB::table('chat_tickets')->where('id', $id)->first();
        if (! $ticket) return response()->json(['message' => 'Не найден'], 404);

        $userId = (int) $request->user()->id;
        $isOwner = (int) $ticket->created_by === $userId
            || (int) ($ticket->recipient_id ?? 0) === $userId;
        if (! $isOwner) {
            return response()->json(['message' => 'Оценить может только участник тикета'], 403);
        }
        if (! in_array($ticket->status, ['resolved', 'closed'], true)) {
            return response()->json(['message' => 'Оценить можно только закрытый тикет'], 422);
        }
        if ($ticket->csat_rating !== null) {
            return response()->json(['message' => 'Оценка уже поставлена'], 422);
        }

        DB::table('chat_tickets')->where('id', $id)->update([
            'csat_rating' => $data['rating'],
            'csat_comment' => $data['comment'] ?? null,
            'csat_at' => now(),
            'updated_at' => now(),
        ]);

        // Системное сообщение в ленту, чтобы staff видел оценку без перезагрузки.
        DB::table('chat_messages')->insert([
            'ticket_id' => $id,
            'sender_id' => $userId,
            'sender_name' => $this->userName($request),
            'content' => 'Оценка: ' . str_repeat('★', $data['rating']) . str_repeat('☆', 5 - $data['rating'])
                . ($data['comment'] ? ' · ' . $data['comment'] : ''),
            'is_system' => true,
            'is_agent' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Уведомить assigned-staff
        if ($ticket->assigned_to) {
            NotificationController::create(
                (int) $ticket->assigned_to,
                'chat',
                "Оценка тикета: {$data['rating']} ★",
                $data['comment'] ?: 'Партнёр оценил ваш ответ',
                "/manage/chat?ticket={$id}",
            );
        }

        return response()->json(['message' => 'Спасибо за оценку!', 'rating' => $data['rating']]);
    }

    /** Assign ticket to staff */
    public function assign(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isStaff()) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }

        $request->validate(['user_id' => 'required|integer|exists:WebUser,id']);

        $existing = DB::table('chat_tickets')->where('id', $id)
            ->select('assigned_to', 'assigned_name', 'status')->first();
        if (! $existing) return response()->json(['message' => 'Не найден'], 404);

        $assignee = DB::table('WebUser')->where('id', $request->user_id)->first();
        $assigneeName = $assignee ? trim(($assignee->lastName ?? '') . ' ' . ($assignee->firstName ?? '')) : '—';

        DB::table('chat_tickets')->where('id', $id)->update([
            'assigned_to' => $request->user_id,
            'assigned_name' => $assigneeName,
            'status' => 'open',
            'updated_at' => now(),
        ]);

        // Audit log: assignment + (если был) переход статуса в open.
        if ((int) ($existing->assigned_to ?? 0) !== (int) $request->user_id) {
            $this->logTicketChange($id, 'assigned_to',
                $existing->assigned_name ?? (string) ($existing->assigned_to ?? ''),
                $assigneeName, $request->user());
        }
        if ($existing->status !== 'open') {
            $this->logTicketChange($id, 'status', $existing->status, 'open', $request->user());
        }

        DB::table('chat_messages')->insert([
            'ticket_id' => $id,
            'sender_id' => $request->user()->id,
            'sender_name' => $this->userName($request),
            'content' => 'Назначен → ' . $assigneeName,
            'is_system' => true,
            'is_agent' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(\App\Services\SocketService::class)->emit('chat:ticket-updated', null, [
                'ticketId' => $id,
                'assignedTo' => $request->user_id,
                'assignedName' => $assigneeName,
                'status' => 'open',
            ]);
        } catch (\Exception $e) {
            Log::warning('chat socket emit failed: ticket-updated (assign)', ['ticket_id' => $id, 'exception' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Назначен']);
    }

    // ==================== INTERNAL NOTES ====================

    public function notes(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->isStaff()) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }

        $notes = DB::table('chat_internal_notes')
            ->where('ticket_id', $id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'authorId' => $n->author_id,
                'authorName' => $n->author_name,
                'content' => $n->content,
                'createdAt' => $n->created_at,
            ]);

        return response()->json(['data' => $notes]);
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isStaff()) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }

        $request->validate(['content' => 'required|string']);

        $noteId = DB::table('chat_internal_notes')->insertGetId([
            'ticket_id' => $id,
            'author_id' => $request->user()->id,
            'author_name' => $this->userName($request),
            'content' => $request->content,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $noteId], 201);
    }

    // ==================== QUICK REPLIES ====================

    public function quickReplies(): JsonResponse
    {
        return response()->json(DB::table('chat_quick_replies')->orderBy('category')->get());
    }

    // ==================== KNOWLEDGE BASE ====================

    public function knowledgeArticles(Request $request): JsonResponse
    {
        $query = DB::table('chat_knowledge_articles');
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('title', 'ilike', $s)->orWhere('content', 'ilike', $s);
            });
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        $limit = min(50, max(1, (int) $request->input('limit', 50)));

        return response()->json(
            $query->orderByDesc('views')->limit($limit)->get()
        );
    }

    /**
     * Suggestions for a specific ticket: look up articles whose title or
     * content overlaps with the ticket subject / category / tags. Returns
     * up to 5 items ranked by a simple substring count.
     */
    /**
     * GET /chat/tickets/{id}/partner-context
     *
     * Карточка автора тикета для правой контекстной панели в StaffChat.
     * Возвращает: профиль WebUser (ФИО / e-mail / телефон), партнёрский
     * блок (статус активности, квалификация, ЛП/ГП/НГП, кол-во клиентов
     * и контрактов, реф-код, пригласитель, дедлайн активации, кол-во
     * терминаций, год до …) и до 5 последних контрактов.
     *
     * Доступ: только staff.
     */
    public function partnerContext(Request $request, int $id): JsonResponse
    {
        if (! $request->user() || ! $request->user()->isStaff()) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $ticket = DB::table('chat_tickets')->where('id', $id)
            ->select('id', 'created_by')->first();
        if (! $ticket) {
            return response()->json(['message' => 'Тикет не найден'], 404);
        }

        $context = $this->buildPartnerContext((int) $ticket->created_by);
        return response()->json($context);
    }

    /**
     * Сборка контекста партнёра по WebUser.id.
     * Используется и в show() (чтобы вернуть всё одним запросом для UI),
     * и в отдельном endpoint partnerContext() (для refresh без перезагрузки
     * сообщений).
     *
     * @return array{user:?array,consultant:?array,recentContracts:\Illuminate\Support\Collection}
     */
    /**
     * Сформировать текстовую сводку по объекту, из которого создан тикет.
     * Возвращает многострочный markdown-стиль (без HTML — content рендерится
     * через text-биндинг). Если `context_type` не известен или объект
     * не найден — возвращает пустую строку.
     *
     * Используется при создании тикета через StartChatButton, чтобы первое
     * сообщение содержало полную картину (клиент / контракт / сумма / даты),
     * а не только «Чат создан из раздела: Контракт — #69171».
     */
    private function buildContextSummary(string $type, string $id): string
    {
        if ($type === '' || $id === '' || ! ctype_digit($id)) return '';
        $idInt = (int) $id;
        $lines = [];

        switch ($type) {
            case 'Контракт':
                $r = DB::table('contract as c')
                    ->leftJoin('product as p', 'p.id', '=', 'c.product')
                    ->leftJoin('client as cl', 'cl.id', '=', 'c.client')
                    ->leftJoin('consultant as co', 'co.id', '=', 'c.consultant')
                    ->leftJoin('currency as cur', 'cur.id', '=', 'c.currency')
                    ->where('c.id', $idInt)
                    ->select([
                        'c.id', 'c.number', 'c.ammount', 'c.status',
                        'c.createDate', 'c.openDate', 'c.closeDate',
                        'p.name as productName',
                        'cl.personName as clientName',
                        'co.personName as consultantName',
                        'cur.symbol as currencySymbol',
                    ])
                    ->first();
                if (! $r) return '';
                $lines[] = "**Контракт #{$r->number}**" . ($r->productName ? " · {$r->productName}" : '');
                if ($r->clientName)     $lines[] = "• Клиент: {$r->clientName}";
                if ($r->consultantName) $lines[] = "• Партнёр: {$r->consultantName}";
                if ($r->ammount)        $lines[] = "• Сумма: " . number_format((float) $r->ammount, 0, ',', ' ') . ' ' . ($r->currencySymbol ?? '');
                if ($r->openDate)       $lines[] = "• Открыт: " . substr($r->openDate, 0, 10);
                if ($r->closeDate)      $lines[] = "• Закрыт: " . substr($r->closeDate, 0, 10);
                break;

            case 'Клиент':
                $r = DB::table('client as cl')
                    ->leftJoin('person as pe', 'pe.id', '=', 'cl.person')
                    ->leftJoin('consultant as co', 'co.id', '=', 'cl.consultant')
                    ->where('cl.id', $idInt)
                    ->select([
                        'cl.id', 'cl.personName',
                        'pe.email', 'pe.phone', 'pe.birthDate',
                        'co.personName as consultantName',
                    ])
                    ->first();
                if (! $r) return '';
                $lines[] = "**Клиент #{$r->id}** · " . ($r->personName ?? '—');
                if ($r->consultantName) $lines[] = "• Партнёр: {$r->consultantName}";
                if ($r->email)          $lines[] = "• Email: {$r->email}";
                if ($r->phone)          $lines[] = "• Телефон: {$r->phone}";
                if ($r->birthDate)      $lines[] = "• ДР: " . substr($r->birthDate, 0, 10);
                $contractCount = (int) DB::table('contract')->where('client', $idInt)->whereNull('deletedAt')->count();
                if ($contractCount > 0) $lines[] = "• Активных контрактов: {$contractCount}";
                break;

            case 'Транзакция':
                $r = DB::table('transaction as t')
                    ->leftJoin('contract as c', 'c.id', '=', 't.contract')
                    ->leftJoin('product as p', 'p.id', '=', 'c.product')
                    ->leftJoin('client as cl', 'cl.id', '=', 'c.client')
                    ->leftJoin('consultant as co', 'co.id', '=', 'c.consultant')
                    ->where('t.id', $idInt)
                    ->select([
                        't.id', 't."amountRUB" as amountRUB', 't.score', 't.date',
                        'c.number as contractNumber',
                        'p.name as productName',
                        'cl.personName as clientName',
                        'co.personName as consultantName',
                    ])
                    ->first();
                if (! $r) return '';
                $lines[] = "**Транзакция #{$r->id}**";
                if ($r->contractNumber) $lines[] = "• Контракт: #{$r->contractNumber}" . ($r->productName ? " · {$r->productName}" : '');
                if ($r->clientName)     $lines[] = "• Клиент: {$r->clientName}";
                if ($r->consultantName) $lines[] = "• Партнёр: {$r->consultantName}";
                if ($r->amountRUB)      $lines[] = "• Сумма ₽: " . number_format((float) $r->amountRUB, 0, ',', ' ');
                if ($r->date)           $lines[] = "• Дата: " . substr($r->date, 0, 10);
                if ($r->score)          $lines[] = "• Год КВ: {$r->score}";
                break;

            case 'Реквизиты':
                $r = DB::table('requisites as r')
                    ->leftJoin('consultant as co', 'co.id', '=', 'r.consultant')
                    ->where('r.id', $idInt)
                    ->select([
                        'r.id', 'r.individualEntrepreneur', 'r.inn',
                        'r.verified', 'r.status',
                        'co.personName as consultantName',
                    ])
                    ->first();
                if (! $r) return '';
                $lines[] = "**Реквизиты #{$r->id}**" . ($r->individualEntrepreneur ? " · {$r->individualEntrepreneur}" : '');
                if ($r->consultantName) $lines[] = "• Партнёр: {$r->consultantName}";
                if ($r->inn)            $lines[] = "• ИНН: {$r->inn}";
                $lines[] = "• Статус: " . ($r->verified ? 'Верифицированы' : 'На проверке');
                break;

            case 'Комиссия':
                $r = DB::table('commission as cm')
                    ->leftJoin('contract as c', 'c.id', '=', 'cm.contract')
                    ->leftJoin('product as p', 'p.id', '=', 'c.product')
                    ->leftJoin('consultant as co', 'co.id', '=', 'cm.consultant')
                    ->where('cm.id', $idInt)
                    ->select([
                        'cm.id', 'cm."amountRUB" as amountRUB', 'cm."chainOrder" as chainOrder',
                        'cm.date', 'cm."personalVolume" as personalVolume', 'cm."groupVolume" as groupVolume',
                        'c.number as contractNumber',
                        'p.name as productName',
                        'co.personName as consultantName',
                    ])
                    ->first();
                if (! $r) return '';
                $lines[] = "**Комиссия #{$r->id}**";
                if ($r->consultantName) $lines[] = "• Партнёр: {$r->consultantName}";
                if ($r->contractNumber) $lines[] = "• Контракт: #{$r->contractNumber}" . ($r->productName ? " · {$r->productName}" : '');
                if ($r->amountRUB)      $lines[] = "• Сумма ₽: " . number_format((float) $r->amountRUB, 0, ',', ' ');
                if ($r->personalVolume) $lines[] = "• ЛП: " . number_format((float) $r->personalVolume, 0, ',', ' ');
                if ($r->groupVolume)    $lines[] = "• ГП: " . number_format((float) $r->groupVolume, 0, ',', ' ');
                if ($r->chainOrder)     $lines[] = "• Уровень в цепочке: {$r->chainOrder}";
                if ($r->date)           $lines[] = "• Период: " . substr($r->date, 0, 7);
                break;

            case 'Акцепт':
                // taxAcceptanceLog хранит логи акцепта налоговых деклараций;
                // структура — id, consultant, year, status и т.п.
                if (\Illuminate\Support\Facades\Schema::hasTable('taxAcceptanceLog')) {
                    $r = DB::table('taxAcceptanceLog as a')
                        ->leftJoin('consultant as co', 'co.id', '=', 'a.consultant')
                        ->where('a.id', $idInt)
                        ->select(['a.id', 'a.year', 'a.status', 'co.personName as consultantName'])
                        ->first();
                    if ($r) {
                        $lines[] = "**Акцепт #{$r->id}**";
                        if ($r->consultantName) $lines[] = "• Партнёр: {$r->consultantName}";
                        if ($r->year)           $lines[] = "• Год: {$r->year}";
                        if ($r->status)         $lines[] = "• Статус: {$r->status}";
                    }
                }
                break;

            default:
                return '';
        }

        if (empty($lines)) return '';
        return implode("\n", $lines);
    }

    private function buildPartnerContext(int $webUserId): array
    {
        // ВАЖНО: в WebUser колонка `avatar` (хранит относительный путь),
        // а наружу через UserResource всегда отдаётся `avatarUrl`. Здесь
        // выбираем `avatar` и собираем URL ниже — иначе SQL падает с
        // "column avatarUrl does not exist".
        $user = DB::table('WebUser')->where('id', $webUserId)
            ->select('id', 'lastName', 'firstName', 'patronymic', 'email', 'phone', 'avatar')
            ->first();
        if (! $user) {
            return ['user' => null, 'consultant' => null, 'recentContracts' => collect()];
        }

        // Один SQL: партнёрская строка + связанные справочники.
        // Учёт двух-id-спейса (см. CLAUDE.md): ищем по consultant.webUser,
        // а не по совпадению id.
        $consultantRow = DB::table('consultant as c')
            ->leftJoin('directory_of_activities as pa', 'pa.id', '=', 'c.activity')
            ->leftJoin('status_levels as sl', 'sl.id', '=', 'c.status_and_lvl')
            ->leftJoin('consultant as inv', 'inv.id', '=', 'c.inviter')
            ->where('c.webUser', $user->id)
            ->whereNull('c.dateDeleted')
            ->select([
                'c.id',
                'c.activity as activityId',
                'pa.name as activityName',
                'sl.title as qualificationName',
                'sl.level as qualificationLevel',
                'c.participantCode',
                'c.personalVolume',
                'c.groupVolume',
                'c.groupVolumeCumulative',
                'c.dateActivity',
                'c.yearPeriodEnd',
                'c.activationDeadline',
                'c.terminationCount',
                'inv.personName as inviterName',
            ])
            ->first();

        $consultant = null;
        $recentContracts = collect();
        if ($consultantRow) {
            $clientsCount = (int) DB::table('client')
                ->where('consultant', $consultantRow->id)
                ->whereNull('dateDeleted')
                ->count();
            $contractsCount = (int) DB::table('contract')
                ->where('consultant', $consultantRow->id)
                ->whereNull('deletedAt')
                ->count();

            $consultant = [
                'id' => $consultantRow->id,
                'activityId' => $consultantRow->activityId,
                'activityName' => $consultantRow->activityName,
                'qualificationName' => $consultantRow->qualificationName,
                'qualificationLevel' => $consultantRow->qualificationLevel,
                'participantCode' => $consultantRow->participantCode,
                'personalVolume' => (float) ($consultantRow->personalVolume ?? 0),
                'groupVolume' => (float) ($consultantRow->groupVolume ?? 0),
                'groupVolumeCumulative' => (float) ($consultantRow->groupVolumeCumulative ?? 0),
                'dateActivity' => $consultantRow->dateActivity,
                'yearPeriodEnd' => $consultantRow->yearPeriodEnd,
                'activationDeadline' => $consultantRow->activationDeadline,
                'terminationCount' => (int) ($consultantRow->terminationCount ?? 0),
                'inviterName' => $consultantRow->inviterName,
                'clientsCount' => $clientsCount,
                'contractsCount' => $contractsCount,
            ];

            $recentContracts = DB::table('contract as ct')
                ->leftJoin('client as cl', 'cl.id', '=', 'ct.client')
                ->leftJoin('product as p', 'p.id', '=', 'ct.product')
                ->where('ct.consultant', $consultantRow->id)
                ->whereNull('ct.deletedAt')
                ->orderByDesc('ct.openDate')
                ->limit(5)
                ->select([
                    'ct.id',
                    'ct.number',
                    'ct.ammount as amount',
                    'cl.personName as clientName',
                    'p.name as productName',
                    'ct.openDate',
                ])
                ->get();
        }

        return [
            'user' => [
                'id' => $user->id,
                'lastName' => $user->lastName,
                'firstName' => $user->firstName,
                'patronymic' => $user->patronymic,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatarUrl' => $user->avatar ? '/storage/' . $user->avatar : null,
            ],
            'consultant' => $consultant,
            'recentContracts' => $recentContracts,
        ];
    }

    /**
     * Записать изменение тикета в audit-log. Best-effort: если таблица
     * ещё не существует (старая БД без миграции), молчим.
     */
    private function logTicketChange(int $ticketId, string $field, $oldValue, $newValue, $user): void
    {
        try {
            DB::table('chat_ticket_changes')->insert([
                'ticket_id' => $ticketId,
                'field' => $field,
                'old_value' => $oldValue !== null ? (string) $oldValue : null,
                'new_value' => $newValue !== null ? (string) $newValue : null,
                'changed_by' => $user?->id,
                'changed_by_name' => $user
                    ? trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? ''))
                    : null,
                'changed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('chat_ticket_changes insert skipped: ' . $e->getMessage());
        }
    }

    /**
     * GET /chat/tickets/{id}/changes — история всех изменений тикета.
     * Доступно только staff (для compliance / debug).
     */
    public function changes(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->isStaff()) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }
        $rows = DB::table('chat_ticket_changes')
            ->where('ticket_id', $id)
            ->orderByDesc('changed_at')
            ->limit(200)
            ->get();
        return response()->json(['data' => $rows]);
    }

    /**
     * GET /chat/messages/{messageId}/attachment
     *
     * Защищённое скачивание вложения. Файл лежит в private storage
     * (`storage/app/chat/...`), доступ — только если пользователь имеет
     * право видеть тикет. Отвечает inline для image/pdf, attachment
     * для всех остальных типов.
     */
    public function downloadAttachment(Request $request, int $messageId): \Symfony\Component\HttpFoundation\Response
    {
        $msg = DB::table('chat_messages')->where('id', $messageId)
            ->select('id', 'ticket_id', 'attachment_path', 'attachment_name')
            ->first();
        if (! $msg || ! $msg->attachment_path) {
            abort(404, 'Вложение не найдено');
        }

        $ticket = ChatTicket::find($msg->ticket_id);
        if (! $ticket) abort(404);
        if ($request->user()->cannot('view', $ticket)) {
            abort(403, 'Доступ запрещён');
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($msg->attachment_path)) {
            abort(404, 'Файл не найден на диске');
        }

        $mime = $disk->mimeType($msg->attachment_path) ?: 'application/octet-stream';
        $name = $msg->attachment_name ?: basename($msg->attachment_path);
        // image/pdf — inline (открываются прямо в браузере), остальные — download.
        $disposition = (str_starts_with($mime, 'image/') || $mime === 'application/pdf')
            ? 'inline' : 'attachment';

        return response()->stream(function () use ($disk, $msg) {
            $stream = $disk->readStream($msg->attachment_path);
            if ($stream) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition . '; filename="' . addslashes($name) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function knowledgeSuggest(Request $request, int $ticketId): JsonResponse
    {
        if (! $request->user()->isStaff()) {
            return response()->json(['data' => []]);
        }

        $ticket = DB::table('chat_tickets')->where('id', $ticketId)->first();
        if (! $ticket) return response()->json(['data' => []]);

        // Pull keywords from subject + first message
        $firstMsg = DB::table('chat_messages')
            ->where('ticket_id', $ticketId)
            ->where('is_system', false)
            ->orderBy('created_at')
            ->value('content');

        $text = trim(($ticket->subject ?? '') . ' ' . mb_substr($firstMsg ?? '', 0, 500));
        // Strip short/noisy words, keep tokens of length >= 4
        $tokens = collect(preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($w) => mb_strtolower($w))
            ->filter(fn ($w) => mb_strlen($w) >= 4)
            ->unique()
            ->take(8);

        if ($tokens->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $articles = DB::table('chat_knowledge_articles')
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $t) {
                    $q->orWhere('title', 'ilike', '%' . $t . '%')
                      ->orWhere('content', 'ilike', '%' . $t . '%');
                }
            })
            ->limit(30)
            ->get();

        // Rank by token hits in title (×3) + content
        $ranked = $articles->map(function ($a) use ($tokens) {
            $score = 0;
            $title = mb_strtolower($a->title ?? '');
            $content = mb_strtolower($a->content ?? '');
            foreach ($tokens as $t) {
                if (mb_strpos($title, $t) !== false) $score += 3;
                if (mb_strpos($content, $t) !== false) $score += 1;
            }
            $a->score = $score;
            return $a;
        })
        ->sortByDesc('score')
        ->take(5)
        ->values();

        return response()->json(['data' => $ranked]);
    }

    /**
     * Create a KB article from a resolved ticket. Uses subject as title
     * and concatenates non-system messages as content. Staff-only.
     */
    public function saveTicketAsArticle(Request $request, int $ticketId): JsonResponse
    {
        if (! $request->user()->isStaff()) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }

        $ticket = DB::table('chat_tickets')->where('id', $ticketId)->first();
        if (! $ticket) return response()->json(['message' => 'Не найден'], 404);

        $request->validate([
            'title' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:64',
            'content' => 'nullable|string',
        ]);

        $title = $request->input('title') ?: ($ticket->subject ?? 'Решение тикета #' . $ticketId);
        $category = $request->input('category') ?: ($ticket->department ?? 'general');

        // Default content: subject + joined non-system messages
        $content = $request->input('content');
        if (! $content) {
            $msgs = DB::table('chat_messages')
                ->where('ticket_id', $ticketId)
                ->where('is_system', false)
                ->orderBy('created_at')
                ->get();
            $content = "## Вопрос\n\n";
            $first = $msgs->first();
            $content .= $first ? $first->content : ($ticket->subject ?? '');
            $content .= "\n\n## Решение\n\n";
            foreach ($msgs->skip(1) as $m) {
                $author = (bool) $m->is_agent ? 'Сотрудник' : 'Клиент';
                $content .= "**{$author}** ({$m->sender_name}):\n{$m->content}\n\n";
            }
        }

        $articleId = DB::table('chat_knowledge_articles')->insertGetId([
            'title' => $title,
            'content' => $content,
            'category' => $category,
            'tags' => $ticket->tags ?: null,
            'views' => 0,
            'helpful' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $articleId, 'title' => $title], 201);
    }

    // ==================== STATS ====================

    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => DB::table('chat_tickets')->count(),
            'new' => DB::table('chat_tickets')->where('status', 'new')->count(),
            'open' => DB::table('chat_tickets')->where('status', 'open')->count(),
            'pending' => DB::table('chat_tickets')->where('status', 'pending')->count(),
            'resolved' => DB::table('chat_tickets')->where('status', 'resolved')->count(),
            'closed' => DB::table('chat_tickets')->where('status', 'closed')->count(),
            'critical' => DB::table('chat_tickets')->where('priority', 'critical')->whereIn('status', ['new', 'open'])->count(),
        ]);
    }

    /**
     * Aggregate analytics for the head/calculations roles.
     * Period = 'day' | 'week' | 'month' | 'custom' (with from/to).
     * Returns totals, response-time averages, category/priority breakdowns,
     * staff load, and a daily trend series.
     */
    public function analytics(Request $request): JsonResponse
    {
        if (! $request->user()->isStaff()) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }

        $period = $request->input('period', 'week');
        [$from, $to] = $this->resolvePeriod($request, $period);

        $ticketsInPeriod = DB::table('chat_tickets')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);

        // Counters by status
        $counters = [
            'total' => (clone $ticketsInPeriod)->count(),
            'new' => (clone $ticketsInPeriod)->where('status', 'new')->count(),
            'open' => (clone $ticketsInPeriod)->where('status', 'open')->count(),
            'pending' => (clone $ticketsInPeriod)->where('status', 'pending')->count(),
            'resolved' => (clone $ticketsInPeriod)->where('status', 'resolved')->count(),
            'closed' => (clone $ticketsInPeriod)->where('status', 'closed')->count(),
        ];

        // Average response time (minutes) — per-ticket first partner msg → first staff msg
        $ticketIds = (clone $ticketsInPeriod)->pluck('id');
        $responseTimes = [];
        $resolutionTimes = [];
        $slaBreachedCount = 0;
        if ($ticketIds->isNotEmpty()) {
            $msgs = DB::table('chat_messages')
                ->whereIn('ticket_id', $ticketIds)
                ->where('is_system', false)
                ->orderBy('ticket_id')
                ->orderBy('created_at')
                ->get(['ticket_id', 'is_agent', 'created_at']);

            $firstPartnerByTicket = [];
            $firstStaffByTicket = [];
            foreach ($msgs as $m) {
                $tid = $m->ticket_id;
                if ((bool) $m->is_agent) {
                    if (! isset($firstStaffByTicket[$tid])) $firstStaffByTicket[$tid] = $m->created_at;
                } else {
                    if (! isset($firstPartnerByTicket[$tid])) $firstPartnerByTicket[$tid] = $m->created_at;
                }
            }

            foreach ($firstPartnerByTicket as $tid => $partnerAt) {
                if (! isset($firstStaffByTicket[$tid])) {
                    // No reply yet — count as breach if waiting time > 30 min
                    $mins = (strtotime(now()) - strtotime($partnerAt)) / 60;
                    if ($mins > 30) $slaBreachedCount++;
                    continue;
                }
                $mins = (strtotime($firstStaffByTicket[$tid]) - strtotime($partnerAt)) / 60;
                if ($mins < 0) continue;
                $responseTimes[] = $mins;
                if ($mins > 30) $slaBreachedCount++;
            }

            // Resolution time: created_at → closed_at for closed tickets
            $resolved = (clone $ticketsInPeriod)
                ->whereNotNull('closed_at')
                ->get(['created_at', 'closed_at']);
            foreach ($resolved as $t) {
                $mins = (strtotime($t->closed_at) - strtotime($t->created_at)) / 60;
                if ($mins > 0) $resolutionTimes[] = $mins;
            }
        }

        $avg = fn ($arr) => count($arr) > 0 ? round(array_sum($arr) / count($arr), 1) : 0;

        // Breakdown by category (department)
        $byCategory = (clone $ticketsInPeriod)
            ->select('department', DB::raw('count(*) as cnt'))
            ->groupBy('department')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($r) => ['category' => $r->department ?: 'general', 'count' => (int) $r->cnt]);

        // Breakdown by priority
        $byPriority = (clone $ticketsInPeriod)
            ->select('priority', DB::raw('count(*) as cnt'))
            ->groupBy('priority')
            ->get()
            ->map(fn ($r) => ['priority' => $r->priority ?: 'medium', 'count' => (int) $r->cnt]);

        // Staff load: resolved tickets per assignee with avg response time
        $staffLoad = DB::table('chat_tickets')
            ->whereNotNull('assigned_to')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->select('assigned_to', 'assigned_name',
                DB::raw('count(*) as total'),
                DB::raw("count(*) filter (where status = 'resolved' or status = 'closed') as resolved"))
            ->groupBy('assigned_to', 'assigned_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'userId' => $r->assigned_to,
                'name' => $r->assigned_name ?: 'Неизвестно',
                'total' => (int) $r->total,
                'resolved' => (int) $r->resolved,
            ]);

        // Daily trend: new vs resolved (or closed) per day
        $dailyRaw = DB::table('chat_tickets')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, count(*) as cnt")
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('cnt', 'day')
            ->toArray();

        $dailyResolvedRaw = DB::table('chat_tickets')
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $from)
            ->where('closed_at', '<=', $to)
            ->selectRaw("to_char(closed_at, 'YYYY-MM-DD') as day, count(*) as cnt")
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('cnt', 'day')
            ->toArray();

        $days = [];
        $cursor = strtotime($from);
        $end = strtotime($to);
        while ($cursor <= $end) {
            $d = date('Y-m-d', $cursor);
            $days[] = [
                'day' => $d,
                'new' => (int) ($dailyRaw[$d] ?? 0),
                'resolved' => (int) ($dailyResolvedRaw[$d] ?? 0),
            ];
            $cursor += 86400;
        }

        return response()->json([
            'period' => [
                'type' => $period,
                'from' => is_string($from) ? $from : $from->toIso8601String(),
                'to' => is_string($to) ? $to : $to->toIso8601String(),
            ],
            'counters' => $counters,
            'avgResponseMinutes' => $avg($responseTimes),
            'avgResolutionMinutes' => $avg($resolutionTimes),
            'responseTimeSamples' => count($responseTimes),
            'slaBreachedCount' => $slaBreachedCount,
            'byCategory' => $byCategory,
            'byPriority' => $byPriority,
            'staffLoad' => $staffLoad,
            'dailyTrend' => $days,
        ]);
    }

    /**
     * Open tickets assigned to the current staff user — shift handover report.
     */
    public function myOpenTickets(Request $request): JsonResponse
    {
        if (! $request->user()->isStaff()) {
            return response()->json(['data' => []]);
        }

        $userId = $request->user()->id;
        $tickets = DB::table('chat_tickets')
            ->where('assigned_to', $userId)
            ->whereIn('status', ['new', 'open', 'pending'])
            ->orderByDesc('priority')
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'subject' => $t->subject,
                'customerName' => $t->customer_name,
                'status' => $t->status,
                'priority' => $t->priority,
                'lastMessageAt' => $t->last_message_at,
                'createdAt' => $t->created_at,
                'department' => $t->department,
                'tags' => $t->tags,
            ]);

        return response()->json(['data' => $tickets]);
    }

    private function resolvePeriod(Request $request, string $period): array
    {
        $now = now();
        switch ($period) {
            case 'day':
                return [$now->copy()->startOfDay()->toDateTimeString(), $now->copy()->endOfDay()->toDateTimeString()];
            case 'month':
                return [$now->copy()->subDays(29)->startOfDay()->toDateTimeString(), $now->copy()->endOfDay()->toDateTimeString()];
            case 'custom':
                $from = $request->filled('from')
                    ? \Carbon\Carbon::parse($request->input('from'))->startOfDay()->toDateTimeString()
                    : $now->copy()->subDays(6)->startOfDay()->toDateTimeString();
                $to = $request->filled('to')
                    ? \Carbon\Carbon::parse($request->input('to'))->endOfDay()->toDateTimeString()
                    : $now->copy()->endOfDay()->toDateTimeString();
                return [$from, $to];
            case 'week':
            default:
                return [$now->copy()->subDays(6)->startOfDay()->toDateTimeString(), $now->copy()->endOfDay()->toDateTimeString()];
        }
    }

    /**
     * Unread messages count + tickets-with-unread count for current user.
     *
     * Раньше возвращалось количество ТИКЕТОВ с непрочитанными — поэтому в
     * шапке висело «N» вместо реального числа новых сообщений и ощущение
     * было «счётчик не обновляется». Теперь считаем именно сообщения.
     *
     * Один SQL вместо N+1 (раньше .exists() в цикле по каждому тикету).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $isStaff = $request->user()->isStaff();

        $query = DB::table('chat_tickets');
        if (!$isStaff) {
            $query->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)->orWhere('recipient_id', $userId);
            });
        }

        $ticketIds = $query->whereIn('status', ['new', 'open', 'pending'])->pluck('id');
        if ($ticketIds->isEmpty()) {
            return response()->json(['count' => 0, 'tickets' => 0]);
        }

        // Один JOIN-запрос: непрочитанные = сообщения от других и системные
        // отброшены, плюс created_at > last_read_at (либо нет read-маркера).
        $row = DB::table('chat_messages as cm')
            ->leftJoin('chat_read_status as rs', function ($j) use ($userId) {
                $j->on('rs.ticket_id', '=', 'cm.ticket_id')
                  ->where('rs.user_id', '=', $userId);
            })
            ->whereIn('cm.ticket_id', $ticketIds)
            ->where('cm.sender_id', '!=', $userId)
            ->where('cm.is_system', false)
            ->where(function ($q) {
                $q->whereNull('rs.last_read_at')
                  ->orWhereColumn('cm.created_at', '>', 'rs.last_read_at');
            })
            ->selectRaw('COUNT(*) AS msgs, COUNT(DISTINCT cm.ticket_id) AS tickets')
            ->first();

        return response()->json([
            'count'   => (int) ($row->msgs ?? 0),
            'tickets' => (int) ($row->tickets ?? 0),
        ]);
    }

    /**
     * Access probe used by the socket-server before it lets a client join
     * `ticket:{id}`. Returns 200 if the current token may see the ticket,
     * 403 otherwise. Logic is the same ChatTicketPolicy::view used by
     * show/sendMessage — keeping both paths on a single gate.
     */
    public function canAccess(Request $request, int $id): JsonResponse
    {
        $ticket = ChatTicket::find($id);
        if (! $ticket) return response()->json(['message' => 'Не найден'], 404);

        if ($request->user()->cannot('view', $ticket)) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        return response()->json(['ok' => true]);
    }

    /** Staff list for assignment */
    public function staffList(): JsonResponse
    {
        $staff = DB::table('WebUser')
            ->where(function ($q) {
                foreach (self::$staffRoles as $role) {
                    $q->orWhere('role', 'like', "%{$role}%");
                }
            })
            ->orderBy('lastName')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => trim(($u->lastName ?? '') . ' ' . ($u->firstName ?? '')),
                'email' => $u->email,
                'role' => $u->role,
            ]);

        return response()->json($staff);
    }

    // ==================== INCIDENTS / SUPPORT DESK ====================

    /**
     * POST /chat/tickets/{id}/incident — пометить тикет как инцидент.
     *
     * Доступно стафф-ролям admin/support/head — кто работает на рабочем
     * столе техподдержки. Идемпотентно: повторный POST на уже зафиксированном
     * — обновляет severity, не плодит новый incident_no.
     */
    public function markIncident(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'severity' => 'nullable|in:critical,high,medium,low',
            'note' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        if (! array_intersect($roles, ['admin', 'support', 'head'])) {
            return response()->json([
                'message' => 'Фиксация инцидентов — только техподдержка/админ',
            ], 403);
        }

        $ticket = DB::table('chat_tickets')->where('id', $id)->first();
        if (! $ticket) return response()->json(['message' => 'Не найден'], 404);

        $severity = $request->input('severity', $ticket->incident_severity ?? 'medium');
        $isNew = ! ($ticket->is_incident ?? false);

        $incidentNo = $ticket->incident_no;
        if ($isNew) {
            $incidentNo = $this->nextIncidentNumber();
        }

        DB::table('chat_tickets')->where('id', $id)->update([
            'is_incident' => true,
            'incident_no' => $incidentNo,
            'incident_severity' => $severity,
            'incident_logged_at' => $isNew ? now() : ($ticket->incident_logged_at ?? now()),
            'incident_logged_by' => $isNew ? $user->id : ($ticket->incident_logged_by ?? $user->id),
            'incident_resolved_at' => null,
            'updated_at' => now(),
        ]);

        // System-сообщение в чат, чтобы участники видели смену статуса.
        $msg = $isNew
            ? "Зафиксирован инцидент {$incidentNo} (приоритет: {$severity})"
            : "Изменён приоритет инцидента {$incidentNo}: {$severity}";
        if ($request->filled('note')) {
            $msg .= ". " . $request->input('note');
        }
        DB::table('chat_messages')->insert([
            'ticket_id' => $id,
            'sender_id' => $user->id,
            'sender_name' => trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')),
            'content' => $msg,
            'is_agent' => true,
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => $isNew ? 'Инцидент зафиксирован' : 'Приоритет обновлён',
            'incidentNo' => $incidentNo,
            'severity' => $severity,
        ]);
    }

    /** Закрыть инцидент. Сам тикет может оставаться в любом статусе. */
    public function resolveIncident(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        if (! array_intersect($roles, ['admin', 'support', 'head'])) {
            return response()->json(['message' => 'Только для техподдержки/админа'], 403);
        }

        $ticket = DB::table('chat_tickets')->where('id', $id)->first();
        if (! $ticket || ! $ticket->is_incident) {
            return response()->json(['message' => 'Инцидент не найден'], 404);
        }

        DB::table('chat_tickets')->where('id', $id)->update([
            'incident_resolved_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('chat_messages')->insert([
            'ticket_id' => $id,
            'sender_id' => $user->id,
            'sender_name' => trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')),
            'content' => "Инцидент {$ticket->incident_no} закрыт",
            'is_agent' => true,
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Инцидент закрыт']);
    }

    /**
     * GET /support/desk — данные для рабочего стола техподдержки.
     *
     * Один эндпоинт отдаёт: KPI (open/incidents/resolved-сегодня),
     * тикеты department=support, плюс активные инциденты любой категории.
     */
    public function supportDesk(Request $request): JsonResponse
    {
        $user = $request->user();
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        if (! array_intersect($roles, ['admin', 'support', 'head'])) {
            return response()->json(['message' => 'Только для техподдержки/админа'], 403);
        }

        $today = now()->startOfDay();
        // Учитываем legacy-алиас 'technical' наравне с 'support' —
        // некоторые старые тикеты ещё с этим department'ом.
        $supportDepts = ['support', 'technical'];

        $kpi = [
            'open' => (int) DB::table('chat_tickets')
                ->whereIn('department', $supportDepts)
                ->whereIn('status', ['new', 'open', 'in_progress'])->count(),
            'incidentsActive' => (int) DB::table('chat_tickets')
                ->where('is_incident', true)
                ->whereNull('incident_resolved_at')->count(),
            'resolvedToday' => (int) DB::table('chat_tickets')
                ->whereIn('department', $supportDepts)
                ->where('status', 'resolved')
                ->where('updated_at', '>=', $today)->count(),
            'closedToday' => (int) DB::table('chat_tickets')
                ->whereIn('department', $supportDepts)
                ->where('status', 'closed')
                ->where('updated_at', '>=', $today)->count(),
        ];

        $statusFilter = $request->input('status');
        $q = DB::table('chat_tickets as t')
            ->where(function ($w) use ($supportDepts) {
                $w->whereIn('t.department', $supportDepts)
                  ->orWhere('t.is_incident', true);
            })
            ->select([
                't.id', 't.subject', 't.department', 't.status', 't.priority',
                't.is_incident', 't.incident_no', 't.incident_severity',
                't.incident_logged_at', 't.incident_resolved_at',
                't.created_at', 't.updated_at', 't.last_message_at',
                't.customer_name',
            ]);
        if ($statusFilter && in_array($statusFilter, ['new', 'open', 'in_progress', 'pending', 'resolved', 'closed'], true)) {
            $q->where('t.status', $statusFilter);
        }
        if ($request->boolean('incidents_only')) {
            $q->where('t.is_incident', true)->whereNull('t.incident_resolved_at');
        }

        $rows = $q->orderByDesc('t.is_incident')
            ->orderByDesc('t.last_message_at')
            ->orderByDesc('t.updated_at')
            ->limit(200)
            ->get();

        $tickets = $rows->map(fn ($t) => [
            'id' => $t->id,
            'subject' => $t->subject,
            'department' => $t->department,
            'departmentLabel' => TicketService::categoryLabel($t->department),
            'status' => $t->status,
            'priority' => $t->priority,
            'isIncident' => (bool) $t->is_incident,
            'incidentNo' => $t->incident_no,
            'incidentSeverity' => $t->incident_severity,
            'incidentLoggedAt' => $t->incident_logged_at,
            'incidentResolvedAt' => $t->incident_resolved_at,
            'customerName' => $t->customer_name,
            'createdAt' => $t->created_at,
            'updatedAt' => $t->updated_at,
            'lastMessageAt' => $t->last_message_at,
        ]);

        return response()->json([
            'kpi' => $kpi,
            'tickets' => $tickets,
        ]);
    }

    /**
     * INC-YYYYMM-NNNN. NNNN — порядковый номер за месяц по уже выданным
     * incident_no. Без отдельной sequence — sequence не работает
     * корректно после dump/restore legacy-БД.
     */
    private function nextIncidentNumber(): string
    {
        $prefix = 'INC-' . now()->format('Ym') . '-';
        $maxRow = DB::table('chat_tickets')
            ->where('incident_no', 'like', $prefix . '%')
            ->selectRaw("MAX(CAST(SUBSTRING(incident_no FROM ?) AS INTEGER)) AS n", [strlen($prefix) + 1])
            ->first();
        $n = ((int) ($maxRow->n ?? 0)) + 1;
        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
