<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TaskStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Колонки канбана (стадии) внутри проекта.
 */
class TaskStageController extends Controller
{
    public function store(int $projectId, Request $request): JsonResponse
    {
        $this->assertAccess($request, $projectId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:9'],
        ]);
        $max = (int) TaskStage::where('project_id', $projectId)->max('sort_order');
        $stage = TaskStage::create([
            'project_id' => $projectId,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#90A4AE',
            'sort_order' => $max + 1,
        ]);

        return response()->json(['stage' => $stage], 201);
    }

    public function update(int $projectId, int $id, Request $request): JsonResponse
    {
        $this->assertAccess($request, $projectId);
        $stage = TaskStage::where('project_id', $projectId)->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:9'],
            'is_done' => ['boolean'],
        ]);
        $stage->update($data);

        return response()->json(['stage' => $stage->fresh()]);
    }

    public function destroy(int $projectId, int $id, Request $request): JsonResponse
    {
        $this->assertAccess($request, $projectId);
        $stage = TaskStage::where('project_id', $projectId)->findOrFail($id);
        // Задачи стадии не удаляем — обнуляем stage_id (nullOnDelete уже в FK,
        // но делаем явно, чтобы карточки «осели» в «Без стадии»).
        DB::table('tasks')->where('stage_id', $id)->update(['stage_id' => null]);
        $stage->delete();

        return response()->json(['message' => 'Колонка удалена']);
    }

    /** Порядок колонок: [{id, sort_order}, ...]. */
    public function reorder(int $projectId, Request $request): JsonResponse
    {
        $this->assertAccess($request, $projectId);
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer'],
        ]);
        foreach ($data['order'] as $row) {
            TaskStage::where('project_id', $projectId)->where('id', $row['id'])
                ->update(['sort_order' => $row['sort_order']]);
        }

        return response()->json(['message' => 'Порядок сохранён']);
    }

    // ───────── Общая доска (project_id = null), shared для всех ролей ─────────

    public function storeBoard(Request $request): JsonResponse
    {
        $this->assertColumnAccess($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:9'],
        ]);
        $max = (int) TaskStage::whereNull('project_id')->max('sort_order');
        $stage = TaskStage::create([
            'project_id' => null,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#90A4AE',
            'sort_order' => $max + 1,
        ]);

        return response()->json(['stage' => $stage], 201);
    }

    public function updateBoard(int $id, Request $request): JsonResponse
    {
        $this->assertColumnAccess($request);
        $stage = TaskStage::whereNull('project_id')->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:9'],
            'is_done' => ['boolean'],
        ]);
        $stage->update($data);

        return response()->json(['stage' => $stage->fresh()]);
    }

    public function destroyBoard(int $id, Request $request): JsonResponse
    {
        $this->assertColumnAccess($request);
        $stage = TaskStage::whereNull('project_id')->findOrFail($id);
        DB::table('tasks')->where('stage_id', $id)->update(['stage_id' => null]);
        $stage->delete();

        return response()->json(['message' => 'Колонка удалена']);
    }

    public function reorderBoard(Request $request): JsonResponse
    {
        $this->assertColumnAccess($request);
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer'],
        ]);
        foreach ($data['order'] as $row) {
            TaskStage::whereNull('project_id')->where('id', $row['id'])
                ->update(['sort_order' => $row['sort_order']]);
        }

        return response()->json(['message' => 'Порядок сохранён']);
    }

    /** Если включено «колонками управляют только админы» — гейтим не-админов. */
    private function assertColumnAccess(Request $request): void
    {
        if (\App\Support\TaskPermissions::columnsAdminOnly()
            && ! $request->user()?->hasAnyRole(['admin'])) {
            abort(403, 'Управление колонками доступно только администраторам');
        }
    }

    private function assertAccess(Request $request, int $projectId): void
    {
        $project = Project::findOrFail($projectId);
        $uid = (int) $request->user()->id;
        $isMember = DB::table('project_members')
            ->where('project_id', $projectId)->where('user_id', $uid)->exists();
        if ((int) $project->created_by !== $uid && ! $isMember) {
            abort(403, 'Нет доступа к проекту');
        }
    }
}
