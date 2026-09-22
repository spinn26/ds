<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Сверка снимка `consultantBalance` с живыми `commission` / `poolLog`.
 *
 * Только чтение — ничего не пишет и не чинит. Вынесено из
 * `finance:diagnose-month` отдельным классом, чтобы «что считать
 * расхождением» жило в ОДНОМ месте: по этой формуле и человек смотрит отчёт,
 * и автомат (`finance:autoheal-balances`) решает, чинить ли снимок. Разъедься
 * эти два запроса — сторож чинил бы не то, что видит финансист.
 *
 * Почему снимок вообще расходится — см. докблок ResyncConsultantBalances:
 * реестр выплат читает НЕ живые commission, а снимок, который пересобирается
 * точечно (по цепочке транзакции) и «по кнопке».
 */
class BalanceDriftInspector
{
    /** Ниже этого расхождение в начислениях — округление, а не дрейф. */
    public const ACCRUAL_EPS = 1.0;

    /** Пул кладётся одним числом за месяц, поэтому сверяем до копейки. */
    public const POOL_EPS = 0.01;

    /**
     * Расхождения за месяц, отсортированные по модулю дельты начислений.
     *
     * $onlyConsultant — режим разбора одного партнёра: его строка попадает в
     * выдачу даже без расхождения (иначе «посмотреть, что у него» не работает).
     *
     * @return array{
     *     ym: string,
     *     rows: int,
     *     drifted: list<array{row: \stdClass, accrual: float, pool: float, dups: int}>,
     *     total: float,
     *     poolDrift: int,
     *     dupPartners: int
     * }
     */
    public function inspect(string $ym, ?int $onlyConsultant = null): array
    {
        $from = $ym . '-01';
        $to = date('Y-m-t 23:59:59', strtotime($from));

        $rows = DB::select(<<<'SQL'
            WITH live AS (
                SELECT consultant,
                       COALESCE(SUM(CASE WHEN type = 'transaction' THEN "amountRUB" ELSE 0 END), 0)      AS tx,
                       COALESCE(SUM(CASE WHEN type = 'nonTransactional' THEN "amountRUB" ELSE 0 END), 0) AS nontx,
                       COALESCE(SUM("withheldForGap"), 0)                                                AS gap,
                       COALESCE(SUM("withheldForCommission"), 0)                                         AS op,
                       COUNT(*)                                                                          AS rows_all,
                       COUNT(DISTINCT (COALESCE(transaction, -id), COALESCE("chainOrder", 0)))           AS rows_uniq
                  FROM commission
                 WHERE "dateMonth" = ? AND "deletedAt" IS NULL AND consultant IS NOT NULL
                 GROUP BY consultant
            ), pool AS (
                SELECT consultant, COALESCE(SUM("poolBonus"), 0) AS pool
                  FROM "poolLog"
                 WHERE date BETWEEN ? AND ?
                 GROUP BY consultant
            )
            SELECT COALESCE(b.consultant, live.consultant, pool.consultant) AS consultant,
                   c."personName",
                   COALESCE(b."accruedTransactional", 0) AS snap_tx,
                   COALESCE(b."accruedNonTransactional", 0) AS snap_nontx,
                   COALESCE(b."accruedPool", 0) AS snap_pool,
                   COALESCE(live.tx, 0) AS live_tx,
                   COALESCE(live.nontx, 0) AS live_nontx,
                   COALESCE(pool.pool, 0) AS live_pool,
                   COALESCE(live.gap, 0) AS gap,
                   COALESCE(live.op, 0) AS op,
                   COALESCE(live.rows_all, 0) AS rows_all,
                   COALESCE(live.rows_uniq, 0) AS rows_uniq
              FROM "consultantBalance" b
              FULL JOIN live ON live.consultant = b.consultant AND b."dateMonth" = ?
              FULL JOIN pool ON pool.consultant = COALESCE(b.consultant, live.consultant)
              LEFT JOIN consultant c ON c.id = COALESCE(b.consultant, live.consultant, pool.consultant)
             WHERE b."dateMonth" = ? OR b.id IS NULL
        SQL, [$ym, $from, $to, $ym, $ym]);

        $drifted = [];
        $poolDrift = 0;
        $dupPartners = 0;
        $total = 0.0;

        foreach ($rows as $r) {
            if ($onlyConsultant && (int) $r->consultant !== $onlyConsultant) {
                continue;
            }

            $accrual = ((float) $r->snap_tx + (float) $r->snap_nontx) - ((float) $r->live_tx + (float) $r->live_nontx);
            $pool = (float) $r->snap_pool - (float) $r->live_pool;
            $dups = (int) $r->rows_all - (int) $r->rows_uniq;

            if ($dups > 0) {
                $dupPartners++;
            }
            if (abs($pool) > self::POOL_EPS) {
                $poolDrift++;
            }
            if (abs($accrual) > self::ACCRUAL_EPS || abs($pool) > self::POOL_EPS || $onlyConsultant) {
                $drifted[] = ['row' => $r, 'accrual' => $accrual, 'pool' => $pool, 'dups' => $dups];
                $total += abs($accrual);
            }
        }

        usort($drifted, fn ($a, $b) => abs($b['accrual']) <=> abs($a['accrual']));

        return [
            'ym' => $ym,
            'rows' => count($rows),
            'drifted' => $drifted,
            'total' => $total,
            'poolDrift' => $poolDrift,
            'dupPartners' => $dupPartners,
        ];
    }

    /**
     * Итоги снимка за месяц — «начислено» и «пул» одной строкой.
     * Нужны, чтобы показать пересборку как «было → стало», а не как факт.
     *
     * @return array{accrued: float, pool: float}
     */
    public function snapshotTotals(string $ym): array
    {
        $r = DB::table('consultantBalance')
            ->where('dateMonth', $ym)
            ->selectRaw('COALESCE(SUM(COALESCE("accruedTransactional",0) + COALESCE("accruedNonTransactional",0)),0) AS accrued,
                         COALESCE(SUM(COALESCE("accruedPool",0)),0) AS pool')
            ->first();

        return ['accrued' => (float) ($r->accrued ?? 0), 'pool' => (float) ($r->pool ?? 0)];
    }
}
