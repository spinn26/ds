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
}
