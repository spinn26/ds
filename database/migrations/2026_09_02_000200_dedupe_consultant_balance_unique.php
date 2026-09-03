<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Дедупликация `consultantBalance` + уникальный индекс (consultant, dateMonth).
 *
 * ⚠ Найдено 02.09.2026 при сплошной проверке переноса остатка.
 * `consultantBalance` — это агрегат ОДНОЙ пары (consultant, dateMonth), но
 * ограничения, которое бы это гарантировало, никогда не было. В базе нашлись
 * четыре пары-двойника:
 *
 *   c=388  2026-06  ids 39928, 40364   создано 01.06 в 03:02:47 и 03:02:54
 *   c=1303 2026-06  ids 38842, 39965   создано 01.06 в 03:02:36 и 03:02:48
 *   c=1354 2026-06  ids 40296, 40501   создано 01.06 в 03:02:53 и 03:02:58
 *   c=1548 2025-03  ids 9072, 13900    01.03.2025 и пересчёт 12.04.2025
 *
 * Первые три — гонка месячной задачи: она обработала партнёра дважды за один
 * прогон, с разницей в секунды (у qualificationLog там же по две строки).
 *
 * ЧЕМ ОПАСНО. Денежные суммы в парах совпадают, поэтому сверки расхождения не
 * видели. Но код читает строку через `->first()` БЕЗ сортировки
 * (CommissionCalculator::rebuildBalance и ::applyPoolToBalance): при дубле
 * пересчёт обновляет случайную строку из пары, а вторая остаётся протухшей.
 * Дальше `incomingBalance` берёт `ORDER BY "dateMonth" DESC LIMIT 1` — и с
 * равной вероятностью подхватывает устаревшую. То есть дубль превращается в
 * неверный перенос ровно в тот момент, когда суммы в паре разойдутся.
 * Плюс любой отчёт, суммирующий строки, задваивает месяц.
 *
 * ЧТО ДЕЛАЕТ МИГРАЦИЯ. Оставляет строку с наибольшим id (последняя запись —
 * та, которую с наибольшей вероятностью трогал последний пересчёт; она же в
 * паре 1548 единственная, на которую есть ссылка). Остальные:
 *   1) копирует целиком в `consultantBalance_dedup_20260902`;
 *   2) переводит на оставшуюся строку все восемь внешних ключей;
 *   3) удаляет;
 *   4) вешает уникальный индекс, чтобы двойники не появились снова.
 *
 * Пара 1548 лежит в замороженной истории Directual (до HISTORICAL_CUTOFF).
 * Удаление там осознанное и денег не меняет: `remaining` в паре одинаков,
 * выплат по строкам нет, удаляется более ранняя и менее заполненная строка.
 * Иначе уникальный индекс на историю не натянуть, а дубль продолжит отравлять
 * входящее сальдо апреля 2025.
 *
 * ВОССТАНОВЛЕНИЕ. Удалённые строки целиком лежат в
 * `consultantBalance_dedup_20260902` — таблицу не удалять, как и
 * `consultant_activation_backfill_20260602`.
 */
return new class extends Migration
{
    /**
     * Двойники и строка-победитель в каждой группе.
     * Победитель — максимальный id внутри (consultant, dateMonth).
     */
    private const DUPLICATES = <<<'SQL'
        SELECT id AS loser_id, keeper_id
          FROM (
            SELECT id,
                   max(id) OVER (PARTITION BY consultant, "dateMonth")   AS keeper_id,
                   count(*) OVER (PARTITION BY consultant, "dateMonth")  AS n
              FROM "consultantBalance"
             WHERE consultant IS NOT NULL
          ) d
         WHERE n > 1 AND id <> keeper_id
        SQL;

    /**
     * Все внешние ключи на consultantBalance: четыре живых и четыре в legacy.
     * Ссылки переводим на оставшуюся строку — иначе DELETE упрётся в FK.
     *
     * @var list<array{0: string, 1: string}> [квалифицированная таблица, колонка]
     */
    private const REFERENCES = [
        ['public."consultantPayment"', 'consultantBalance'],
        ['public.documentlogs', 'consultantBalance'],
        ['public.documentlogs', 'consultantBalance2'],
        ['public."reportGenerator"', 'consultantBalances'],
        ['legacy."firstBalances"', 'balance'],
        ['legacy."massTransactionRecalculationTrigger"', 'consultantBalances'],
        ['legacy."partnerMonthlyPaymentsReportTrigger"', 'consultantBalance'],
        ['legacy."unactualBalances"', 'consultantBalance'],
    ];

    public function up(): void
    {
        $dup = self::DUPLICATES;

        // 1. Архив ДО удаления: таблица создаётся сразу с содержимым.
        //    IF NOT EXISTS — чтобы повторный прогон не затёр архив пустотой.
        DB::statement(<<<SQL
            CREATE TABLE IF NOT EXISTS "consultantBalance_dedup_20260902" AS
            SELECT b.* FROM "consultantBalance" b JOIN ({$dup}) m ON m.loser_id = b.id
        SQL);

        // 2. Перевод ссылок на оставшуюся строку. Схема legacy в тестовой базе
        //    поднимается из дампа и может отсутствовать — проверяем наличие.
        foreach (self::REFERENCES as [$table, $column]) {
            if (DB::selectOne('SELECT to_regclass(?) AS r', [$table])?->r === null) {
                continue;
            }

            DB::statement(<<<SQL
                UPDATE {$table} t
                   SET "{$column}" = m.keeper_id
                  FROM ({$dup}) m
                 WHERE t."{$column}" = m.loser_id
            SQL);
        }

        // 3. Удаление двойников.
        DB::statement(<<<SQL
            DELETE FROM "consultantBalance" b USING ({$dup}) m WHERE b.id = m.loser_id
        SQL);

        // 4. Ограничение, которого не хватало. Партиальный по consultant IS NOT
        //    NULL: семь строк без партнёра — мусор Directual, они уникальному
        //    индексу не мешают (NULL в Postgres не равен NULL), но исключаем их
        //    явно, чтобы намерение читалось.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS consultantbalance_consultant_month_uniq
            ON "consultantBalance" (consultant, "dateMonth")
            WHERE consultant IS NOT NULL');
    }

    public function down(): void
    {
        // Снимаем только индекс. Удалённые строки не возвращаем автоматически:
        // ссылки уже переведены на оставшиеся, и восстановление двойников
        // сломало бы этот же индекс при повторном накате. Исходные строки —
        // в "consultantBalance_dedup_20260902".
        DB::statement('DROP INDEX IF EXISTS consultantbalance_consultant_month_uniq');
    }
};
