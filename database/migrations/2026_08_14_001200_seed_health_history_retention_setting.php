<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Срок хранения истории health-check (health_check_result_history_items).
 *
 * `health:check` пишет результат каждую минуту → таблица выросла до 34 МБ
 * (199 тыс. строк) и была самой толстой служебной в базе. Ретеншн формально
 * жил в `db:housekeep`, но команда зарегистрирована без `--apply` (она умеет
 * дропать таблицы, автозапуск такого не заслуживает доверия), поэтому не
 * чистилось вообще ничего. Прун вынесен отдельной задачей в routes/console.php,
 * а срок вынесен сюда — чтобы правился в админке, а не в коде.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        $now = now();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'maintenance.health_history_retention_days'],
            [
                'value' => '14',
                'type' => 'int',
                'category' => 'maintenance',
                'label' => 'Хранение истории health-check (дней)',
                'description' => 'health:check пишет результат каждую минуту. Старше указанного срока строки удаляются ночью.',
                'sort_order' => 4,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        Cache::forget('system_settings:map');
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->where('key', 'maintenance.health_history_retention_days')
                ->delete();
            Cache::forget('system_settings:map');
        }
    }
};
