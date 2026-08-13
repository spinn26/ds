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
               coalesce(to_char(w."birthDate", 'YYYY-MM-DD'), left(p."birthDate", 10)) AS bd,
               -- ⚠ У WebUser city и taxResidency — ЧИСЛОВЫЕ id справочников,
               -- у person те же поля текстовые. Приводим к тексту: колонки
               -- партнёра строковые, как у client.city (там уже лежат и
               -- названия из формы, и legacy-коды).
               coalesce(nullif(btrim(w.city::text), ''), nullif(btrim(p.city), ''))               AS city,
               coalesce(nullif(btrim(w."nicTG"), ''), nullif(btrim(p."nicTG"), ''))               AS nictg,
               coalesce(nullif(btrim(w.gender), ''), nullif(btrim(p.gender), ''))                 AS gender,
               coalesce(nullif(btrim(w."taxResidency"::text), ''), nullif(btrim(p."taxResidency"), '')) AS tax
        FROM consultant c2
        LEFT JOIN "WebUser" w ON w.id = c2."webUser"
        LEFT JOIN person p ON p.id = c2.person
             AND btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
               = btrim(lower(c2."personName"))
        WHERE c2."dateDeleted" IS NULL
        SQL;

    /** Есть что переносить. */
    private const HAS_DATA = '(src.email IS NOT NULL OR src.phone IS NOT NULL OR src.bd IS NOT NULL
        OR src.city IS NOT NULL OR src.nictg IS NOT NULL OR src.gender IS NOT NULL OR src.tax IS NOT NULL)';

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
            ? 'email = src.email, phone = src.phone, "birthDate" = src.bd, city = src.city, '
                .'"nicTG" = src.nictg, gender = src.gender, "taxResidency" = src.tax'
            : 'email = coalesce(nullif(btrim(c.email), \'\'), src.email), '
                .'phone = coalesce(nullif(btrim(c.phone), \'\'), src.phone), '
                .'"birthDate" = coalesce(c."birthDate", src.bd), '
                .'city = coalesce(nullif(btrim(c.city), \'\'), src.city), '
                .'"nicTG" = coalesce(nullif(btrim(c."nicTG"), \'\'), src.nictg), '
                .'gender = coalesce(nullif(btrim(c.gender), \'\'), src.gender), '
                .'"taxResidency" = coalesce(nullif(btrim(c."taxResidency"), \'\'), src.tax)';

        $updated = DB::update(
            "UPDATE consultant AS c SET {$assign} FROM ({$source}) src ".
            'WHERE src.id = c.id AND '.self::HAS_DATA
        );

        $this->info("Обновлено партнёров: {$updated}.");

        return self::SUCCESS;
    }
}
