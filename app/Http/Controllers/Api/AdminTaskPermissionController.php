<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TaskPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Права доступа к задачам (только admin): матрица «роль × действие» +
 * флаг «колонками управляют только администраторы». Хранится в system_settings.
 */
class AdminTaskPermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'actions' => TaskPermissions::ACTIONS,
            'roles' => TaskPermissions::ROLES,
            'matrix' => TaskPermissions::matrix(),
            'columns_admin_only' => TaskPermissions::columnsAdminOnly(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'matrix' => ['required', 'array'],
            'columns_admin_only' => ['boolean'],
        ]);

        // Нормализуем матрицу строго по известным ролям/действиям (защита от мусора).
        $clean = [];
        foreach (TaskPermissions::ROLES as $role => $_) {
            foreach (TaskPermissions::ACTIONS as $act => $__) {
                $clean[$role][$act] = (bool) ($data['matrix'][$role][$act] ?? TaskPermissions::DEFAULTS[$role][$act] ?? false);
            }
        }

        $this->putSetting('tasks.permissions', json_encode($clean, JSON_UNESCAPED_UNICODE), 'json');
        $this->putSetting('tasks.columns_admin_only', ! empty($data['columns_admin_only']) ? '1' : '0', 'bool');

        return response()->json([
            'message' => 'Права сохранены',
            'matrix' => $clean,
            'columns_admin_only' => ! empty($data['columns_admin_only']),
        ]);
    }

    /** Записать настройку (insert при отсутствии) и сбросить кэш карты настроек. */
    private function putSetting(string $key, string $value, string $type): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'type' => $type, 'updated_at' => now(), 'created_at' => now()],
        );
        Cache::forget('system_settings:map');
    }
}
