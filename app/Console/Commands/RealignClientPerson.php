<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Выравнивание client.person: у большинства клиентов FK client.person указывает
 * на ЧУЖУЮ person-запись (имя карточки personName верное, а контакты — почта/
 * телефон/ДР/город — из другого человека). Причина — переномерация person при
 * консолидации Directual без обновления client.person.
 *
 * Фикс: для клиента, у которого текущая person не совпадает по ФИО (или person
 * пустой) И существует РОВНО ОДНА person с ФИО = personName — перепривязываем
 * client.person на неё (у неё верные контакты). Неоднозначные (2+ совпадения) и
 * без совпадений не трогаем. Обратимо, деньги не затрагивает.
 *
 * --dry-run — показать план (counts) без изменений.
 */
class RealignClientPerson extends Command
{
    protected $signature = 'clients:realign-person
        {--dry-run : показать план без изменений}
        {--with-duplicates : брать и случаи с несколькими кандидатами по ФИО, если все они — ДУБЛИ одного человека}';

    protected $description = 'Выровнять client.person по ФИО (контакты клиентов сейчас чужие)';

    /**
     * Кандидаты: живые клиенты, где person=NULL или ФИО person != personName,
     * и существует РОВНО ОДНА person с таким ФИО. new_person — её id.
     */
    private const CANDIDATES_SQL = <<<'SQL'
        WITH matched AS (
            SELECT cl.id AS client_id, cl.person AS cur_person,
                   min(p.id) AS new_person, count(*) AS cnt
            FROM client cl
            JOIN person p
              ON btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
               = btrim(lower(cl."personName"))
            WHERE cl."dateDeleted" IS NULL AND cl."personName" IS NOT NULL
            GROUP BY cl.id, cl.person
            HAVING count(*) = 1
        )
        SELECT m.client_id, m.cur_person, m.new_person
        FROM matched m
        LEFT JOIN person cp ON cp.id = m.cur_person
        WHERE m.cur_person IS NULL
           OR m.cur_person <> m.new_person
        SQL;

    /**
     * Расширенный случай (--with-duplicates): по ФИО нашлось НЕСКОЛЬКО person,
     * но все они — дубли одного человека, то есть сходятся по почте и телефону
     * (после нормализации: регистр, пробелы, только цифры в номере). Тогда
     * выбор между ними не влияет на контакты клиента.
     *
     * Тёзок (разные почты/телефоны) НЕ трогаем — там нужен человек.
     * Из дублей берём «самого заполненного»: сначала с почтой, потом с
     * телефоном, при равенстве — меньший id (он же старший, на него обычно
     * ссылается партнёрская запись).
     */
    private const DUPLICATE_CANDIDATES_SQL = <<<'SQL'
        WITH cand AS (
            SELECT cl.id AS client_id, cl.person AS cur_person, p.id AS person_id,
                   lower(btrim(coalesce(p.email,''))) AS norm_email,
                   regexp_replace(coalesce(p.phone,''), '\D', '', 'g') AS norm_phone,
                   (nullif(btrim(coalesce(p.email,'')),'') IS NOT NULL)::int AS has_email,
                   (nullif(btrim(coalesce(p.phone,'')),'') IS NOT NULL)::int AS has_phone
            FROM client cl
            JOIN person p
              ON btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
               = btrim(lower(cl."personName"))
            WHERE cl."dateDeleted" IS NULL AND cl."personName" IS NOT NULL
        ), grouped AS (
            SELECT client_id, cur_person,
                   count(*) AS cnt,
                   count(DISTINCT nullif(norm_email,'')) AS emails,
                   count(DISTINCT nullif(norm_phone,'')) AS phones
            FROM cand GROUP BY client_id, cur_person
        ), same_person AS (
            SELECT client_id, cur_person FROM grouped
            WHERE cnt > 1 AND emails <= 1 AND phones <= 1
        ), best AS (
            SELECT DISTINCT ON (c.client_id) c.client_id, c.cur_person, c.person_id AS new_person
            FROM cand c JOIN same_person s ON s.client_id = c.client_id
            ORDER BY c.client_id, c.has_email DESC, c.has_phone DESC, c.person_id
        )
        SELECT client_id, cur_person, new_person FROM best
        WHERE cur_person IS NULL OR cur_person <> new_person
        SQL;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $withDuplicates = (bool) $this->option('with-duplicates');

        $rows = DB::select(self::CANDIDATES_SQL);
        $this->info(($dry ? '[DRY-RUN] ' : '') . 'Клиентов к перепривязке (ровно 1 совпадение по ФИО): ' . count($rows));

        $dupRows = [];
        if ($withDuplicates) {
            $dupRows = DB::select(self::DUPLICATE_CANDIDATES_SQL);
            $this->info('  + несколько кандидатов, но все — дубли одного человека: ' . count($dupRows));
        }

        // Диагностика по остальным.
        $stats = DB::selectOne(<<<'SQL'
            SELECT
              count(*) FILTER (WHERE mc = 0) AS no_match,
              count(*) FILTER (WHERE mc > 1) AS ambiguous
            FROM (
              SELECT cl.id, (SELECT count(*) FROM person p2
                WHERE btrim(lower(p2."lastName"||' '||p2."firstName"||' '||coalesce(p2.patronymic,'')))
                    = btrim(lower(cl."personName"))) AS mc
              FROM client cl WHERE cl."dateDeleted" IS NULL AND cl."personName" IS NOT NULL
            ) x
            SQL);
        $this->line("  не трогаю: без совпадения ФИО — {$stats->no_match}, неоднозначных (2+) — {$stats->ambiguous}");

        if ($withDuplicates) {
            $this->line('  из «неоднозначных» останутся нетронутыми только настоящие тёзки (разные почта/телефон).');
        }

        if ($dry || (! $rows && ! $dupRows)) {
            return self::SUCCESS;
        }

        // Массовый UPDATE одним запросом (быстрее итерации по 7k строк).
        $affected = DB::update(<<<'SQL'
            UPDATE client cl
            SET person = m.new_person, "dateChanged" = now()
            FROM (
                WITH matched AS (
                    SELECT cl2.id AS client_id, cl2.person AS cur_person,
                           min(p.id) AS new_person, count(*) AS cnt
                    FROM client cl2
                    JOIN person p
                      ON btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
                       = btrim(lower(cl2."personName"))
                    WHERE cl2."dateDeleted" IS NULL AND cl2."personName" IS NOT NULL
                    GROUP BY cl2.id, cl2.person
                    HAVING count(*) = 1
                )
                SELECT client_id, new_person FROM matched
                WHERE cur_person IS NULL OR cur_person <> new_person
            ) m
            WHERE cl.id = m.client_id
            SQL);

        // Дубли — точечным UPDATE по посчитанному плану: пересчитывать тот же
        // тяжёлый CTE второй раз незачем, а список уже на руках.
        $affectedDup = 0;
        foreach ($dupRows as $r) {
            $affectedDup += DB::table('client')->where('id', $r->client_id)
                ->update(['person' => $r->new_person, 'dateChanged' => now()]);
        }
        if ($withDuplicates) {
            $this->info("Перепривязано по дублям одного человека: {$affectedDup}.");
        }

        $this->info("Готово. Перепривязано клиентов: {$affected}.");
        $this->warn('⚠ Если выгрузка в Google Sheets включена — прогони sheets:export-platform для обновления контактов.');

        return self::SUCCESS;
    }
}
