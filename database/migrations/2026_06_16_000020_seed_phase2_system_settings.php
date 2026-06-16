<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 2 настроек (только админ-консоль /admin/*, НЕфинансовые).
 * Добавляет: порог сдачи теста (%), TTL кэшей (api-настройки, гео),
 * сроки хранения sanctum-токенов и job-батчей. Все читаются с фолбэком.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $now = now();
        $rows = [
            // key, value, type, category, label, description, sort
            ['education.pass_percent', '100', 'int', 'education', 'Порог сдачи теста (%)', 'Минимальный процент верных ответов для зачёта. 100 = нужно всё верно.', 5],

            ['performance.api_settings_cache_ttl', '300', 'int', 'performance', 'TTL кэша интеграций (сек)', 'Как долго кэшируются ключи интеграций (ApiSettings).', 30],
            ['performance.geo_cache_ttl_hours', '24', 'int', 'performance', 'TTL гео-кэша (часы)', 'Кэш геокодинга городов/адресов.', 40],

            ['maintenance.sanctum_token_retention_hours', '24', 'int', 'maintenance', 'Очистка истёкших токенов (часы)', 'Старше — ежедневный prune personal_access_tokens.', 40],
            ['maintenance.job_batch_retention_days', '7', 'int', 'maintenance', 'Хранение job-батчей (дни)', 'Старше — ежедневная очистка завершённых батчей очереди.', 50],
        ];

        foreach ($rows as $r) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $r[0]],
                [
                    'value' => $r[1], 'type' => $r[2], 'category' => $r[3],
                    'label' => $r[4], 'description' => $r[5], 'sort_order' => $r[6],
                    'updated_at' => $now, 'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        DB::table('system_settings')->whereIn('key', [
            'education.pass_percent',
            'performance.api_settings_cache_ttl',
            'performance.geo_cache_ttl_hours',
            'maintenance.sanctum_token_retention_hours',
            'maintenance.job_batch_retention_days',
        ])->delete();
    }
};
