<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Конструктор меню: кастомные пункты навигации (только admin).
 * Публичная выдача (published) доступна любому авторизованному пользователю —
 * layouts мёржат её поверх статической навигации.
 */
class AdminMenuController extends Controller
{
    /** Список всех пунктов для админки (с группировкой по области). */
    public function index(): JsonResponse
    {
        return response()->json([
            'items' => MenuItem::query()
                ->orderBy('area')->orderBy('sort_order')->orderBy('id')
                ->get(),
            'areas' => MenuItem::AREAS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $item = MenuItem::create($this->validateData($request));
        Audit::log('create', 'menu_item', $item->id, ['title' => $item->title, 'area' => $item->area]);

        return response()->json(['item' => $item], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $item = MenuItem::findOrFail($id);
        $item->update($this->validateData($request));
        Audit::log('update', 'menu_item', $item->id, ['title' => $item->title]);

        return response()->json(['item' => $item->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $item = MenuItem::findOrFail($id);
        $item->delete();
        Audit::log('delete', 'menu_item', $id, ['title' => $item->title]);

        return response()->json(['message' => 'Пункт удалён']);
    }

    /** Пакетное изменение порядка: [{id, sort_order}, ...]. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer'],
        ]);
        foreach ($data['order'] as $row) {
            MenuItem::where('id', $row['id'])->update(['sort_order' => $row['sort_order']]);
        }

        return response()->json(['message' => 'Порядок сохранён']);
    }

    /**
     * Опубликованные пункты для текущего пользователя и области.
     * Фильтр по ролям: roles=null → всем; иначе роль пользователя должна входить.
     */
    public function published(Request $request): JsonResponse
    {
        $area = $request->input('area', 'admin');
        if (! in_array($area, MenuItem::AREAS, true)) {
            $area = 'admin';
        }
        $role = $request->user()?->role;

        $items = MenuItem::query()
            ->where('area', $area)->where('active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'group_title', 'title', 'icon', 'to', 'external', 'roles'])
            ->filter(fn ($i) => empty($i->roles) || ($role && in_array($role, $i->roles, true)))
            ->values();

        return response()->json(['items' => $items]);
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'area' => ['required', Rule::in(MenuItem::AREAS)],
            'group_title' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'to' => ['nullable', 'string', 'max:512'],
            'external' => ['boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'max:64'],
            'sort_order' => ['nullable', 'integer'],
            'active' => ['boolean'],
        ]);
        $data['to'] = $data['to'] ?? '';
        $data['external'] = (bool) ($data['external'] ?? false);
        $data['active'] = (bool) ($data['active'] ?? true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        if (empty($data['roles'])) {
            $data['roles'] = null;
        }
        if (empty($data['group_title'])) {
            $data['group_title'] = null;
        }

        return $data;
    }
}
