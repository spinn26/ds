<?php

namespace App\Console\Commands;

use App\Services\CommissionCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Пересчёт июньских транзакций после фиксов расчёта комиссий (2026-07-08):
 *   - A2 «своя комиссия»: калькулятор теперь выводит %ДС из dsCommissionAbsolute,
 *     а не падает на тариф/100% (Брокер+ был посчитан по 100%).
 *   - A5 «неизвестный ФК»: пишем Доход ДС/прибыль (раньше пусто).
 *
 * Пересчитываем ТОЛЬКО затронутые июньские транзакции (customCommission=true
 * ИЛИ consultant = UNKNOWN_CONSULTANT_ID), открытый период (>=2026-06-01).
 * Для каждой: soft-delete старых commission + calculateForTransaction (заново
 * создаёт цепочку + обновляет consultantBalance). --dry-run печатает before/after
 * без изменений. Идемпотентна.
 *
 * ⚠ После прогона пересчитать июньскую финализацию квалификаций/пула
 * (ЛП/ГП снапшоты аггрегируют эти транзакции) — отдельными кнопками/командами.
 */
class RecomputeJuneCommissionFixes extends Command
{
    protected $signature = 'commission:recompute-june-fixes
        {--dry-run : показать before/after без изменений}
        {--all : пересчитать ВСЕ живые транзакции периода (а не только своя-комиссия/неизв-ФК)}
        {--ids= : пересчитать только перечисленные id транзакций (через запятую)}
        {--stale-lp : пересчитать только транзакции, где personalVolume разошёлся с доходом ДС без НДС / 100}
        {--from=2026-06-01 : начало периода (date >=)}
        {--to=2026-07-01 : конец периода (date <, не включая)}';

    protected $description = 'Пересчёт июньских транзакций после фиксов «своя комиссия» / «неизвестный ФК»';

    public function handle(CommissionCalculator $calculator): int
    {
        $dry = (bool) $this->option('dry-run');
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        $all = (bool) $this->option('all');
        $onlyIds = array_values(array_filter(array_map(
            'intval',
            preg_split('/\s*,\s*/', (string) $this->option('ids'), -1, PREG_SPLIT_NO_EMPTY) ?: []
        )));
        $staleLp = (bool) $this->option('stale-lp');

        $q = DB::table('transaction as t')
            ->join('contract as c', 'c.id', '=', 't.contract')
            ->whereNull('t.deletedAt')
            ->where('t.date', '>=', $from)
            ->where('t.date', '<', $to);
        if ($onlyIds) {
            $q->whereIn('t.id', $onlyIds);
        } elseif ($staleLp) {
            // ЛП должен равняться «доход ДС без НДС / 100» (спека ✅Калькулятор
            // объёмов). Расхождение = значение из старого импорта, а не из
            // калькулятора — такие транзакции и пересчитываем.
            $q->whereNotNull('t.commissionsAmountRUB')
              ->whereRaw('ABS(COALESCE(t."personalVolume", 0) - t."commissionsAmountRUB" / 100) > 0.02');
        } elseif (! $all) {
            $q->where(function ($w) {
                $w->where('t.customCommission', true)
                  ->orWhere('c.consultant', CommissionCalculator::UNKNOWN_CONSULTANT_ID);
            });
        }
        $ids = $q->orderBy('t.id')->pluck('t.id')->all();

        $this->info(($dry ? '[DRY-RUN] ' : '')."Транзакций к пересчёту ({$from}..{$to}): ".count($ids));
        if (! $ids) {
            return self::SUCCESS;
        }

        $beforeInc = (float) DB::table('transaction')->whereIn('id', $ids)->sum('commissionsAmountRUB');
        $beforeChain = (float) DB::table('commission')->whereIn('transaction', $ids)->whereNull('deletedAt')->sum('groupBonusRub');

        if ($dry) {
            // В dry-run считаем ожидаемый Доход ДС по формуле без записи.
            $this->line('  (dry-run: пересчёт не выполняется; текущие суммы ниже)');
            $this->info(sprintf('Доход ДС сейчас: %s; комиссии цепочке сейчас: %s',
                round($beforeInc, 2), round($beforeChain, 2)));
            return self::SUCCESS;
        }

        $done = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $calculator) {
                DB::table('commission')->where('transaction', $id)->update(['deletedAt' => now()]);
                $calculator->calculateForTransaction($id);
            });
            $done++;
        }

        $afterInc = (float) DB::table('transaction')->whereIn('id', $ids)->sum('commissionsAmountRUB');
        $afterChain = (float) DB::table('commission')->whereIn('transaction', $ids)->whereNull('deletedAt')->sum('groupBonusRub');

        $this->info(sprintf('Готово: пересчитано %d. Доход ДС %s → %s (Δ %s); комиссии цепочке %s → %s (Δ %s).',
            $done,
            round($beforeInc, 2), round($afterInc, 2), round($afterInc - $beforeInc, 2),
            round($beforeChain, 2), round($afterChain, 2), round($afterChain - $beforeChain, 2),
        ));
        $this->warn('⚠ Пересчитайте июньскую финализацию квалификаций/пула — ЛП/ГП снапшоты изменились.');

        return self::SUCCESS;
    }
}
