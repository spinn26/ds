<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\TaskStage;
use App\Support\TaskPermissions;
use App\Support\UserLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /** Канбан проекта: стадии + задачи. */
    public function board(int $projectId, Request $request): JsonResponse
    {
        $project = $this->accessibleProject($request, $projectId);
        $stages = $project->stages()->get(['id', 'name', 'color', 'sort_order', 'is_done']);
        $tasks = Task::where('project_id', $projectId)->whereNull('parent_id')
            ->orderBy('sort_order')->orderByDesc('id')->get();

        return response()->json([
            'project' => ['id' => $project->id, 'name' => $project->name, 'color' => $project->color],
            'stages' => $stages,
            'tasks' => $this->serializeMany($tasks),
        ]);
    }

    /** Общая доска (без проекта): кастомные колонки-стадии + задачи по охвату. */
    public function boardDefault(Request $request): JsonResponse
    {
        // Сид колонок по умолчанию, если их ещё нет.
        if (TaskStage::whereNull('project_id')->count() === 0) {
            $defaults = [
                ['Бэклог', '#90A4AE', false], ['В работе', '#42A5F5', false],
                ['На стопе', '#FFA726', false], ['Готово', '#66BB6A', true],
            ];
            foreach ($defaults as $i => $d) {
                TaskStage::create(['project_id' => null, 'name' => $d[0], 'color' => $d[1], 'sort_order' => $i, 'is_done' => $d[2]]);
            }
        }
        $stages = TaskStage::whereNull('project_id')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'color', 'sort_order', 'is_done']);

        // Доска — единый дом всех задач пользователя (раздел «Проекты» убран),
        // поэтому по project_id НЕ фильтруем. Группировка по общим колонкам;
        // задачи с «чужой»/пустой стадией фронт сваливает в первую колонку.
        $q = Task::query()->whereNull('parent_id');
        $this->applyScope($q, $request);
        $tasks = $q->orderBy('sort_order')->orderByDesc('id')->get();

        return response()->json([
            'stages' => $stages,
            'stage_ids' => $stages->pluck('id'),
            'tasks' => $this->serializeMany($tasks),
        ]);
    }

    /** «Мои задачи» — назначенные/созданные/наблюдаемые, с фильтрами. */
    public function index(Request $request): JsonResponse
    {
        $q = Task::query()->whereNull('parent_id');
        $this->applyScope($q, $request);

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('project_id')) {
            $q->where('project_id', (int) $request->input('project_id'));
        }
        if ($s = trim((string) $request->input('search', ''))) {
            $q->where('title', 'ilike', "%{$s}%");
        }

        $tasks = $q->orderByRaw('deadline asc nulls last')->orderByDesc('id')->limit(500)->get();

        // Названия проектов для строк списка.
        $projNames = Project::whereIn('id', $tasks->pluck('project_id')->filter()->unique())
            ->pluck('name', 'id');

        $rows = $this->serializeMany($tasks);
        foreach ($rows as &$r) {
            $r['project_name'] = $r['project_id'] ? ($projNames[$r['project_id']] ?? null) : null;
        }

        return response()->json(['tasks' => $rows]);
    }

    /** Фильтр охвата: assigned | created | watching | all (по текущему пользователю). */
    private function applyScope($q, Request $request): void
    {
        $uid = (int) $request->user()->id;
        $scope = $request->input('scope', 'assigned');
        if ($scope === 'assigned') {
            $q->where('assignee_id', $uid);
        } elseif ($scope === 'created') {
            $q->where('created_by', $uid);
        } elseif ($scope === 'watching') {
            $q->whereIn('id', DB::table('task_watchers')->where('user_id', $uid)->pluck('task_id'));
        } elseif ($scope === 'favorites') {
            $q->whereIn('id', DB::table('task_favorites')->where('user_id', $uid)->pluck('task_id'));
        } elseif ($scope === 'accomplice') {
            $q->whereIn('id', DB::table('task_accomplices')->where('user_id', $uid)->pluck('task_id'));
        } else { // all
            $watchIds = DB::table('task_watchers')->where('user_id', $uid)->pluck('task_id');
            $q->where(fn ($w) => $w->where('assignee_id', $uid)->orWhere('created_by', $uid)->orWhereIn('id', $watchIds));
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request, true);
        $uid = (int) $request->user()->id;

        if (! empty($data['project_id'])) {
            $this->accessibleProject($request, (int) $data['project_id']);
        }

        $task = DB::transaction(function () use ($data, $uid, $request) {
            $maxOrder = (int) Task::where('project_id', $data['project_id'] ?? null)
                ->where('stage_id', $data['stage_id'] ?? null)->max('sort_order');

            $task = Task::create([
                'project_id' => $data['project_id'] ?? null,
                'stage_id' => $data['stage_id'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'created_by' => $uid,
                'assignee_id' => $data['assignee_id'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'status' => $data['status'] ?? 'pending',
                'deadline' => $data['deadline'] ?? null,
                'tags' => $data['tags'] ?? null,
                'requires_result' => $data['requires_result'] ?? false,
                'sort_order' => $maxOrder + 1,
            ]);

            $this->syncWatchers($task, $request->input('watcher_ids', []));
            $this->syncAccomplices($task, $request->input('accomplice_ids', []));

            return $task;
        });

        \App\Services\TaskNotifier::assigned($task, $uid);

        return response()->json(['task' => $this->serialize($task->fresh())], 201);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);

        $data = $this->serialize($task);
        // Комментарии с авторами.
        $comments = $task->comments()->get();
        $authors = UserLookup::map($comments->pluck('user_id'));
        $data['comments'] = $comments->map(fn ($c) => [
            'id' => $c->id,
            'body' => $c->body,
            'created_at' => $c->created_at,
            'author' => $authors[(int) $c->user_id] ?? ['id' => (int) $c->user_id, 'name' => "#{$c->user_id}"],
        ]);
        // Подзадачи.
        $subtasks = Task::where('parent_id', $id)->orderBy('id')->get();
        $data['subtasks'] = $this->serializeMany($subtasks);
        // Вложения.
        $data['attachments'] = $this->attachmentList($id);
        // Связанные задачи.
        $data['related'] = $this->relatedList($id);

        return response()->json(['task' => $data]);
    }

    /** Связанные задачи (двунаправленно): [{link_id, id, title, status}]. */
    private function relatedList(int $taskId): array
    {
        $links = DB::table('task_links')
            ->where('task_id', $taskId)->orWhere('related_task_id', $taskId)
            ->get();
        $otherIds = $links->map(fn ($l) => (int) $l->task_id === $taskId ? (int) $l->related_task_id : (int) $l->task_id);
        $titles = Task::whereIn('id', $otherIds)->get(['id', 'title', 'status'])->keyBy('id');

        return $links->map(function ($l) use ($taskId, $titles) {
            $otherId = (int) $l->task_id === $taskId ? (int) $l->related_task_id : (int) $l->task_id;
            $t = $titles[$otherId] ?? null;

            return $t ? ['link_id' => $l->id, 'id' => $t->id, 'title' => $t->title, 'status' => $t->status] : null;
        })->filter()->values()->all();
    }

    /** Сериализованный список вложений задачи. */
    private function attachmentList(int $taskId): array
    {
        $rows = TaskAttachment::where('task_id', $taskId)->orderByDesc('id')->get();
        $authors = UserLookup::map($rows->pluck('user_id'));

        return $rows->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->original_name,
            'size' => $a->size,
            'mime' => $a->mime,
            'created_at' => $a->created_at,
            'author' => $authors[(int) $a->user_id] ?? null,
        ])->all();
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        $data = $this->validateData($request, false);

        // Проверка прав по характеру изменения (Bitrix-матрица).
        $user = $request->user();
        $need = [];
        if (array_key_exists('status', $data) && $data['status'] !== $task->status) $need[] = 'change_status';
        if (array_key_exists('deadline', $data) || array_key_exists('started_at', $data)) $need[] = 'change_deadline';
        if (array_key_exists('assignee_id', $data) && (int) $data['assignee_id'] !== (int) $task->assignee_id) $need[] = 'change_responsible';
        if (array_intersect(array_keys($data), ['title', 'description', 'priority', 'stage_id', 'parent_id', 'watcher_ids', 'accomplice_ids', 'tags', 'requires_result', 'result'])) $need[] = 'edit';
        if (! $need) $need[] = 'edit';
        foreach (array_unique($need) as $act) {
            if (! TaskPermissions::can($task, $user, $act)) {
                abort(403, 'Недостаточно прав: '.(TaskPermissions::ACTIONS[$act] ?? $act));
            }
        }

        // Авто-метки статуса по времени.
        if (isset($data['status'])) {
            if ($data['status'] === 'in_progress' && ! $task->started_at) {
                $data['started_at'] = now();
            }
            if ($data['status'] === 'done') {
                $data['completed_at'] = now();
            }
            if (in_array($data['status'], ['pending', 'in_progress', 'deferred'], true)) {
                $data['completed_at'] = null;
            }
        }

        $prevAssignee = (int) $task->assignee_id;
        $wasDone = $task->status === 'done';

        $task->update(collect($data)->except(['watcher_ids', 'accomplice_ids'])->all());

        if ($request->has('watcher_ids')) {
            $this->syncWatchers($task, $request->input('watcher_ids', []));
        }
        if ($request->has('accomplice_ids')) {
            $this->syncAccomplices($task, $request->input('accomplice_ids', []));
        }

        $uid = (int) $request->user()->id;
        if ((int) $task->assignee_id !== $prevAssignee) {
            \App\Services\TaskNotifier::assigned($task, $uid);
        }
        if (! $wasDone && $task->status === 'done') {
            \App\Services\TaskNotifier::completed($task, $uid);
        }

        return response()->json(['task' => $this->serialize($task->fresh())]);
    }

    /** Перемещение карточки по канбану: смена стадии и позиции. */
    public function move(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        if (! TaskPermissions::can($task, $request->user(), 'change_status')) {
            abort(403, 'Недостаточно прав: '.TaskPermissions::ACTIONS['change_status']);
        }
        $data = $request->validate([
            'stage_id' => ['nullable', 'integer', 'exists:task_stages,id'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $updates = ['stage_id' => $data['stage_id'] ?? null, 'sort_order' => $data['sort_order'] ?? 0];

        // Если перенесли в стадию-«готово» — помечаем выполненной (и наоборот).
        if (! empty($data['stage_id'])) {
            $isDone = DB::table('task_stages')->where('id', $data['stage_id'])->value('is_done');
            if ($isDone && $task->status !== 'done') {
                $updates['status'] = 'done';
                $updates['completed_at'] = now();
            } elseif (! $isDone && $task->status === 'done') {
                $updates['status'] = 'in_progress';
                $updates['completed_at'] = null;
            }
        }

        $task->update($updates);

        if (($updates['status'] ?? null) === 'done') {
            \App\Services\TaskNotifier::completed($task, (int) $request->user()->id);
        }

        return response()->json(['task' => $this->serialize($task->fresh())]);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        if (! TaskPermissions::can($task, $request->user(), 'delete')) {
            abort(403, 'Недостаточно прав: '.TaskPermissions::ACTIONS['delete']);
        }
        $task->delete();

        return response()->json(['message' => 'Задача удалена']);
    }

    public function addComment(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        if (! TaskPermissions::can($task, $request->user(), 'comment')) {
            abort(403, 'Недостаточно прав: '.TaskPermissions::ACTIONS['comment']);
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $comment = TaskComment::create([
            'task_id' => $id,
            'user_id' => (int) $request->user()->id,
            'body' => $data['body'],
        ]);
        $author = UserLookup::one((int) $request->user()->id);

        \App\Services\TaskNotifier::commented($task, (int) $request->user()->id, $data['body']);

        return response()->json(['comment' => [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at,
            'author' => $author,
        ]], 201);
    }

    // ───────────── helpers ─────────────

    private function validateData(Request $request, bool $creating): array
    {
        return $request->validate([
            'project_id' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'stage_id' => ['nullable', 'integer', 'exists:task_stages,id'],
            'parent_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'assignee_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'started_at' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'watcher_ids' => ['nullable', 'array'],
            'watcher_ids.*' => ['integer'],
            'accomplice_ids' => ['nullable', 'array'],
            'accomplice_ids.*' => ['integer'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'requires_result' => ['boolean'],
            'result' => ['nullable', 'string', 'max:20000'],
        ]);
    }

    private function syncWatchers(Task $task, $ids): void
    {
        $this->syncPivot('task_watchers', $task->id, $ids);
    }

    private function syncAccomplices(Task $task, $ids): void
    {
        $this->syncPivot('task_accomplices', $task->id, $ids);
    }

    /** Перезаписать pivot task_id↔user_id (наблюдатели / соисполнители). */
    private function syncPivot(string $table, int $taskId, $ids): void
    {
        $ids = collect($ids)->map(fn ($i) => (int) $i)->filter()->unique();
        DB::table($table)->where('task_id', $taskId)->delete();
        foreach ($ids as $uid) {
            DB::table($table)->insert([
                'task_id' => $taskId, 'user_id' => $uid,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param \Illuminate\Support\Collection<Task> $tasks */
    private function serializeMany($tasks): array
    {
        if ($tasks->isEmpty()) {
            return [];
        }
        $ids = $tasks->pluck('id');
        $uid = (int) auth()->id();
        $watchers = DB::table('task_watchers')->whereIn('task_id', $ids)->get()->groupBy('task_id');
        $accomplices = DB::table('task_accomplices')->whereIn('task_id', $ids)->get()->groupBy('task_id');
        $favIds = DB::table('task_favorites')->where('user_id', $uid)->whereIn('task_id', $ids)->pluck('task_id')->flip();
        $userIds = $tasks->pluck('created_by')
            ->merge($tasks->pluck('assignee_id'))
            ->merge($watchers->flatten(1)->pluck('user_id'))
            ->merge($accomplices->flatten(1)->pluck('user_id'));
        $users = UserLookup::map($userIds);
        $commentCounts = DB::table('task_comments')->whereIn('task_id', $ids)
            ->selectRaw('task_id, count(*) c')->groupBy('task_id')->pluck('c', 'task_id');
        $timers = DB::table('task_timers')->where('user_id', $uid)->whereIn('task_id', $ids)->pluck('started_at', 'task_id');

        return $tasks->map(fn ($t) => $this->shape($t, $users, $watchers, $accomplices, $favIds, $commentCounts, $timers))->all();
    }

    private function serialize(Task $task): array
    {
        $id = $task->id;
        $uid = (int) auth()->id();
        $watchers = DB::table('task_watchers')->where('task_id', $id)->get()->groupBy('task_id');
        $accomplices = DB::table('task_accomplices')->where('task_id', $id)->get()->groupBy('task_id');
        $favIds = DB::table('task_favorites')->where('user_id', $uid)->where('task_id', $id)->pluck('task_id')->flip();
        $users = UserLookup::map(collect([$task->created_by, $task->assignee_id])
            ->merge($watchers->flatten(1)->pluck('user_id'))
            ->merge($accomplices->flatten(1)->pluck('user_id')));
        $cc = collect([$id => DB::table('task_comments')->where('task_id', $id)->count()]);
        $timers = DB::table('task_timers')->where('user_id', $uid)->where('task_id', $id)->pluck('started_at', 'task_id');

        return $this->shape($task, $users, $watchers, $accomplices, $favIds, $cc, $timers);
    }

    private function shape(Task $t, array $users, $watchers, $accomplices, $favIds, $commentCounts, $timers = null): array
    {
        $mapUsers = fn ($coll) => ($coll[$t->id] ?? collect())->pluck('user_id')
            ->map(fn ($id) => $users[(int) $id] ?? null)->filter()->values();
        $timerStarted = $timers ? ($timers[$t->id] ?? null) : null;

        return [
            'id' => $t->id,
            'project_id' => $t->project_id,
            'stage_id' => $t->stage_id,
            'parent_id' => $t->parent_id,
            'title' => $t->title,
            'description' => $t->description,
            'priority' => $t->priority,
            'status' => $t->status,
            'deadline' => $t->deadline,
            'started_at' => $t->started_at,
            'sort_order' => $t->sort_order,
            'created_at' => $t->created_at,
            'tags' => $t->tags ?? [],
            'creator' => $users[(int) $t->created_by] ?? null,
            'assignee' => $t->assignee_id ? ($users[(int) $t->assignee_id] ?? null) : null,
            'watchers' => $mapUsers($watchers),
            'accomplices' => $mapUsers($accomplices),
            'is_favorite' => $favIds->has($t->id),
            'comments_count' => (int) ($commentCounts[$t->id] ?? 0),
            'time_spent' => (int) $t->time_spent,
            'timer_running' => $timerStarted !== null,
            'timer_started_at' => $timerStarted,
            'requires_result' => (bool) $t->requires_result,
            'result' => $t->result,
        ];
    }

    private function accessibleProject(Request $request, int $id): Project
    {
        $project = Project::findOrFail($id);
        $uid = (int) $request->user()->id;
        $isMember = DB::table('project_members')->where('project_id', $id)->where('user_id', $uid)->exists();
        if ((int) $project->created_by !== $uid && ! $isMember) {
            abort(403, 'Нет доступа к проекту');
        }

        return $project;
    }

    /** Переключить «избранное» для текущего пользователя. */
    public function toggleFavorite(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        $uid = (int) $request->user()->id;
        $exists = DB::table('task_favorites')->where('task_id', $id)->where('user_id', $uid)->exists();
        if ($exists) {
            DB::table('task_favorites')->where('task_id', $id)->where('user_id', $uid)->delete();
        } else {
            DB::table('task_favorites')->insert(['task_id' => $id, 'user_id' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['is_favorite' => ! $exists]);
    }

    // ───────────── Вложения ─────────────

    /** Каталог хранения вложений задачи на приватном диске. */
    private function attachDir(int $taskId): string
    {
        return "task-attachments/{$taskId}";
    }

    public function uploadAttachment(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        if (! TaskPermissions::can($task, $request->user(), 'comment')) {
            abort(403, 'Недостаточно прав для прикрепления файлов');
        }
        $request->validate([
            // SVG/исполняемые исключены (stored-XSS). До 25 МБ.
            'file' => ['required', 'file', 'max:25600',
                'mimes:png,jpg,jpeg,webp,gif,ico,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,7z,mp4,webm,mov,mp3'],
        ]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $stored = Str::random(24).'.'.$ext;
        $path = $file->storeAs($this->attachDir($id), $stored, 'local');

        $att = TaskAttachment::create([
            'task_id' => $id,
            'user_id' => (int) $request->user()->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
        ]);

        return response()->json(['attachment' => [
            'id' => $att->id,
            'name' => $att->original_name,
            'size' => $att->size,
            'mime' => $att->mime,
            'created_at' => $att->created_at,
            'author' => UserLookup::one((int) $request->user()->id),
        ]], 201);
    }

    public function downloadAttachment(int $id, int $attId, Request $request)
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        $att = TaskAttachment::where('task_id', $id)->findOrFail($attId);
        if (! Storage::disk('local')->exists($att->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($att->path, $att->original_name);
    }

    public function deleteAttachment(int $id, int $attId, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        $att = TaskAttachment::where('task_id', $id)->findOrFail($attId);
        // Удалять может загрузивший, имеющий право edit, либо админ.
        $uid = (int) $request->user()->id;
        if ((int) $att->user_id !== $uid && ! TaskPermissions::can($task, $request->user(), 'edit')) {
            abort(403, 'Недостаточно прав для удаления вложения');
        }
        Storage::disk('local')->delete($att->path);
        $att->delete();

        return response()->json(['message' => 'Вложение удалено']);
    }

    // ───────────── Связанные задачи / делегирование ─────────────

    /** Поиск задач по названию для связывания (id + title). */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $rows = Task::query()->whereNull('parent_id')
            ->when($q !== '', fn ($x) => $x->where('title', 'ilike', "%{$q}%"))
            ->orderByDesc('id')->limit(15)->get(['id', 'title', 'status']);

        return response()->json(['tasks' => $rows]);
    }

    public function linkTask(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        if (! TaskPermissions::can($task, $request->user(), 'edit')) {
            abort(403, 'Недостаточно прав для связывания');
        }
        $data = $request->validate(['related_task_id' => ['required', 'integer', 'different:'.$id, 'exists:tasks,id']]);
        $rid = (int) $data['related_task_id'];

        $exists = DB::table('task_links')->where(function ($w) use ($id, $rid) {
            $w->where(['task_id' => $id, 'related_task_id' => $rid])
              ->orWhere(['task_id' => $rid, 'related_task_id' => $id]);
        })->exists();
        if (! $exists) {
            DB::table('task_links')->insert([
                'task_id' => $id, 'related_task_id' => $rid,
                'created_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json(['related' => $this->relatedList($id)]);
    }

    public function unlinkTask(int $id, int $linkId, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        if (! TaskPermissions::can($task, $request->user(), 'edit')) {
            abort(403, 'Недостаточно прав');
        }
        DB::table('task_links')->where('id', $linkId)
            ->where(fn ($w) => $w->where('task_id', $id)->orWhere('related_task_id', $id))->delete();

        return response()->json(['related' => $this->relatedList($id)]);
    }

    /** Делегирование: назначить нового исполнителя, прежнего — в наблюдатели. */
    public function delegate(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        if (! TaskPermissions::can($task, $request->user(), 'change_responsible')) {
            abort(403, 'Недостаточно прав для делегирования');
        }
        $data = $request->validate(['assignee_id' => ['required', 'integer']]);
        $prev = (int) $task->assignee_id;
        $new = (int) $data['assignee_id'];

        $task->update(['assignee_id' => $new]);
        if ($prev && $prev !== $new) {
            DB::table('task_watchers')->updateOrInsert(
                ['task_id' => $id, 'user_id' => $prev],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
        \App\Services\TaskNotifier::assigned($task, (int) $request->user()->id);

        return response()->json(['task' => $this->serialize($task->fresh())]);
    }

    // ───────────── Учёт времени ─────────────

    public function startTimer(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        DB::table('task_timers')->updateOrInsert(
            ['task_id' => $id, 'user_id' => (int) $request->user()->id],
            ['started_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['task' => $this->serialize($task->fresh())]);
    }

    public function stopTimer(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        $this->assertTaskAccess($request, $task);
        $uid = (int) $request->user()->id;
        $timer = DB::table('task_timers')->where('task_id', $id)->where('user_id', $uid)->first();
        if ($timer) {
            $elapsed = max(0, now()->getTimestamp() - \Illuminate\Support\Carbon::parse($timer->started_at)->getTimestamp());
            $task->increment('time_spent', $elapsed);
            DB::table('task_timers')->where('task_id', $id)->where('user_id', $uid)->delete();
        }

        return response()->json(['task' => $this->serialize($task->fresh())]);
    }

    /** Доступ к задаче: причастность (автор/исполнитель/соисполнитель/наблюдатель) или доступ к её проекту. */
    private function assertTaskAccess(Request $request, Task $task): void
    {
        $uid = (int) $request->user()->id;
        if ((int) $task->created_by === $uid || (int) $task->assignee_id === $uid) {
            return;
        }
        if ($task->project_id) {
            $this->accessibleProject($request, (int) $task->project_id);

            return;
        }
        $related = DB::table('task_watchers')->where('task_id', $task->id)->where('user_id', $uid)->exists()
            || DB::table('task_accomplices')->where('task_id', $task->id)->where('user_id', $uid)->exists();
        if (! $related) {
            abort(403, 'Нет доступа к задаче');
        }
    }
}
