<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Группа прав «Инвест департамент» (invest) в сетке «Группы и права».
 * Только просмотр всех разделов кабинета (view), кроме управления правами
 * (это админ-функция). Уровни далее редактируются в UI /manage/permissions.
 * Роль recognises как staff (User::isStaff), запись блокирует RestrictInvestWrites.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permission_groups')) {
            return;
        }

        $perms = [];
        foreach (config('permissions.sections', []) as $s) {
            if (($s['key'] ?? null) === 'permissions') {
                continue; // управление группами и правами — админ-функция
            }
            $perms[$s['key']] = 'view';
        }

        $now = now();
        DB::table('permission_groups')->updateOrInsert(
            ['key' => 'invest'],
            [
                'name' => 'Инвест департамент',
                'description' => 'Только просмотр всех разделов кабинета (read-only), без админ-панели.',
                'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                'is_system' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_groups')) {
            DB::table('permission_groups')->where('key', 'invest')->delete();
        }
    }
};
