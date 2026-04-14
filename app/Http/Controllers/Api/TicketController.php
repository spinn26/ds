<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    const CATEGORIES = [
        'support' => 'Техподдержка',
        'backoffice' => 'Бэк-офис',
        'legal' => 'Юрист',
        'accounting' => 'Бухгалтер',
        'accruals' => 'Начисления',
    ];

    /** Список тикетов (для партнёра — свои, для сотрудника — назначенные/все) */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        $isStaff = array_intersect($roles, ['admin', 'backoffice', 'support', 'finance', 'head', 'calculations', 'corrections']);

        $query = DB::table('tickets');

        if ($isStaff) {
            // Сотрудник — все тикеты или назначенные
            if ($request->filled('assigned_to_me')) {
                $query->where('assigned_to', $user->id);
            }
            if ($request->filled('category')) {
                $query->where('category', $request->category);
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

        $tickets = $rows->map(function ($t) use ($webUsers, $lastMessages, $unreadCounts) {
                $creator = $webUsers[$t->created_by] ?? null;
                $assignee = $t->assigned_to ? ($webUsers[$t->assigned_to] ?? null) : null;
                $lastMsg = $lastMessages[$t->id] ?? null;

                return [
                    'id' => $t->id,
                    'subject' => $t->subject,
                    'category' => $t->category,
                    'categoryLabel' => self::CATEGORIES[$t->category] ?? $t->category,
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'createdBy' => $creator ? trim(($creator->lastName ?? '') . ' ' . ($creator->firstName ?? '')) : '—',
                    'assignedTo' => $assignee ? trim(($assignee->lastName ?? '') . ' ' . ($assignee->firstName ?? '')) : null,
                    'contextType' => $t->context_type,
                    'lastMessage' => $lastMsg ? mb_substr($lastMsg->message ?? '', 0, 80) : null,
                    'lastMessageAt' => $lastMsg?->created_at ?? null,
                    'unreadCount' => $unreadCounts[$t->id] ?? 0,
                    'createdAt' => $t->created_at,
                    'updatedAt' => $t->updated_at,
                ];
            });

        return response()->json(['data' => $tickets, 'total' => $total]);
    }

    /** Создать тикет (партнёр) */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'category' => 'required|in:support,backoffice,legal,accounting,accruals',
            'message' => 'required|string',
        ]);

        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        $ticketId = DB::table('tickets')->insertGetId([
            'subject' => $request->subject,
            'category' => $request->category,
            'created_by' => $user->id,
            'consultant_id' => $consultant?->id,
            'status' => 'open',
            'priority' => $request->input('priority', 'normal'),
            'context_type' => $request->context_type,
            'context_id' => $request->context_id,
            'context_info' => $request->context_info ? json_encode($request->context_info) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Первое сообщение
        DB::table('ticket_messages')->insert([
            'ticket_id' => $ticketId,
            'user_id' => $user->id,
            'message' => $request->message,
            'created_at' => now(),
        ]);

        // Участник-создатель
        DB::table('ticket_participants')->insert([
            'ticket_id' => $ticketId,
            'user_id' => $user->id,
            'role' => 'creator',
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Тикет создан', 'id' => $ticketId], 201);
    }

    /** Получить тикет с сообщениями */
    public function show(int $id): JsonResponse
    {
        $ticket = DB::table('tickets')->where('id', $id)->first();
        if (! $ticket) return response()->json(['message' => 'Не найден'], 404);

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
                'categoryLabel' => self::CATEGORIES[$ticket->category] ?? $ticket->category,
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
        $request->validate([
            'message' => 'nullable|string',
            'attachment' => 'nullable|file|max:10240',
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

        $msgId = DB::table('ticket_messages')->insertGetId([
            'ticket_id' => $id,
            'user_id' => $user->id,
            'message' => $request->message,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'created_at' => now(),
        ]);

        // Обновить статус если сотрудник отвечает
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

            // Notify ticket creator if staff replies
            if ($ticket && $user->id !== $ticket->created_by) {
                $socketService->notifyUser($ticket->created_by, 'notification', [
                    'type' => 'ticket',
                    'title' => 'Новый ответ в тикете',
                    'message' => mb_substr($request->message ?? '', 0, 80),
                    'link' => '/tickets',
                ]);
            }
        } catch (\Exception $e) {}

        return response()->json(['message' => 'Отправлено', 'id' => $msgId]);
    }

    /** Назначить тикет на сотрудника */
    public function assign(Request $request, int $id): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer']);

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

    /** Категории */
    public function categories(): JsonResponse
    {
        return response()->json(self::CATEGORIES);
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
