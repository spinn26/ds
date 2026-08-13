<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Перенос контактов партнёра в его собственные колонки (consultant.email/
 * phone/birthDate) — шаг вывода person из обращения.
 *
 * Источник по приоритету: WebUser (канон для тех, у кого есть логин), иначе
 * ВЕРНАЯ person — где ФИО связанной person совпадает с consultant.personName.
 * ⚠ Чужие person не трогаем: id разошлись при консолидации Directual, и
 * «валидный, но чужой» указатель тихо подставил бы контакты постороннего —
 * ровно так у клиентов вылезли чужие почты (инцидент 2026-08-12).
 *
 * Идемпотентно, заполняет только пустые. --overwrite перезаписывает.
 */
class BackfillPartnerContacts extends Command
{
    protected $signature = 'partners:backfill-contacts
        {--dry-run : показать план без изменений}
        {--overwrite : перезаписать уже заполненные контакты партнёра}';

    protected $description = 'Перенести контакты партнёра из WebUser/верной person в consultant';

    /** Контакты каждого живого партнёра из доступных источников. */
    private const SOURCE_SQL = <<<'SQL'
        SELECT c2.id,
               coalesce(nullif(btrim(w.email), ''), nullif(btrim(p.email), '')) AS email,
               coalesce(nullif(btrim(w.phone), ''), nullif(btrim(p.phone), '')) AS phone,
               -- ДР приводим к Y-m-d: у WebUser это timestamp, у person —
               -- ISO-строка со смещением, и «сырой» перенос сдвигал бы дату.
               coalesce(to_char(w."birthDate", 'YYYY-MM-DD'), left(p."birthDate", 10)) AS bd
        FROM consultant c2
        LEFT JOIN "WebUser" w ON w.id = c2."webUser"
        LEFT JOIN person p ON p.id = c2.person
             AND btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
               = btrim(lower(c2."personName"))
        WHERE c2."dateDeleted" IS NULL
        SQL;

    /** Есть что переносить. */
    private const HAS_DATA = '(src.email IS NOT NULL OR src.phone IS NOT NULL OR src.bd IS NOT NULL)';

    public function handle(): int
    {
        $source = self::SOURCE_SQL;

        $total = (int) (DB::selectOne(
            "SELECT count(*) AS cnt FROM ({$source}) src WHERE ".self::HAS_DATA
        )->cnt ?? 0);
        $this->info("Партнёров с доступными контактами: {$total}.");

        if ($this->option('dry-run')) {
            $this->line('Сухой прогон, изменений нет.');

            return self::SUCCESS;
        }

        $assign = $this->option('overwrite')
            ? 'email = src.email, phone = src.phone, "birthDate" = src.bd'
            : 'email = coalesce(nullif(btrim(c.email), \'\'), src.email), '
                .'phone = coalesce(nullif(btrim(c.phone), \'\'), src.phone), '
                .'"birthDate" = coalesce(c."birthDate", src.bd)';

        $updated = DB::update(
            "UPDATE consultant AS c SET {$assign} FROM ({$source}) src ".
            'WHERE src.id = c.id AND '.self::HAS_DATA
        );

        $this->info("Обновлено партнёров: {$updated}.");

        return self::SUCCESS;
    }
}
