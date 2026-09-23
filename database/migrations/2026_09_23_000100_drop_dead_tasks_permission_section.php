<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Убираем мёртвую секцию `tasks` из матрицы «Группы и права».
 *
 * Модуль «Задачи и проекты» удалён 14.08.2026 вместе с маршрутами и крон-
 * задачами; из config/permissions.php колонку тогда сняли, а в данных
 * permission_groups ключ остался — у семи ролей там висит «Полный».
 *
 * Сам по себе он безвреден (маршрутов нет), но мешает разбирать матрицу:
 * при сверке «кому что выдано» приходится каждый раз вспоминать, что
 * `tasks=full` у всех — это не выданное право, а след миграции
 * 2026_06_24_000030, которая проставляла full ради видимости пункта меню.
 *
 * Уровни других секций не трогаем.
 */
return new class extends Migration
{
    private const DEAD_SECTION = 'tasks';

    public function up(): void
    {
        if (! Schema::hasTable('permission_groups')) {
            return;
        }

        foreach (DB::table('permission_groups')->get() as $group) {
            $perms = $this->decode($group->permissions);
            if (! array_key_exists(self::DEAD_SECTION, $perms)) {
                continue;
            }

            unset($perms[self::DEAD_SECTION]);

            DB::table('permission_groups')
                ->where('id', $group->id)
                ->update([
                    'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Откат ничего не восстанавливает: секции нет ни в конфиге, ни в
     * маршрутах, и вернуть «Полный» на несуществующий раздел — значит
     * вернуть ровно ту путаницу, ради которой миграция и написана.
     */
    public function down(): void
    {
        // no-op
    }

    /** @return array<string, string> */
    private function decode(mixed $raw): array
    {
        if (is_string($raw)) {
            return json_decode($raw, true) ?? [];
        }

        return is_array($raw) ? $raw : [];
    }
};
