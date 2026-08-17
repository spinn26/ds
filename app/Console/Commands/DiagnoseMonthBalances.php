<?php

namespace App\Console\Commands;

use App\Services\CommissionCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only разбор месяца: почему «Итого начислено» расходится с продажами,
 * а «Пул» в реестре нулевой.
 *
 * Печатает по каждому партнёру с расхождением:
 *   снимок consultantBalance vs live SUM(commission) — начисления;
 *   accruedPool vs SUM(poolLog) — пул;
 *   сумму удержаний месяца (отрыв/ОП) и число дублей commission.
 *
 *   php artisan finance:diagnose-month 2026-07
 *   php artisan finance:diagnose-month 2026-07 --consultant=256
 */
class DiagnoseMonthBalances extends Command
{
    protected $signature = 'finance:diagnose-month
        {ym : период YYYY-MM}
        {--consultant= : только этот consultant.id}
        {--limit=25 : сколько строк расхождений печатать}';

    protected $description = 'Показать расхождения снимка consultantBalance с commission/poolLog за месяц (только чтение)';

    public function handle(): int
    {
        $ym = (string) $this->argument('ym');
        if (! preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $this->error('Период должен быть в формате YYYY-MM');

            return self::FAILURE;
        }
        if (CommissionCalculator::isHistorical($ym)) {
            $this->warn("Период {$ym} исторический (< " . CommissionCalculator::HISTORICAL_CUTOFF . ') — снимок неизменен по правилам расчёта.');
        }

        $only = $this->option('consultant') ? (int) $this->option('consultant') : null;
        $limit = (int) $this->option('limit');
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
        foreach ($rows as $r) {
            if ($only && (int) $r->consultant !== $only) {
                continue;
            }
            $dAccr = ((float) $r->snap_tx + (float) $r->snap_nontx) - ((float) $r->live_tx + (float) $r->live_nontx);
            $dPool = (float) $r->snap_pool - (float) $r->live_pool;
            $dups = (int) $r->rows_all - (int) $r->rows_uniq;
            if ($dups > 0) {
                $dupPartners++;
            }
            if (abs($dPool) > 0.01) {
                $poolDrift++;
            }
            if (abs($dAccr) > 1 || abs($dPool) > 0.01 || $only) {
                $drifted[] = [$r, $dAccr, $dPool, $dups];
            }
        }

        usort($drifted, fn ($a, $b) => abs($b[1]) <=> abs($a[1]));

        $this->info("Период {$ym}: строк — " . count($rows) . ', с расхождением — ' . count($drifted)
            . ", пул разошёлся у {$poolDrift}, дубли commission у {$dupPartners}");
        $this->table(
            ['ID', 'Партнёр', 'снимок', 'live', 'Δ начисл.', 'пул снимок', 'пул log', 'удержано', 'дубли'],
            array_map(fn ($d) => [
                $d[0]->consultant,
                mb_substr((string) ($d[0]->personName ?? '—'), 0, 28),
                $this->n((float) $d[0]->snap_tx + (float) $d[0]->snap_nontx),
                $this->n((float) $d[0]->live_tx + (float) $d[0]->live_nontx),
                $this->n($d[1]),
                $this->n((float) $d[0]->snap_pool),
                $this->n((float) $d[0]->live_pool),
                $this->n((float) $d[0]->gap + (float) $d[0]->op),
                $d[3] > 0 ? (string) $d[3] : '',
            ], array_slice($drifted, 0, $limit))
        );

        if ($drifted) {
            $this->newLine();
            $this->comment('Δ начисл. > 0 — снимок выше строк commission (в отчёте «Итого начислено» без удержаний).');
            $this->comment("Починка: php artisan commission:resync-balances --month={$ym}");
        }

        return self::SUCCESS;
    }

    private function n(float $v): string
    {
        return number_format($v, 2, '.', ' ');
    }
}
