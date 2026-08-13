<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Перенос контактов из person в собственные колонки client (email/phone/
 * birthDate/city). Копируем ТОЛЬКО из ВЕРНОЙ person — где ФИО связанной person
 * совпадает с client.personName. Клиенты с чужой/пустой person (хвост 936+236)
 * не трогаем: их контакты недоступны, заполнятся вручную в карточке.
 *
 * Идемпотентно, только заполняет пустые (COALESCE), деньги не затрагивает.
 * --overwrite — перезаписать даже уже заполненные client-контакты.
 */
class BackfillClientContacts extends Command
{
    protected $signature = 'clients:backfill-contacts
        {--dry-run : показать план без изменений}
        {--overwrite : перезаписать уже заполненные контакты client}
        {--by-name : добрать контакты из архивной person по ФИО (для карточек без контактов)}';

    protected $description = 'Перенести контакты из верной person в client (client владеет контактами)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');

        if ($this->option('by-name')) {
            return $this->byName($dry);
        }

        // Кандидаты: живой клиент со связанной person, ФИО которой совпадает.
        $matchJoin = <<<'SQL'
            FROM client cl
            JOIN person p ON p.id = cl.person
            WHERE cl."dateDeleted" IS NULL
              AND cl."personName" IS NOT NULL
              AND btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
                = btrim(lower(cl."personName"))
            SQL;

        $total = (int) (DB::selectOne("SELECT count(*) c $matchJoin")->c ?? 0);
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Клиентов с верной person: {$total}");

        if ($dry) {
            $sample = DB::select("SELECT cl.id, cl.\"personName\", p.email, p.phone $matchJoin ORDER BY cl.id LIMIT 3");
            foreach ($sample as $s) {
                $this->line("  #{$s->id} {$s->personName} — {$s->email} / {$s->phone}");
            }
            return self::SUCCESS;
        }

        // Массовый UPDATE. Без --overwrite заполняем только пустые client-поля
        // (COALESCE(cl.col, p.col)); с --overwrite — берём person как истину.
        $set = $overwrite
            ? 'email = p.email, phone = p.phone, "birthDate" = p."birthDate", city = p.city,
               "nicTG" = p."nicTG", gender = p.gender, "taxResidency" = p."taxResidency"'
            : 'email = COALESCE(cl.email, p.email),
               phone = COALESCE(cl.phone, p.phone),
               "birthDate" = COALESCE(cl."birthDate", p."birthDate"),
               city = COALESCE(cl.city, p.city),
               "nicTG" = COALESCE(cl."nicTG", p."nicTG"),
               gender = COALESCE(cl.gender, p.gender),
               "taxResidency" = COALESCE(cl."taxResidency", p."taxResidency")';

        $affected = DB::update(<<<SQL
            UPDATE client cl
            SET $set, "dateChanged" = now()
            FROM person p
            WHERE p.id = cl.person
              AND cl."dateDeleted" IS NULL
              AND cl."personName" IS NOT NULL
              AND btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
                = btrim(lower(cl."personName"))
            SQL);

        $this->info("Готово. Обновлено клиентов: {$affected}.");
        $this->warn('⚠ Если включена выгрузка в Google Sheets — прогони sheets:export-platform.');

        return self::SUCCESS;
    }

    /**
     * Добор контактов из архивной person по ФИО — для карточек, где своих
     * контактов нет, а связанная person их не дала (указывала на другую
     * запись). Берём ТОЛЬКО однозначное совпадение: и person с таким ФИО, и
     * карточка должны быть единственными, иначе тёзки получат чужой телефон.
     */
    private function byName(bool $dry): int
    {
        $sql = <<<'SQL'
            WITH kandidat AS (
                SELECT cl.id AS client_id,
                       min(p.id) AS person_id
                FROM client cl
                JOIN person p
                  ON btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
                   = btrim(lower(cl."personName"))
                 AND (nullif(btrim(coalesce(p.email,'')),'') IS NOT NULL
                   OR nullif(btrim(coalesce(p.phone,'')),'') IS NOT NULL)
                WHERE cl."dateDeleted" IS NULL
                  AND nullif(btrim(coalesce(cl.email,'')),'') IS NULL
                  AND nullif(btrim(coalesce(cl.phone,'')),'') IS NULL
                GROUP BY cl.id
                HAVING count(DISTINCT p.id) = 1
            )
            SELECT k.client_id, k.person_id
            FROM kandidat k
            WHERE (SELECT count(*) FROM client c2
                    WHERE c2."dateDeleted" IS NULL
                      AND btrim(lower(c2."personName")) = (SELECT btrim(lower("personName")) FROM client WHERE id = k.client_id)) = 1
            SQL;

        $rows = DB::select($sql);
        $this->info('Карточек, которым архив может вернуть контакты: '.count($rows).'.');

        if ($dry || $rows === []) {
            if ($dry) {
                $this->line('Сухой прогон, изменений нет.');
            }

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($rows as $r) {
            $update = <<<'SQL'
                UPDATE client cl
                SET email = nullif(btrim(coalesce(p.email,'')),''),
                    phone = nullif(btrim(coalesce(p.phone,'')),''),
                    "birthDate" = coalesce(cl."birthDate", p."birthDate"),
                    city = coalesce(cl.city, p.city),
                    "dateChanged" = now()
                FROM person p
                WHERE p.id = ? AND cl.id = ?
                SQL;
            $done += DB::update($update, [$r->person_id, $r->client_id]);
        }

        $this->info("Дозаполнено карточек: {$done}.");

        return self::SUCCESS;
    }
}
