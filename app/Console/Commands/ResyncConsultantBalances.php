<?php

namespace App\Console\Commands;

use App\Services\CommissionCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ресинк снимка `consultantBalance` из `commission`.
 *
 * Реестр выплат читает НАЧИСЛЕНО только из снимка consultantBalance
 * (accruedTransactional/NonTransactional), обновляемого «по кнопке». При
 * пересчёте комиссий rebuildBalancesForTransaction пересобирает снимок ТОЛЬКО
 * по консультантам, оставшимся в цепочке транзакции — тот, кого пересчёт из
 * цепочки убрал (его commission → soft-delete), в снимке остаётся со СТАРЫМ
 * (завышенным) начислением, а новичок, добавленный не через calculate(), может
 * быть занижен. В обоих случаях Реестр расходится с комиссами.
 *
 * Команда для каждой пары (consultant, dateMonth) пересобирает снимок из
 * актуальных commission (та же формула, что rebuildBalance / кнопка пересчёта):
 * accruedTransactional ← SUM(amountRUB WHERE type='transaction') и т.д.
 * Идемпотентно; НЕ трогает payed/accruedPool/balance. Исторические периоды
 * (< HISTORICAL_CUTOFF) защищены внутри rebuildBalance и пропускаются.
 */
class ResyncConsultantBalances extends Command
{
    protected $signature = 'commission:resync-balances
        {--month= : период YYYY-MM (по умолчанию все открытые месяцы >= cutoff)}
        {--dry-run : показать дрейф без записи}';

    protected $description = 'Пересобрать снимок consultantBalance из commission (Реестр выплат = комиссы)';

    public function handle(CommissionCalculator $calculator): int
    {
        $dry = (bool) $this->option('dry-run');
        $cutoffYm = substr(CommissionCalculator::HISTORICAL_CUTOFF, 0, 7); // '2026-06'

        // Целевые месяцы: явный --month или все НЕисторические месяцы,
        // встречающиеся в commission ИЛИ consultantBalance.
        $month = $this->option('month');
        if ($month) {
            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                $this->error('--month должен быть в формате YYYY-MM');
                return self::FAILURE;
            }
            if ($month < $cutoffYm) {
                $this->error("Месяц {$month} исторический (< {$cutoffYm}) — снимок неизменен.");
                return self::FAILURE;
            }
            $months = [$month];
        } else {
            $months = collect(DB::select(
                'SELECT DISTINCT "dateMonth" AS m FROM commission
                   WHERE "deletedAt" IS NULL AND "dateMonth" >= ?
                 UNION
                 SELECT DISTINCT "dateMonth" AS m FROM "consultantBalance"
                   WHERE "dateMonth" >= ?
                 ORDER BY m',
                [$cutoffYm, $cutoffYm]
            ))->pluck('m')->filter()->all();
        }

        if (! $months) {
            $this->info('Открытых месяцев нет — нечего пересобирать.');
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . 'Месяцы к ресинку: ' . implode(', ', $months));

        $totalPairs = 0;
        $totalDrifted = 0;
        $totalDriftAbs = 0.0;

        foreach ($months as $ym) {
            // Все консультанты, у кого в этом месяце есть строки commission ИЛИ
            // строка снимка (последнее — чтобы обнулить завышенный снимок, если
            // commission по нему уже soft-deleted).
            $consultants = collect(DB::select(
                'SELECT DISTINCT consultant FROM commission
                   WHERE "deletedAt" IS NULL AND "dateMonth" = ? AND consultant IS NOT NULL
                 UNION
                 SELECT DISTINCT consultant FROM "consultantBalance"
                   WHERE "dateMonth" = ? AND consultant IS NOT NULL',
                [$ym, $ym]
            ))->pluck('consultant')->map(fn ($v) => (int) $v)->all();

            // Снимок ДО (сумма accruedTransactional+NonTransactional по месяцу).
            $before = (float) DB::table('consultantBalance')
                ->where('dateMonth', $ym)
                ->selectRaw('COALESCE(SUM(COALESCE("accruedTransactional",0)+COALESCE("accruedNonTransactional",0)),0) AS s')
                ->value('s');

            // Live-начисление из commission (что ДОЛЖНО быть в снимке).
            $live = (float) DB::table('commission')
                ->where('dateMonth', $ym)
                ->whereNull('deletedAt')
                ->whereIn('type', ['transaction', 'nonTransactional'])
                ->sum('amountRUB');

            if ($dry) {
                $drift = $live - $before;
                if (abs($drift) > 1) {
                    $totalDrifted++;
                    $totalDriftAbs += abs($drift);
                }
                $this->line(sprintf('  %s: консультантов=%d снимок=%s live=%s дрейф=%s',
                    $ym, count($consultants), $this->fmt($before), $this->fmt($live), $this->fmt($live - $before)));
                $totalPairs += count($consultants);
                continue;
            }

            foreach ($consultants as $cid) {
                $calculator->rebuildBalanceFor($cid, $ym);
            }

            $after = (float) DB::table('consultantBalance')
                ->where('dateMonth', $ym)
                ->selectRaw('COALESCE(SUM(COALESCE("accruedTransactional",0)+COALESCE("accruedNonTransactional",0)),0) AS s')
                ->value('s');

            $delta = $after - $before;
            if (abs($delta) > 1) {
                $totalDrifted++;
                $totalDriftAbs += abs($delta);
            }
            $totalPairs += count($consultants);
            $this->line(sprintf('  %s: пересобрано консультантов=%d снимок %s → %s (Δ %s)',
                $ym, count($consultants), $this->fmt($before), $this->fmt($after), $this->fmt($delta)));
        }

        $this->info(sprintf('%sИтого: пар (consultant×месяц)=%d, месяцев с расхождением=%d, суммарный |Δ|=%s',
            $dry ? '[DRY-RUN] ' : '', $totalPairs, $totalDrifted, $this->fmt($totalDriftAbs)));

        return self::SUCCESS;
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2, '.', ' ');
    }
}
