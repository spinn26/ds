<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Выделяем 3 standalone-страницы в собственные permission-секции, чтобы
 * доступ к ним можно было выдавать независимо через /manage/permissions.
 * Раньше они были «приклеены» к чужой секции и наследовали её доступ:
 *
 *   bank-changes           ← requisites       (/manage/bank-changes)
 *   homework               ← education        (/manage/homework)
 *   management-currencies  ← owner-dashboard  (/manage/management-currencies)
 *
 * Чтобы никто не потерял доступ, копируем текущий уровень родительской
 * секции в новую для каждой группы, где родитель задан (включая ручные
 * гранты админов). admin не трогаем — он получает full на все секции
 * через config/permissions.php (в БД у него пустой permissions = '{}').
 *
 * Идемпотентно: если новый ключ уже есть — не перезаписываем.
 */
return new class extends Migration {
    /** новая секция => родительская секция, у которой наследуем уровень */
    private const MAP = [
        'bank-changes' => 'requisites',
        'homework' => 'education',
        'management-currencies' => 'owner-dashboard',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permission_groups')) return;

        foreach (DB::table('permission_groups')->get() as $row) {
            $perms = $this->decode($row->permissions);
            $changed = false;

            foreach (self::MAP as $new => $parent) {
                if (isset($perms[$parent]) && ! isset($perms[$new])) {
                    $perms[$new] = $perms[$parent];
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

        foreach (DB::table('permission_groups')->get() as $row) {
            $perms = $this->decode($row->permissions);
            $changed = false;

            foreach (array_keys(self::MAP) as $new) {
                if (isset($perms[$new])) {
                    unset($perms[$new]);
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
