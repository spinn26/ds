<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $tickets = $query->orderByDesc('last_message_at')
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

        $ticketId = DB::table('chat_tickets')->insertGetId([
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
            'ticket_id' => $ticketId,
            'sender_id' => $user->id,
            'sender_name' => $name,
            'content' => $request->message,
            'is_agent' => $this->isStaff($request),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Notify via socket
        try {
            app(\App\Services\SocketService::class)->emit('chat:new-ticket', null, [
                'ticketId' => $ticketId,
                'subject' => $request->subject,
                'department' => $request->department,
                'customerName' => $name,
            ]);
        } catch (\Exception $e) {}

        $ticket = DB::table('chat_tickets')->where('id', $ticketId)->first();

        return response()->json(['ticket' => $ticket], 201);
    }

    /** Get ticket with messages */
    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('chat_tickets')->where('id', $id)->first();
        if (!$ticket) return response()->json(['message' => 'Не найден'], 404);

        // Auth check — creator, recipient, or staff
        $userId = $request->user()->id;
        if (!$this->isStaff($request)
            && (int) $ticket->created_by !== $userId
            && (int) ($ticket->recipient_id ?? 0) !== $userId) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        $rawMessages = DB::table('chat_messages')
            ->where('ticket_id', $id)
            ->orderBy('created_at')
            ->get();

        // Batch-load reply targets to avoid N+1.
        $replyIds = $rawMessages->pluck('reply_to_id')->filter()->unique();
        $replies = $replyIds->isNotEmpty()
            ? DB::table('chat_messages')->whereIn('id', $replyIds)->get()->keyBy('id')
            : collect();

        $messages = $rawMessages->map(function ($m) use ($replies) {
            $replyTo = null;
            if ($m->reply_to_id && isset($replies[$m->reply_to_id])) {
                $r = $replies[$m->reply_to_id];
                $replyTo = [
                    'id' => $r->id,
                    'senderName' => $r->sender_name,
                    'content' => mb_substr((string) $r->content, 0, 140),
                ];
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

        return response()->json([
            'ticket' => $ticket,
            'messages' => $messages,
            'otherLastReadAt' => $otherLastReadAt,
        ]);
    }

    /** Send message to ticket */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('chat_tickets')->where('id', $id)->first();
        if (!$ticket) return response()->json(['message' => 'Не найден'], 404);

        $userId = $request->user()->id;
        if (!$this->isStaff($request)
            && (int) $ticket->created_by !== $userId
            && (int) ($ticket->recipient_id ?? 0) !== $userId) {
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
        } catch (\Exception $e) {}

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
        } catch (\Exception $e) {}

        return response()->json(['id' => $messageId, 'editedAt' => now()->toIso8601String()]);
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

        return response()->json($query->orderByDesc('views')->get());
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
