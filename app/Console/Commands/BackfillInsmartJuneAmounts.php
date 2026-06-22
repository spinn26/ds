<?php

namespace App\Console\Commands;

use App\Services\CommissionCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Бэкфилл InSmart-транзакций за ОТКРЫТЫЙ июнь-2026: amount был равен агентской
 * комиссии (КВ), а должен быть страховой премией (= contract.ammount). Чиним:
 *   amount = premium, %ДС = oldAmount / premium × 100 → доход ДС не меняется
 *   (premium × %ДС = oldAmount). Затем пересчёт комиссий калькулятором.
 *
 * Только dateMonth='2026-06' и date >= 2026-06-01 (исторические < 01.06.2026 —
 * неизменны по правилу проекта, не трогаем). --dry-run печатает план без записи.
 */
class BackfillInsmartJuneAmounts extends Command
{
    protected $signature = 'insmart:backfill-june-amounts {--dry-run : показать план без изменений}';

    protected $description = 'InSmart июнь-2026: amount = страховая премия (а не КВ), %ДС сохраняет доход ДС';

    public function handle(CommissionCalculator $calculator): int
    {
        $dry = (bool) $this->option('dry-run');

        $rows = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->where('t.comment', 'ilike', '%insmart%')
            ->whereNull('t.deletedAt')
            ->where('t.dateMonth', '2026-06')
            ->whereRaw('t.date >= ?', ['2026-06-01'])
            ->select(['t.id', 't.amount as old_amount', 'co.ammount as premium', 'co.id as contract_id'])
            ->orderBy('t.id')
            ->get();

        $this->info(($dry ? '[DRY-RUN] ' : '').'InSmart-транзакций к правке (июнь-2026): '.$rows->count());

        $changed = 0;
        $dsBefore = 0.0;
        $dsAfter = 0.0;

        foreach ($rows as $r) {
            $premium = (float) $r->premium;
            $old = (float) $r->old_amount;
            if ($premium <= 0) {
                $this->warn("  tx#{$r->id}: premium<=0 — пропуск");
                continue;
            }
            // Доход ДС до правки (КВ) + баллы партнёров — должны не измениться.
            $before = $this->dsIncome($r->id);
            $bonusBefore = (float) DB::table('commission')->where('transaction', $r->id)
                ->whereNull('deletedAt')->sum('groupBonusRub');
            $dsBefore += $before;

            $pct = round($old / $premium * 100, 4);

            if ($dry) {
                $this->line(sprintf('  tx#%d: amount %s → %s, %%ДС → %s (доходДС было %s)',
                    $r->id, $old, $premium, $pct, $before));
                $changed++;
                continue;
            }

            DB::transaction(function () use ($r, $premium, $pct, $calculator) {
                DB::table('transaction')->where('id', $r->id)->update([
                    'amount' => $premium,
                    'amountRUB' => $premium,
                    'dsCommissionPercentage' => $pct,
                    'changedAt' => now(),
                ]);
                // Пересчёт: снимаем старые комиссии и считаем заново.
                DB::table('commission')->where('transaction', $r->id)->update(['deletedAt' => now()]);
                $calculator->calculateForTransaction($r->id);
            });

            $after = $this->dsIncome($r->id);
            $bonusAfter = (float) DB::table('commission')->where('transaction', $r->id)
                ->whereNull('deletedAt')->sum('groupBonusRub');
            $dsAfter += $after;
            if (abs($after - $before) > 0.5 || abs($bonusAfter - $bonusBefore) > 0.5) {
                $this->warn(sprintf('    ⚠ tx#%d: расхождение! доходДС %s→%s, баллы %s→%s',
                    $r->id, $before, $after, $bonusBefore, $bonusAfter));
            }
            $delta = round($after - $before, 2);
            $this->line(sprintf('  tx#%d: amount→%s %%ДС→%s · доходДС %s→%s (Δ%s)',
                $r->id, $premium, $pct, $before, $after, $delta));
            $changed++;
        }

        if ($dry) {
            $this->info("[DRY-RUN] к правке: {$changed}");
        } else {
            $this->info(sprintf('Готово: %d транзакций. Доход ДС всего: было %s, стало %s, Δ%s',
                $changed, round($dsBefore, 2), round($dsAfter, 2), round($dsAfter - $dsBefore, 2)));
        }

        return self::SUCCESS;
    }

    /** Доход ДС (КВ) по транзакции — из transaction.commissionsAmountRUB. */
    private function dsIncome(int $txId): float
    {
        return (float) (DB::table('transaction')->where('id', $txId)->value('commissionsAmountRUB') ?? 0);
    }
}
