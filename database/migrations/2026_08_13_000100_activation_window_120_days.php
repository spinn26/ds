<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Окно активации: 90 → 120 дней (решение владельца, 13.08.2026).
 *
 * Партнёру в статусе «Зарегистрирован» даётся 120 дней с момента регистрации
 * на набор 500 ЛП. Действующим зарегистрированным дедлайн продлевается.
 *
 * ⚠ Продление ТОЛЬКО вперёд (GREATEST): дедлайн могли продлить руками. Прямой
 * пересчёт «дата регистрации + 120» сократил бы срок таким партнёрам, а тем,
 * кто зарегистрирован больше 120 дней назад, поставил бы дату в прошлом — и
 * ночной крон терминировал бы их (у Панфиловой 1614, зарегистрирована
 * 11.06.2025, срок стоял до 09.11.2026).
 * ⚠ SystemSetting кэширует всю карту через rememberForever — правка напрямую в
 * таблице без сброса кэша не видна приложению.
 */
return new class extends Migration
{
    private const OLD_DAYS = 90;

    private const NEW_DAYS = 120;

    public function up(): void
    {
        DB::table('system_settings')
            ->where('key', 'activation.window_days')
            ->update(['value' => (string) self::NEW_DAYS, 'updated_at' => now()]);

        DB::statement(
            'UPDATE consultant
                SET "activationDeadline" = GREATEST("activationDeadline",
                        "dateCreated" + make_interval(days => ?))
              WHERE "dateDeleted" IS NULL
                AND activity = 4
                AND "dateCreated" IS NOT NULL',
            [self::NEW_DAYS]
        );

        Cache::forget('system_settings:map');
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'activation.window_days')
            ->update(['value' => (string) self::OLD_DAYS, 'updated_at' => now()]);

        // Дедлайны назад не откатываем: продление уже могло быть учтено в
        // уведомлениях партнёрам, а сокращение срока = терминация.
        Cache::forget('system_settings:map');
    }
};
