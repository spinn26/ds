<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\SocketService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    const OWNER_EMAIL = 'lamakin@dsconsult.ru'; // Александр Ламакин

    private static array $staffRoles = ['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections'];

    /**
     * Check if current user can access a ticket.
     *
     * Раньше любая стафф-роль видела ВСЕ тикеты. По требованию: партнёр
     * выбирает категорию → тикет видит только стафф соответствующей
     * роли (см. TicketService::CATEGORIES). Теперь:
     *   - admin — всегда (все категории);
     *   - стафф — если роль матчит category тикета;
     *   - создатель — всегда;
     *   - явный участник (ticket_participants) — всегда (ручное
     *     добавление другого сотрудника по эскалации).
     */
    private function canAccessTicket(int $ticketId, int $userId): bool
    {
        $ticket = DB::table('tickets')->where('id', $ticketId)->first();
        if (! $ticket) return false;

        // Создатель — всегда видит
        if ((int) $ticket->created_by === $userId) return true;

        // Явно добавленный участник — видит независимо от роли
        $isParticipant = DB::table('ticket_participants')
            ->where('ticket_id', $ticketId)
            ->where('user_id', $userId)
            ->exists();
        if ($isParticipant) return true;

        // Стафф: роль должна матчить категорию тикета
        $user = DB::table('WebUser')->where('id', $userId)->first();
        if ($user) {
            $roles = array_map('trim', explode(',', $user->role ?? ''));
            if (TicketService::staffCanSeeCategory($roles, $ticket->category)) {
                return true;
            }
        }

        return false;
    }

    private function isStaffUser(int $userId): bool
    {
        $user = DB::table('WebUser')->where('id', $userId)->first();
        if (!$user) return false;
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        return (bool) array_intersect($roles, self::$staffRoles);
    }

    /** Список тикетов (для партнёра — свои, для сотрудника — назначенные/доступные по роли) */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        $isStaff = array_intersect($roles, self::$staffRoles);

        $query = DB::table('tickets');

        if ($isStaff) {
            // Сотрудник видит:
            //   • тикеты тех категорий, что матчат его роль (TicketService),
            //   • плюс тикеты где он явно создатель/назначен/участник
            //     (если оператора эскалировали из чужой роли).
            $allowedCategories = TicketService::visibleCategoriesForRoles($roles);
            $query->where(function ($q) use ($user, $allowedCategories) {
                if (! empty($allowedCategories)) {
                    $q->whereIn('category', $allowedCategories);
                }
                $q->orWhere('created_by', $user->id)
                  ->orWhere('assigned_to', $user->id)
                  ->orWhereExists(function ($sub) use ($user) {
                      $sub->select(DB::raw(1))
                          ->from('ticket_participants')
                          ->whereColumn('ticket_participants.ticket_id', 'tickets.id')
                          ->where('ticket_participants.user_id', $user->id);
                  });
            });

            if ($request->filled('assigned_to_me')) {
                $query->where('assigned_to', $user->id);
            }
            if ($request->filled('category')) {
                $query->where('category', TicketService::normalizeCategory($request->category));
            }
        } else {
            // Партнёр — только свои
            $query->where('created_by', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where('subject', 'ilike', '%' . $request->search . '%');
        }

        $total = $query->count();
        $rows = $query->orderByDesc('updated_at')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        $ticketIds = $rows->pluck('id')->filter()->unique();

        // Batch load all WebUsers (creators + assignees)
        $userIds = $rows->pluck('created_by')->merge($rows->pluck('assigned_to'))->filter()->unique();
        $webUsers = $userIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $userIds)->get()->keyBy('id')
            : collect();

        // Batch load last messages per ticket
        $lastMessages = collect();
        if ($ticketIds->isNotEmpty()) {
            $latestMsgIds = DB::table('ticket_messages')
                ->whereIn('ticket_id', $ticketIds)
                ->selectRaw('MAX(id) as id')
                ->groupBy('ticket_id')
                ->pluck('id');
            if ($latestMsgIds->isNotEmpty()) {
                $lastMessages = DB::table('ticket_messages')
                    ->whereIn('id', $latestMsgIds)
                    ->get()
                    ->keyBy('ticket_id');
            }
        }

        // Batch load unread counts per ticket
        $currentUserId = auth()->id();
        $unreadCounts = collect();
        if ($ticketIds->isNotEmpty()) {
            $unreadCounts = DB::table('ticket_messages')
                ->whereIn('ticket_id', $ticketIds)
                ->where('user_id', '!=', $currentUserId)
                ->whereRaw('"created_at" > (SELECT COALESCE("updated_at", "created_at") FROM tickets WHERE tickets.id = ticket_messages.ticket_id)')
                ->select('ticket_id', DB::raw('count(*) as cnt'))
                ->groupBy('ticket_id')
                ->pluck('cnt', 'ticket_id');
        }

        $tickets = $rows->map(function ($t) use ($webUsers, $lastMessages, $unreadCounts, $currentUserId) {
                $creator = $webUsers[$t->created_by] ?? null;
                $assignee = $t->assigned_to ? ($webUsers[$t->assigned_to] ?? null) : null;
                $lastMsg = $lastMessages[$t->id] ?? null;

                return [
                    'id' => $t->id,
                    'subject' => $t->subject,
                    'category' => $t->category,
                    'categoryLabel' => TicketService::categoryLabel($t->category),
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'createdBy' => $creator ? trim(($creator->lastName ?? '') . ' ' . ($creator->firstName ?? '')) : '—',
                    'created_by' => $t->created_by,
                    'assigned_to' => $t->assigned_to,
                    'assignedTo' => $assignee ? trim(($assignee->lastName ?? '') . ' ' . ($assignee->firstName ?? '')) : null,
                    'contextType' => $t->context_type,
                    'lastMessage' => $lastMsg ? mb_substr($lastMsg->message ?? '', 0, 80) : null,
                    'lastMessageAt' => $lastMsg?->created_at ?? null,
                    'lastMessageFromMe' => $lastMsg ? ((int) $lastMsg->user_id === (int) $currentUserId) : false,
                    'last_message_at' => $lastMsg?->created_at ?? null,
                    'unreadCount' => $unreadCounts[$t->id] ?? 0,
                    'unread' => $unreadCounts[$t->id] ?? 0,
                    'createdAt' => $t->created_at,
                    'updatedAt' => $t->updated_at,
                ];
            });

        return response()->json(['data' => $tickets, 'total' => $total]);
    }

    /** Создать тикет (партнёр) */
    public function store(Request $request): JsonResponse
    {
        $allowedKeys = array_merge(
            array_keys(TicketService::CATEGORIES),
            array_keys(TicketService::CATEGORY_ALIASES),
        );
        $request->validate([
            'subject' => 'required|string|max:255',
            'category' => 'required|in:' . implode(',', $allowedKeys),
            'message' => 'required|string',
        ]);

        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        // Нормализуем legacy-ключи (billing/accounting → accruals).
        $category = TicketService::normalizeCategory($request->category);

        // Auto-assign owner tickets to Александр Ламакин
        $assignedTo = null;
        if ($category === 'owner') {
            $owner = DB::table('WebUser')->where('email', self::OWNER_EMAIL)->first();
            $assignedTo = $owner?->id;
        }

        // Транзакция: insert tickets + первое сообщение + участники должны
        // либо все пройти, либо все откатиться. Иначе при сбое на одном из
        // INSERT'ов остаётся «полусозданный» тикет без первого сообщения.
        $ticketId = DB::transaction(function () use ($request, $user, $consultant, $assignedTo, $category) {
            $tid = DB::table('tickets')->insertGetId([
                'subject' => $request->subject,
                'category' => $category,
                'created_by' => $user->id,
                'consultant_id' => $consultant?->id,
                'status' => 'open',
                'priority' => $request->input('priority', 'normal'),
                'assigned_to' => $assignedTo,
                'context_type' => $request->context_type,
                'context_id' => $request->context_id,
                'context_info' => $request->context_info ? json_encode($request->context_info) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('ticket_messages')->insert([
                'ticket_id' => $tid,
                'user_id' => $user->id,
                'message' => $request->message,
                'created_at' => now(),
            ]);
            DB::table('ticket_participants')->insert([
                'ticket_id' => $tid,
                'user_id' => $user->id,
                'role' => 'creator',
                'created_at' => now(),
            ]);
            return $tid;
        });

        // Участник-owner (если назначен)
        if ($assignedTo) {
            DB::table('ticket_participants')->insert([
                'ticket_id' => $ticketId,
                'user_id' => $assignedTo,
                'role' => 'assigned',
                'created_at' => now(),
            ]);

            NotificationController::create(
                $assignedTo,
                'ticket',
                'Новое сообщение от партнёра',
                $request->subject,
                "/manage/tickets?id={$ticketId}"
            );
        }

        // Return full ticket data for frontend
        $ticket = DB::table('tickets')->where('id', $ticketId)->first();

        return response()->json([
            'message' => 'Тикет создан',
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'category' => $ticket->category,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->updated_at,
                'unread_count' => 0,
            ],
        ], 201);
    }

    /** Получить тикет с сообщениями */
    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('tickets')->where('id', $id)->first();
        if (! $ticket) return response()->json(['message' => 'Не найден'], 404);

        if (! $this->canAccessTicket($id, $request->user()->id)) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $messageRows = DB::table('ticket_messages')
            ->where('ticket_id', $id)
            ->orderBy('created_at')
            ->get();

        $participantRows = DB::table('ticket_participants')
            ->where('ticket_id', $id)
            ->get();

        // Batch load all WebUsers needed (message authors + participants + creator)
        $allUserIds = $messageRows->pluck('user_id')
            ->merge($participantRows->pluck('user_id'))
            ->push($ticket->created_by)
            ->filter()->unique();
        $webUsers = $allUserIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $allUserIds)->get()->keyBy('id')
            : collect();

        $messages = $messageRows->map(function ($m) use ($webUsers) {
                $user = $webUsers[$m->user_id] ?? null;
                return [
                    'id' => $m->id,
                    'userId' => $m->user_id,
                    'userName' => $user ? trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')) : '—',
                    'message' => $m->message,
                    'attachmentPath' => $m->attachment_path,
                    'attachmentName' => $m->attachment_name,
                    'isSystem' => (bool) $m->is_system,
                    'createdAt' => $m->created_at,
                ];
            });

        $participants = $participantRows->map(function ($p) use ($webUsers) {
                $user = $webUsers[$p->user_id] ?? null;
                return [
                    'userId' => $p->user_id,
                    'userName' => $user ? trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')) : '—',
                    'role' => $p->role,
                ];
            });

        $creator = $webUsers[$ticket->created_by] ?? null;

        return response()->json([
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'category' => $ticket->category,
                'categoryLabel' => TicketService::categoryLabel($ticket->category),
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'createdBy' => $creator ? trim(($creator->lastName ?? '') . ' ' . ($creator->firstName ?? '')) : '—',
                'createdById' => $ticket->created_by,
                'contextType' => $ticket->context_type,
                'contextInfo' => $ticket->context_info ? json_decode($ticket->context_info) : null,
                'createdAt' => $ticket->created_at,
            ],
            'messages' => $messages,
            'participants' => $participants,
        ]);
    }

    /** Отправить сообщение в тикет */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        if (! $this->canAccessTicket($id, $request->user()->id)) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $request->validate([
            'message' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,gif,zip|max:10240',
        ]);

        $user = $request->user();
        $attachmentPath = null;
        $attachmentName = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentPath = $file->store("tickets/{$id}", 'public');
            $attachmentName = $file->getClientOriginalName();
        }

        if (! $request->message && ! $attachmentPath) {
            return response()->json(['message' => 'Сообщение или файл обязательны'], 422);
        }

        // Транзакция: insert + update tickets вместе.
        $ticket = null;
        $msgId = DB::transaction(function () use ($id, $user, $request, $attachmentPath, $attachmentName, &$ticket) {
            $tid = DB::table('ticket_messages')->insertGetId([
                'ticket_id' => $id,
                'user_id' => $user->id,
                'message' => $request->message,
                'attachment_path' => $attachmentPath,
                'attachment_name' => $attachmentName,
                'created_at' => now(),
            ]);
            $ticket = DB::table('tickets')->where('id', $id)->first();
            if ($ticket && $ticket->status === 'open' && $user->id !== $ticket->created_by) {
                DB::table('tickets')->where('id', $id)->update([
                    'status' => 'in_progress',
                    'assigned_to' => $ticket->assigned_to ?? $user->id,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('tickets')->where('id', $id)->update(['updated_at' => now()]);
            }
            return $tid;
        });

        // Emit to Socket.IO for real-time delivery
        try {
            $socketService = app(\App\Services\SocketService::class);
            $socketService->emitToTicket($id, 'ticket:new-message', [
                'id' => $msgId,
                'ticketId' => $id,
                'userId' => $user->id,
                'userName' => trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')),
                'message' => $request->message,
                'attachmentPath' => $attachmentPath ? '/storage/' . $attachmentPath : null,
                'attachmentName' => $attachmentName,
                'isSystem' => false,
                'createdAt' => now()->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::warning('ticket socket emit failed: new-message', ['ticket_id' => $id, 'message_id' => $msgId, 'exception' => $e->getMessage()]);
        }

        // Notify ticket creator if staff replies
        if ($ticket && $user->id !== $ticket->created_by) {
            NotificationController::create(
                $ticket->created_by,
                'ticket',
                'Новый ответ в тикете',
                mb_substr($request->message ?? '', 0, 80),
                '/tickets'
            );
        }

        return response()->json(['message' => 'Отправлено', 'id' => $msgId]);
    }

    /** Назначить тикет на сотрудника */
    public function assign(Request $request, int $id): JsonResponse
    {
        if (! $this->isStaffUser($request->user()->id)) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }

        // exists защищает от подделанного user_id — иначе тикет назначался
        // на несуществующего юзера и потом валился весь UI «assigned_name».
        $request->validate(['user_id' => 'required|integer|exists:WebUser,id']);

        $assignee = DB::table('WebUser')->where('id', $request->user_id)->first();

        DB::table('tickets')->where('id', $id)->update([
            'assigned_to' => $request->user_id,
            'status' => 'in_progress',
            'updated_at' => now(),
        ]);

        // Системное сообщение
        DB::table('ticket_messages')->insert([
            'ticket_id' => $id,
            'user_id' => $request->user()->id,
            'message' => 'Тикет перенаправлен → ' . trim(($assignee->lastName ?? '') . ' ' . ($assignee->firstName ?? '')),
            'is_system' => true,
            'created_at' => now(),
        ]);

        // Добавить участника
        $exists = DB::table('ticket_participants')
            ->where('ticket_id', $id)->where('user_id', $request->user_id)->exists();
        if (! $exists) {
            DB::table('ticket_participants')->insert([
                'ticket_id' => $id,
                'user_id' => $request->user_id,
                'role' => 'assigned',
                'created_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Тикет назначен']);
    }

    /** Добавить участника */
    public function addParticipant(Request $request, int $id): JsonResponse
    {
        if (! $this->isStaffUser($request->user()->id)) {
            return response()->json(['message' => 'Только для сотрудников'], 403);
        }

        $request->validate(['user_id' => 'required|integer']);

        $user = DB::table('WebUser')->where('id', $request->user_id)->first();

        $exists = DB::table('ticket_participants')
            ->where('ticket_id', $id)->where('user_id', $request->user_id)->exists();

        if (! $exists) {
            DB::table('ticket_participants')->insert([
                'ticket_id' => $id,
                'user_id' => $request->user_id,
                'role' => 'participant',
                'created_at' => now(),
            ]);

            DB::table('ticket_messages')->insert([
                'ticket_id' => $id,
                'user_id' => $request->user()->id,
                'message' => 'Добавлен участник: ' . trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')),
                'is_system' => true,
                'created_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Участник добавлен']);
    }

    /** Закрыть тикет */
    public function close(Request $request, int $id): JsonResponse
    {
        if (! $this->canAccessTicket($id, $request->user()->id)) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        DB::table('tickets')->where('id', $id)->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
            'updated_at' => now(),
        ]);

        $user = $request->user();
        DB::table('ticket_messages')->insert([
            'ticket_id' => $id,
            'user_id' => $user->id,
            'message' => 'Диалог завершён',
            'is_system' => true,
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Тикет закрыт']);
    }

    /** Статистика тикетов для рабочего стола */
    public function stats(): JsonResponse
    {
        $today = now()->startOfDay();

        $stats = [
            'openToday' => DB::table('tickets')->where('status', 'open')->where('created_at', '>=', $today)->count(),
            'totalOpen' => DB::table('tickets')->where('status', 'open')->count(),
            'inProgress' => DB::table('tickets')->where('status', 'in_progress')->count(),
            'closedToday' => DB::table('tickets')->where('status', 'closed')->where('closed_at', '>=', $today)->count(),
            'byCategory' => DB::table('tickets')
                ->whereIn('status', ['open', 'in_progress'])
                ->select('category', DB::raw('count(*) as cnt'))
                ->groupBy('category')
                ->pluck('cnt', 'category')
                ->toArray(),
        ];

        return response()->json($stats);
    }

    /** Отметить тикет прочитанным */
    public function markRead(Request $request, int $id): JsonResponse
    {
        DB::table('tickets')->where('id', $id)->update(['updated_at' => now()]);
        return response()->json(['message' => 'OK']);
    }

    /** Непрочитанные тикеты */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = DB::table('tickets')
            ->where('created_by', $user->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereExists(function ($q) use ($user) {
                $q->select(DB::raw(1))
                  ->from('ticket_messages')
                  ->whereColumn('ticket_messages.ticket_id', 'tickets.id')
                  ->where('ticket_messages.user_id', '!=', $user->id)
                  ->where('ticket_messages.is_system', false);
            })
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Категории + видимость по ролям.
     *
     * Возвращает: { key: { label, roles[] } }. Партнёрский UI берёт
     * только key+label; стафф-UI может использовать roles, чтобы
     * подсветить «свою» категорию или скрыть фильтр.
     */
    public function categories(): JsonResponse
    {
        return response()->json(TicketService::CATEGORIES);
    }

    /** Список сотрудников для назначения */
    public function staffList(): JsonResponse
    {
        $staff = DB::table('WebUser')
            ->where(function ($q) {
                $q->where('role', 'ilike', '%admin%')
                  ->orWhere('role', 'ilike', '%backoffice%')
                  ->orWhere('role', 'ilike', '%support%')
                  ->orWhere('role', 'ilike', '%finance%')
                  ->orWhere('role', 'ilike', '%head%');
            })
            ->orderBy('lastName')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => trim(($u->lastName ?? '') . ' ' . ($u->firstName ?? '')),
                'role' => $u->role,
            ]);

        return response()->json($staff);
    }
}
