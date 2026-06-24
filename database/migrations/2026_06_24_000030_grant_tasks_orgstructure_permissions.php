<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Заводим секции `tasks` (/tasks) и `org-structure` (/manage/org-structure)
 * в матрице прав. Раньше эти пункты меню были на флаге staffOnly — видны
 * всем staff, минуя permission-секции, поэтому их нельзя было выдать или
 * отозвать через /manage/permissions.
 *
 * Чтобы сохранить прежнее «видно всем staff», выдаём full всем каноническим
 * staff-ролям. admin не трогаем — он получает full на все секции через
 * config/permissions.php.
 *
 * Идемпотентно: существующий ключ не перезаписываем.
 */
return new class extends Migration {
    private const STAFF_ROLES = [
        'backoffice', 'support', 'head', 'finance',
        'calculations', 'corrections', 'education',
    ];

    private const SECTIONS = ['tasks', 'org-structure'];

    public function up(): void
    {
        if (! Schema::hasTable('permission_groups')) return;

        foreach (self::STAFF_ROLES as $role) {
            $row = DB::table('permission_groups')->where('key', $role)->first();
            if (! $row) continue;

            $perms = $this->decode($row->permissions);
            $changed = false;
            foreach (self::SECTIONS as $section) {
                if (! isset($perms[$section])) {
                    $perms[$section] = 'full';
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('permission_groups')->where('id', $row->id)->update([
                    'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permission_groups')) return;

        foreach (self::STAFF_ROLES as $role) {
            $row = DB::table('permission_groups')->where('key', $role)->first();
            if (! $row) continue;

            $perms = $this->decode($row->permissions);
            $changed = false;
            foreach (self::SECTIONS as $section) {
                if (isset($perms[$section])) {
                    unset($perms[$section]);
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('permission_groups')->where('id', $row->id)->update([
                    'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function decode($permissions): array
    {
        return is_string($permissions)
            ? (json_decode($permissions, true) ?? [])
            : ($permissions ?? []);
    }
};
