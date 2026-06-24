<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Выделяем «Матрица продаж» (/manage/reports/sales-matrix) в отдельную
 * permission-секцию `sales-matrix`. Раньше пункт меню был привязан к
 * `owner-dashboard`, поэтому в матрице прав (/manage/permissions) для неё
 * не было отдельной строки — выдать доступ независимо было нельзя.
 *
 * Сохраняем текущий доступ роли «head» (Руководитель), у которой матрица
 * была видна через `owner-dashboard`. Остальным админ выдаёт через UI.
 * admin не трогаем — он получает full на все секции через config/permissions.php.
 *
 * Идемпотентно: если ключ уже есть — пропускаем.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('permission_groups')) return;

        $row = DB::table('permission_groups')->where('key', 'head')->first();
        if (! $row) return;

        $perms = is_string($row->permissions)
            ? (json_decode($row->permissions, true) ?? [])
            : ($row->permissions ?? []);

        if (! isset($perms['sales-matrix'])) {
            $perms['sales-matrix'] = 'full';
            DB::table('permission_groups')
                ->where('key', 'head')
                ->update([
                    'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permission_groups')) return;

        $row = DB::table('permission_groups')->where('key', 'head')->first();
        if (! $row) return;

        $perms = is_string($row->permissions)
            ? (json_decode($row->permissions, true) ?? [])
            : ($row->permissions ?? []);

        if (isset($perms['sales-matrix'])) {
            unset($perms['sales-matrix']);
            DB::table('permission_groups')
                ->where('key', 'head')
                ->update([
                    'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }
};
