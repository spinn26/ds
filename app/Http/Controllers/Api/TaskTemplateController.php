<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaskTemplate;
use App\Services\TaskTemplateRunner;
use App\Support\UserLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Шаблоны задач + повторяющиеся задачи. Доступно всем ролям; пользователь
 * видит свои шаблоны (админ — все).
 */
class TaskTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $isAdmin = $request->user()->hasAnyRole(['admin']);
        $rows = TaskTemplate::query()
            ->when(! $isAdmin, fn ($q) => $q->where('created_by', $uid))
            ->orderByDesc('id')->get();
        $assignees = UserLookup::map($rows->pluck('assignee_id'));

        return response()->json([
            'templates' => $rows->map(fn ($t) => $this->shape($t, $assignees)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $tpl = new TaskTemplate($data);
        $tpl->created_by = (int) $request->user()->id;
        $tpl->next_run_at = $tpl->recurrence_freq !== 'none' ? $tpl->computeNextRun() : null;
        $tpl->save();

        return response()->json(['template' => $this->shape($tpl, UserLookup::map([$tpl->assignee_id]))], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $tpl = $this->ownedOrAdmin($request, $id);
        $tpl->fill($this->validateData($request));
        $tpl->next_run_at = $tpl->recurrence_freq !== 'none'
            ? ($tpl->next_run_at ?: $tpl->computeNextRun())
            : null;
        $tpl->save();

        return response()->json(['template' => $this->shape($tpl->fresh(), UserLookup::map([$tpl->assignee_id]))]);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $this->ownedOrAdmin($request, $id)->delete();

        return response()->json(['message' => 'Шаблон удалён']);
    }

    /** Создать задачу по шаблону прямо сейчас. */
    public function instantiate(int $id, Request $request): JsonResponse
    {
        $tpl = $this->ownedOrAdmin($request, $id);
        $task = TaskTemplateRunner::instantiate($tpl, (int) $request->user()->id);

        return response()->json(['task_id' => $task->id, 'message' => 'Задача создана'], 201);
    }

    private function shape(TaskTemplate $t, array $assignees): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'title' => $t->title,
            'description' => $t->description,
            'priority' => $t->priority,
            'tags' => $t->tags ?? [],
            'requires_result' => (bool) $t->requires_result,
            'checklist' => $t->checklist ?? [],
            'assignee_id' => $t->assignee_id,
            'assignee' => $t->assignee_id ? ($assignees[(int) $t->assignee_id] ?? null) : null,
            'recurrence_freq' => $t->recurrence_freq,
            'recurrence_interval' => $t->recurrence_interval,
            'recurrence_weekday' => $t->recurrence_weekday,
            'recurrence_monthday' => $t->recurrence_monthday,
            'recurrence_time' => $t->recurrence_time,
            'active' => (bool) $t->active,
            'next_run_at' => $t->next_run_at,
        ];
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'priority' => ['nullable', 'string', 'max:16'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'requires_result' => ['boolean'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['string', 'max:500'],
            'assignee_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'recurrence_freq' => ['nullable', Rule::in(TaskTemplate::FREQ)],
            'recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:365'],
            'recurrence_weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'recurrence_monthday' => ['nullable', 'integer', 'min:1', 'max:31'],
            'recurrence_time' => ['nullable', 'string', 'max:5'],
            'active' => ['boolean'],
        ]);
        $data['recurrence_freq'] = $data['recurrence_freq'] ?? 'none';
        $data['active'] = (bool) ($data['active'] ?? true);
        $data['requires_result'] = (bool) ($data['requires_result'] ?? false);

        return $data;
    }

    private function ownedOrAdmin(Request $request, int $id): TaskTemplate
    {
        $tpl = TaskTemplate::findOrFail($id);
        if ((int) $tpl->created_by !== (int) $request->user()->id && ! $request->user()->hasAnyRole(['admin'])) {
            abort(403, 'Нет доступа к шаблону');
        }

        return $tpl;
    }
}
