<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Личные виджеты Workspace:
 *  - tasks: персональный TODO-список (user_tasks);
 *  - note: одна заметка-scratchpad (user_notes, upsert по user_id).
 *
 * Все эндпоинты возвращают/принимают данные только текущего юзера —
 * row-level security через user_id внутри запросов.
 */
class UserDashboardController extends Controller
{
    // ==================== TASKS ====================

    public function listTasks(Request $request): JsonResponse
    {
        $rows = DB::table('user_tasks')
            ->where('user_id', $request->user()->id)
            ->orderBy('is_done')           // невыполненные сверху
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();
        return response()->json($rows);
    }

    public function storeTask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'due_date' => 'nullable|date',
            'priority' => 'nullable|in:low,medium,high',
        ]);
        $id = DB::table('user_tasks')->insertGetId([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'priority' => $data['priority'] ?? null,
            'is_done' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function updateTask(Request $request, int $id): JsonResponse
    {
        $row = DB::table('user_tasks')->where('id', $id)
            ->where('user_id', $request->user()->id)->first();
        if (! $row) return response()->json(['message' => 'Задача не найдена'], 404);

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:200',
            'description' => 'sometimes|nullable|string|max:2000',
            'due_date' => 'sometimes|nullable|date',
            'priority' => 'sometimes|nullable|in:low,medium,high',
            'is_done' => 'sometimes|boolean',
        ]);
        $data['updated_at'] = now();
        DB::table('user_tasks')->where('id', $id)->update($data);
        return response()->json(['message' => 'Обновлено']);
    }

    public function destroyTask(Request $request, int $id): JsonResponse
    {
        $deleted = DB::table('user_tasks')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();
        if (! $deleted) return response()->json(['message' => 'Задача не найдена'], 404);
        return response()->json(['message' => 'Удалено']);
    }

    // ==================== NOTE ====================

    public function getNote(Request $request): JsonResponse
    {
        $row = DB::table('user_notes')->where('user_id', $request->user()->id)->first();
        return response()->json([
            'content' => $row->content ?? '',
            'updated_at' => $row->updated_at ?? null,
        ]);
    }

    public function saveNote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => 'nullable|string|max:20000',
        ]);
        DB::table('user_notes')->updateOrInsert(
            ['user_id' => $request->user()->id],
            ['content' => $data['content'] ?? '', 'updated_at' => now()],
        );
        return response()->json(['message' => 'Сохранено', 'updated_at' => now()]);
    }

    // ==================== PRESENCE / HEARTBEAT ====================

    /**
     * Тик от Vue раз в 30 сек: обновляем WebUser.last_seen_at.
     * Внутри UPDATE по PK — дешёво, без коллизий с другими полями.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        DB::table('WebUser')->where('id', $request->user()->id)
            ->update(['last_seen_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /**
     * Список staff'а с разделением «онлайн» (≤90 сек) / «недавно»
     * (90 сек — 10 мин). Используется виджетом «Кто онлайн».
     */
    public function whoOnline(Request $request): JsonResponse
    {
        $now = now();
        $rows = DB::table('WebUser')
            ->whereNull('dateDeleted')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $now->copy()->subMinutes(10))
            ->where(function ($q) {
                foreach (['admin', 'backoffice', 'support', 'head', 'finance', 'calculations', 'corrections', 'education', 'invest'] as $r) {
                    $q->orWhere('role', 'ilike', "%{$r}%");
                }
            })
            ->orderByDesc('last_seen_at')
            ->limit(40)
            ->get(['id', 'firstName', 'lastName', 'role', 'last_seen_at']);

        $online = [];
        $recent = [];
        foreach ($rows as $r) {
            if ((int) $r->id === (int) $request->user()->id) continue;
            $secAgo = $now->diffInSeconds($r->last_seen_at);
            $entry = [
                'id' => $r->id,
                'name' => trim(($r->lastName ?? '') . ' ' . ($r->firstName ?? '')),
                'role' => $r->role,
                'secAgo' => $secAgo,
            ];
            if ($secAgo <= 90) $online[] = $entry;
            else $recent[] = $entry;
        }
        return response()->json(['online' => $online, 'recent' => $recent]);
    }

    /**
     * «Мой день» — личные метрики сотрудника за сегодня:
     *   - тикетов закрыто (chat_tickets.closed_by + status='resolved'/'closed');
     *   - сообщений отправлено;
     *   - назначено активных тикетов сейчас;
     *   - действий в audit-log за день (любой write со стороны юзера).
     */
    public function myDay(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $startOfDay = now()->startOfDay();

        $closedToday = DB::table('chat_tickets')
            ->where('closed_by', $userId)
            ->whereIn('status', ['resolved', 'closed'])
            ->where('closed_at', '>=', $startOfDay)
            ->count();

        $messagesToday = DB::table('chat_messages')
            ->where('sender_id', $userId)
            ->where('is_system', false)
            ->where('created_at', '>=', $startOfDay)
            ->count();

        $assignedActive = DB::table('chat_tickets')
            ->where('assigned_to', $userId)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        // audit_log может ещё не быть в legacy окружении — best-effort.
        $auditToday = 0;
        try {
            $auditToday = DB::table('audit_log')
                ->where('user_id', $userId)
                ->where('created_at', '>=', $startOfDay)
                ->count();
        } catch (\Throwable $e) {
            // schema absent — silently 0
        }

        return response()->json([
            'closedToday' => $closedToday,
            'messagesToday' => $messagesToday,
            'assignedActive' => $assignedActive,
            'auditToday' => $auditToday,
        ]);
    }
}
