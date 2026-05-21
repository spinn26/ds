<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Грантим роли «education» (отдел обучения — Жосан, Вдовина, Проваторова)
 * полный доступ к новой секции education-categories. Без этого статика
 * cabinetPermissions.js не помогает — permission resolver всегда смотрит
 * сначала в permission_groups (БД), и отсутствие ключа = нет прав.
 *
 * Идемпотентно: если ключ уже есть — пропускаем (для случая повторной
 * накатки на dev/локалке).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('permission_groups')) return;

        $row = DB::table('permission_groups')->where('key', 'education')->first();
        if (! $row) return;

        $perms = is_string($row->permissions)
            ? (json_decode($row->permissions, true) ?? [])
            : ($row->permissions ?? []);

        if (! isset($perms['education-categories'])) {
            $perms['education-categories'] = 'full';
            DB::table('permission_groups')
                ->where('key', 'education')
                ->update([
                    'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permission_groups')) return;

        $row = DB::table('permission_groups')->where('key', 'education')->first();
        if (! $row) return;

        $perms = is_string($row->permissions)
            ? (json_decode($row->permissions, true) ?? [])
            : ($row->permissions ?? []);

        if (isset($perms['education-categories'])) {
            unset($perms['education-categories']);
            DB::table('permission_groups')
                ->where('key', 'education')
                ->update([
                    'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }
};
