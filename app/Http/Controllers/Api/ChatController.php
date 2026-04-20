<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Controller;
use App\Models\ChatTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    private static array $staffRoles = ['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections'];

    private function isStaff(Request $request): bool
    {
        $roles = array_map('trim', explode(',', $request->user()->role ?? ''));
        return (bool) array_intersect($roles, self::$staffRoles);
    }

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
        $isStaff = $this->isStaff($request);

        $query = DB::table('chat_tickets');

        if (!$isStaff) {
            // Partner sees chats they created OR chats addressed to them
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere('recipient_id', $user->id);
            });
        }

        // Filters
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('priority')) $query->where('priority', $request->priority);
        if ($request->filled('department')) $query->where('department', $request->department);
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
        }

        $data = $tickets->map(function ($t) use ($unreadMap) {
            $t->unread = $unreadMap[$t->id] ?? 0;
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
        $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'department' => 'required|in:technical,billing,sales,general',
            'priority' => 'nullable|in:critical,high,medium,low',
            'message' => 'required|string',
        ]);

        $user = $request->user();
        $name = $this->userName($request);
        $now = now();

        // Resolve recipient name
        $recipientId = $request->input('recipient_id');
        $recipientName = null;
        if ($recipientId) {
            $recipient = DB::table('WebUser')->where('id', $recipientId)->first();
            $recipientName = $recipient ? trim(($recipient->lastName ?? '') . ' ' . ($recipient->firstName ?? '')) : null;
            // If no recipient found, check consultant table
            if (!$recipientName) {
                $recipientName = DB::table('consultant')->where('id', $recipientId)->value('personName');
            }
        }

        // Transaction: the ticket row is meaningless without its first message,
        // so either both land or neither does.
        $ticketId = DB::transaction(function () use ($request, $user, $name, $recipientId, $recipientName, $now) {
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
                'content' => $request->message,
                'is_agent' => $this->isStaff($request),
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
                'attachmentPath' => $m->attachment_path ? '/storage/' . $m->attachment_path : null,
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
        if ($this->isStaff($request)) {
            $partnerContext = $this->buildPartnerContext($ticket);
        }

        return response()->json([
            'ticket' => $ticket,
            'messages' => $messages,
            'otherLastReadAt' => $otherLastReadAt,
            'partnerContext' => $partnerContext,
        ]);
    }

    /**
     * Build a compact partner snapshot for the staff sidebar:
     * WebUser identity, Consultant activity/qualification, recent contracts.
     */
    private function buildPartnerContext($ticket): ?array
    {
        $webUserId = $ticket->created_by ?? null;
        if (! $webUserId) return null;

        $webUser = DB::table('WebUser')->where('id', $webUserId)->first();
        if (! $webUser) return null;

        $consultant = DB::table('consultant')->where('webUser', $webUserId)->first();

        $activityName = null;
        if ($consultant && $consultant->activity) {
            $activityName = DB::table('directory_of_activities')
                ->where('id', (int) (is_object($consultant->activity) ? $consultant->activity->value : $consultant->activity))
                ->value('name');
        }

        $qualificationName = null;
        if ($consultant && ! empty($consultant->status_and_lvl)) {
            $qualificationName = DB::table('status_levels')
                ->where('id', $consultant->status_and_lvl)
                ->value('title');
        }

        $recentContracts = [];
        if ($consultant) {
            $recentContracts = DB::table('contract')
                ->where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'number' => $c->number ?? "#{$c->id}",
                    'clientName' => $c->clientName ?? '—',
                    'productName' => $c->productName ?? '—',
                    'amount' => $c->amount ?? null,
                    'openDate' => $c->openDate ?? null,
                ])
                ->toArray();

            $clientsCount = DB::table('client')
                ->where('consultant', $consultant->id)
                ->where('active', true)
                ->count();
            $contractsCount = DB::table('contract')
                ->where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->count();
        }

        return [
            'user' => [
                'id' => $webUser->id,
                'email' => $webUser->email,
                'firstName' => $webUser->firstName,
                'lastName' => $webUser->lastName,
                'patronymic' => $webUser->patronymic,
                'phone' => $webUser->phone,
                'avatarUrl' => $webUser->avatar ? '/storage/' . $webUser->avatar : null,
                'role' => $webUser->role,
            ],
            'consultant' => $consultant ? [
                'id' => $consultant->id,
                'participantCode' => $consultant->participantCode,
                'activityId' => is_object($consultant->activity) ? $consultant->activity->value : $consultant->activity,
                'activityName' => $activityName,
                'qualificationName' => $qualificationName,
                'personalVolume' => round((float) ($consultant->personalVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($consultant->groupVolumeCumulative ?? 0), 2),
                'dateActivity' => $consultant->dateActivity ?? null,
                'yearPeriodEnd' => $consultant->yearPeriodEnd ?? null,
                'activationDeadline' => $consultant->activationDeadline ?? null,
                'terminationCount' => $consultant->terminationCount ?? 0,
                'clientsCount' => $clientsCount ?? 0,
                'contractsCount' => $contractsCount ?? 0,
                'inviterName' => $consultant->inviterName ?? null,
            ] : null,
            'recentContracts' => $recentContracts,
        ];
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
            'message' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,gif,zip|max:10240',
            'reply_to_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $name = $this->userName($request);
        $isAgent = $this->isStaff($request);
        $now = now();

        $attachmentPath = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentPath = $file->store("chat/{$id}", 'public');
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

        $msgId = DB::table('chat_messages')->insertGetId([
            'ticket_id' => $id,
            'sender_id' => $user->id,
            'sender_name' => $name,
            'content' => $request->message ?? '',
            'is_agent' => $isAgent,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'reply_to_id' => $replyToId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Update ticket
        $update = ['messages_count' => DB::raw('messages_count + 1'), 'last_message_at' => $now, 'updated_at' => $now];
        if ($ticket->status === 'new' && $isAgent) {
            $update['status'] = 'open';
            $update['assigned_to'] = $ticket->assigned_to ?? $user->id;
            $update['assigned_name'] = $ticket->assigned_name ?? $name;
        }
        DB::table('chat_tickets')->where('id', $id)->update($update);

        // Socket emit
        try {
            app(\App\Services\SocketService::class)->emit('chat:new-message', "ticket:{$id}", [
                'id' => $msgId,
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

        // Personal notification to the other side of the ticket
        $recipientId = $isAgent
            ? (int) $ticket->user_id
            : ((int) ($ticket->assigned_to ?? 0));
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
        if (!$this->isStaff($request)) {
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

    /** Assign ticket to staff */
    public function assign(Request $request, int $id): JsonResponse
    {
        if (!$this->isStaff($request)) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }

        $request->validate(['user_id' => 'required|integer']);

        $assignee = DB::table('WebUser')->where('id', $request->user_id)->first();
        $assigneeName = $assignee ? trim(($assignee->lastName ?? '') . ' ' . ($assignee->firstName ?? '')) : '—';

        DB::table('chat_tickets')->where('id', $id)->update([
            'assigned_to' => $request->user_id,
            'assigned_name' => $assigneeName,
            'status' => 'open',
            'updated_at' => now(),
        ]);

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
        if (! $this->isStaff($request)) {
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
        if (!$this->isStaff($request)) {
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
    public function knowledgeSuggest(Request $request, int $ticketId): JsonResponse
    {
        if (! $this->isStaff($request)) {
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
        if (! $this->isStaff($request)) {
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
        if (! $this->isStaff($request)) {
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
        if (! $this->isStaff($request)) {
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

    /** Unread messages count for current user */
    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $isStaff = $this->isStaff($request);

        $query = DB::table('chat_tickets');
        if (!$isStaff) {
            $query->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)->orWhere('recipient_id', $userId);
            });
        }

        $ticketIds = $query->whereIn('status', ['new', 'open', 'pending'])->pluck('id');
        if ($ticketIds->isEmpty()) {
            return response()->json(['count' => 0]);
        }

        $readMap = DB::table('chat_read_status')
            ->where('user_id', $userId)
            ->whereIn('ticket_id', $ticketIds)
            ->pluck('last_read_at', 'ticket_id');

        $count = 0;
        foreach ($ticketIds as $tid) {
            $lastRead = $readMap[$tid] ?? null;
            $q = DB::table('chat_messages')
                ->where('ticket_id', $tid)
                ->where('sender_id', '!=', $userId)
                ->where('is_system', false);
            if ($lastRead) {
                $q->where('created_at', '>', $lastRead);
            }
            if ($q->exists()) $count++;
        }

        return response()->json(['count' => $count]);
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
}
