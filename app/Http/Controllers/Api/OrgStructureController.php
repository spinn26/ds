<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\UserLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Оргструктура компании (Структура компании). Просмотр — любой staff,
 * редактирование — только admin. Партнёрам недоступно (роут в staff-группе).
 */
class OrgStructureController extends Controller
{
    /** Дерево отделов (плоский список — дерево строит фронт) + участники. */
    public function index(): JsonResponse
    {
        $depts = Department::orderBy('sort_order')->orderBy('id')->get();
        $members = DB::table('department_members')->get()->groupBy('department_id');

        $userIds = $depts->pluck('head_id')
            ->merge($depts->pluck('deputy_id'))
            ->merge($members->flatten(1)->pluck('user_id'));
        $users = UserLookup::map($userIds);

        return response()->json([
            'departments' => $depts->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'description' => $d->description,
                'parent_id' => $d->parent_id,
                'sort_order' => $d->sort_order,
                'head' => $d->head_id ? ($users[(int) $d->head_id] ?? null) : null,
                'deputy' => $d->deputy_id ? ($users[(int) $d->deputy_id] ?? null) : null,
                'members' => ($members[$d->id] ?? collect())->pluck('user_id')
                    ->map(fn ($id) => $users[(int) $id] ?? null)->filter()->values(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $data = $this->validateData($request);
        $data['sort_order'] = (int) Department::where('parent_id', $data['parent_id'] ?? null)->max('sort_order') + 1;
        $dept = Department::create($data);

        return response()->json(['department' => $dept], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $dept = Department::findOrFail($id);
        $data = $this->validateData($request);
        // Защита от цикла: нельзя сделать отдел потомком самого себя.
        if (! empty($data['parent_id']) && $this->wouldCycle($id, (int) $data['parent_id'])) {
            abort(422, 'Нельзя переместить отдел внутрь его же подчинённого');
        }
        $dept->update($data);

        return response()->json(['department' => $dept->fresh()]);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $dept = Department::findOrFail($id);
        // Подотделы и сотрудники поднимаются на уровень выше.
        Department::where('parent_id', $id)->update(['parent_id' => $dept->parent_id]);
        $dept->delete();

        return response()->json(['message' => 'Отдел удалён']);
    }

    /** Добавить сотрудников в отдел. */
    public function addMembers(int $id, Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        Department::findOrFail($id);
        $data = $request->validate(['user_ids' => ['required', 'array'], 'user_ids.*' => ['integer']]);
        foreach (collect($data['user_ids'])->map(fn ($i) => (int) $i)->unique() as $uid) {
            DB::table('department_members')->updateOrInsert(
                ['department_id' => $id, 'user_id' => $uid],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }

        return response()->json(['message' => 'Сотрудники добавлены']);
    }

    public function removeMember(int $id, int $userId, Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        DB::table('department_members')->where('department_id', $id)->where('user_id', $userId)->delete();

        return response()->json(['message' => 'Сотрудник удалён из отдела']);
    }

    /** Мини-профиль сотрудника (контакты + отделы + руководитель). */
    public function employee(int $id): JsonResponse
    {
        $u = DB::table('WebUser')->where('id', $id)
            ->first(['id', 'firstName', 'lastName', 'email', 'phone', 'role', 'isBlocked', 'dateDeleted']);
        if (! $u) {
            abort(404);
        }
        // Отделы сотрудника + где он руководитель.
        $memberDeptIds = DB::table('department_members')->where('user_id', $id)->pluck('department_id');
        $depts = Department::where(fn ($q) => $q->whereIn('id', $memberDeptIds)->orWhere('head_id', $id)->orWhere('deputy_id', $id))
            ->get(['id', 'name', 'head_id', 'deputy_id']);
        $headNames = UserLookup::map($depts->pluck('head_id'));

        $status = $u->dateDeleted ? 'Уволен' : ($u->isBlocked ? 'Заблокирован' : 'Активен');

        return response()->json([
            'employee' => [
                'id' => (int) $u->id,
                'name' => trim("{$u->firstName} {$u->lastName}") ?: ($u->email ?? "#{$u->id}"),
                'email' => $u->email,
                'phone' => $u->phone,
                'role' => $u->role,
                'status' => $status,
                'departments' => $depts->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'role' => (int) $d->head_id === $id ? 'Руководитель' : ((int) $d->deputy_id === $id ? 'Заместитель' : 'Сотрудник'),
                    'head' => $d->head_id ? ($headNames[(int) $d->head_id]['name'] ?? null) : null,
                ]),
            ],
        ]);
    }

    /** Поиск сотрудников для пикеров (ФИО/email). */
    public function searchUsers(Request $request): JsonResponse
    {
        $s = trim((string) $request->input('search', ''));
        $q = DB::table('WebUser')->whereNull('dateDeleted')->select(['id', 'firstName', 'lastName', 'email', 'role']);
        if ($s !== '') {
            $q->where(function ($w) use ($s) {
                $w->where('firstName', 'ilike', "%{$s}%")
                  ->orWhere('lastName', 'ilike', "%{$s}%")
                  ->orWhere('email', 'ilike', "%{$s}%")
                  ->orWhereRaw('CONCAT("firstName", \' \', "lastName") ilike ?', ["%{$s}%"]);
            });
        }

        return response()->json([
            'users' => $q->orderBy('firstName')->limit(30)->get()->map(fn ($u) => [
                'id' => (int) $u->id,
                'name' => trim("{$u->firstName} {$u->lastName}") ?: ($u->email ?? "#{$u->id}"),
                'email' => $u->email,
                'role' => $u->role,
            ]),
        ]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'exists:departments,id'],
            'head_id' => ['nullable', 'integer'],
            'deputy_id' => ['nullable', 'integer'],
        ]);
    }

    /** Не приведёт ли назначение $parentId родителем $deptId к циклу. */
    private function wouldCycle(int $deptId, int $parentId): bool
    {
        $cur = $parentId;
        $guard = 0;
        while ($cur && $guard++ < 100) {
            if ($cur === $deptId) {
                return true;
            }
            $cur = (int) Department::where('id', $cur)->value('parent_id');
        }

        return false;
    }

    private function assertAdmin(Request $request): void
    {
        if (! $request->user()?->hasAnyRole(['admin'])) {
            abort(403, 'Редактировать оргструктуру может только администратор');
        }
    }
}
