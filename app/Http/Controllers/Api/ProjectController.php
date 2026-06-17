<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TaskStage;
use App\Support\UserLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Проекты (канбан-доски) модуля «Задачи и Проекты».
 * Доступ — любой авторизованный пользователь видит проекты, где он автор
 * или участник.
 */
class ProjectController extends Controller
{
    /** Стадии по умолчанию для нового проекта. */
    private const DEFAULT_STAGES = [
        ['name' => 'Бэклог',     'color' => '#90A4AE', 'is_done' => false],
        ['name' => 'В работе',   'color' => '#42A5F5', 'is_done' => false],
        ['name' => 'На проверке', 'color' => '#FFA726', 'is_done' => false],
        ['name' => 'Готово',     'color' => '#66BB6A', 'is_done' => true],
    ];

    public function index(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $memberProjectIds = DB::table('project_members')->where('user_id', $uid)->pluck('project_id');

        $projects = Project::query()
            ->where('archived', false)
            ->where(fn ($q) => $q->where('created_by', $uid)->orWhereIn('id', $memberProjectIds))
            ->orderByDesc('id')
            ->get();

        // Счётчики задач по проектам одним запросом.
        $counts = DB::table('tasks')
            ->whereIn('project_id', $projects->pluck('id'))
            ->selectRaw('project_id, count(*) as c')
            ->groupBy('project_id')->pluck('c', 'project_id');

        return response()->json([
            'projects' => $projects->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'color' => $p->color,
                'archived' => $p->archived,
                'tasks_count' => (int) ($counts[$p->id] ?? 0),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'max:9'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
        ]);
        $uid = (int) $request->user()->id;

        $project = DB::transaction(function () use ($data, $uid) {
            $project = Project::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'color' => $data['color'] ?? '#2E7D32',
                'created_by' => $uid,
            ]);

            // Стадии по умолчанию.
            foreach (self::DEFAULT_STAGES as $i => $s) {
                TaskStage::create([
                    'project_id' => $project->id,
                    'name' => $s['name'],
                    'color' => $s['color'],
                    'sort_order' => $i,
                    'is_done' => $s['is_done'],
                ]);
            }

            // Автор + указанные участники.
            $members = collect($data['member_ids'] ?? [])->map(fn ($i) => (int) $i)->push($uid)->unique();
            foreach ($members as $mid) {
                DB::table('project_members')->insert([
                    'project_id' => $project->id, 'user_id' => $mid,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $project;
        });

        return response()->json(['project' => $project], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $project = $this->accessibleProject($request, $id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'max:9'],
            'archived' => ['boolean'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
        ]);

        $project->update(collect($data)->only(['name', 'description', 'color', 'archived'])->all());

        if (array_key_exists('member_ids', $data)) {
            $members = collect($data['member_ids'] ?? [])->map(fn ($i) => (int) $i)
                ->push((int) $project->created_by)->unique();
            DB::table('project_members')->where('project_id', $project->id)->delete();
            foreach ($members as $mid) {
                DB::table('project_members')->insert([
                    'project_id' => $project->id, 'user_id' => $mid,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return response()->json(['project' => $project->fresh()]);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $project = $this->accessibleProject($request, $id);
        if ((int) $project->created_by !== (int) $request->user()->id) {
            abort(403, 'Удалить проект может только автор');
        }
        $project->delete();

        return response()->json(['message' => 'Проект удалён']);
    }

    /** Карточка проекта с участниками (для настроек проекта). */
    public function show(int $id, Request $request): JsonResponse
    {
        $project = $this->accessibleProject($request, $id);
        $memberIds = $project->memberIds();

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'color' => $project->color,
                'archived' => $project->archived,
                'created_by' => (int) $project->created_by,
                'members' => array_values(UserLookup::map($memberIds)),
            ],
        ]);
    }

    /**
     * Пикер пользователей для постановщика/исполнителя/наблюдателей/участников.
     * Поиск по ФИО/email; возвращает до 30 активных WebUser.
     */
    public function assignableUsers(Request $request): JsonResponse
    {
        $s = trim((string) $request->input('search', ''));
        $q = DB::table('WebUser')->select(['id', 'firstName', 'lastName', 'email', 'role']);
        if ($s !== '') {
            $q->where(function ($w) use ($s) {
                $w->where('firstName', 'ilike', "%{$s}%")
                  ->orWhere('lastName', 'ilike', "%{$s}%")
                  ->orWhere('email', 'ilike', "%{$s}%")
                  ->orWhereRaw('CONCAT("firstName", \' \', "lastName") ilike ?', ["%{$s}%"]);
            });
        }
        $rows = $q->orderBy('firstName')->orderBy('lastName')->limit(30)->get();

        return response()->json([
            'users' => $rows->map(fn ($u) => [
                'id' => (int) $u->id,
                'name' => trim("{$u->firstName} {$u->lastName}") ?: ($u->email ?? "#{$u->id}"),
                'email' => $u->email,
                'role' => $u->role,
            ]),
        ]);
    }

    /** Проект, доступный текущему пользователю (автор или участник), иначе 403/404. */
    private function accessibleProject(Request $request, int $id): Project
    {
        $project = Project::findOrFail($id);
        $uid = (int) $request->user()->id;
        $isMember = DB::table('project_members')
            ->where('project_id', $id)->where('user_id', $uid)->exists();
        if ((int) $project->created_by !== $uid && ! $isMember) {
            abort(403, 'Нет доступа к проекту');
        }

        return $project;
    }
}
