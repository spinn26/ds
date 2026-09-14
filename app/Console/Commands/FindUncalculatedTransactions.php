<?php

namespace App\Console\Commands;

use App\Services\CommissionCalculator;
use App\Services\PeriodFreezeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Транзакции открытых периодов, по которым не посчитаны комиссии.
 *
 * ПРОБЛЕМА. Доход ДС без НДС (`commissionsAmountRUB`) пишет только расчёт
 * комиссий. Если расчёт не прошёл — сделка пришла в закрытый месяц, партнёр
 * удалён, нет ставки НДС, после импорта не нажали «Рассчитать» — поле остаётся
 * пустым. Цепочке ничего не начислено, в отчёте «Комиссии» доход ДС = 0.
 *
 * А пул такую сделку видит: PoolRunner::monthlyVatExclusiveRevenue подставляет
 * оценку amountRUB × %ДС / 105. Так за август 2026 отчёт и пул разошлись на
 * 740 ₽ — ОСАГО из Инсмарта (договор 83672898), по которому интеграция не
 * сообщила, что расчёт не прошёл.
 *
 * По умолчанию только ЧИТАЕТ. С --calculate запускает обычный расчёт
 * (CommissionCalculator::calculateForTransaction) — ровно то, что должно было
 * случиться при создании сделки, — и печатает причину, если он снова не прошёл.
 * Историю (< HISTORICAL_CUTOFF) не ищет, закрытые месяцы пропускает.
 *
 *   php artisan finance:uncalculated-transactions
 *   php artisan finance:uncalculated-transactions --month=2026-08
 *   php artisan finance:uncalculated-transactions --month=2026-08 --calculate
 */
class FindUncalculatedTransactions extends Command
{
    protected $signature = 'finance:uncalculated-transactions
        {--month= : только этот период, YYYY-MM (по умолчанию все с HISTORICAL_CUTOFF)}
        {--calculate : запустить расчёт комиссий по найденным (пишет commission и consultantBalance)}
        {--limit=50 : сколько строк печатать}';

    protected $description = 'Найти транзакции без расчёта комиссий, которые пул уже учитывает (по умолчанию только чтение)';

    public function __construct(
        private readonly CommissionCalculator $calculator,
        private readonly PeriodFreezeService $periodFreeze,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $month = $this->option('month');
        if ($month !== null) {
            $month = (string) $month;
            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                $this->error('--month должен быть в формате YYYY-MM');

                return self::FAILURE;
            }
            if (CommissionCalculator::isHistorical($month)) {
                $this->error("Период {$month} исторический (< " . CommissionCalculator::HISTORICAL_CUTOFF . ') — такие сделки не пересчитываются.');

                return self::FAILURE;
            }
        }

        $rows = $this->find($month);
        if ($rows === []) {
            $this->info('Транзакций без расчёта комиссий нет.');

            return self::SUCCESS;
        }

        // Итог по месяцам: ровно на эту сумму выручка в пуле больше отчёта «Комиссии».
        $byMonth = [];
        foreach ($rows as $r) {
            $ym = substr((string) $r->date, 0, 7);
            $byMonth[$ym] = ($byMonth[$ym] ?? 0.0) + (float) $r->poolEstimate;
        }

        $this->warn('Транзакций без расчёта комиссий: ' . count($rows));
        foreach ($byMonth as $ym => $sum) {
            $closed = $this->isFrozen((string) $ym) ? ' — период закрыт' : '';
            $this->line("  {$ym}: пул учитывает оценкой {$this->n($sum)} ₽, в отчёте «Комиссии» 0{$closed}");
        }

        $limit = (int) $this->option('limit');
        $this->table(
            ['ID', 'Дата', 'Договор', 'Клиент', 'Партнёр', 'Сумма', '%ДС', 'В пуле'],
            array_map(fn ($r) => [
                $r->id,
                substr((string) $r->date, 0, 16),
                $r->number ?? '—',
                mb_substr((string) ($r->clientName ?? '—'), 0, 26),
                mb_substr((string) ($r->personName ?? '—'), 0, 26) . ($r->dateDeleted ? ' (карточка удалена)' : ''),
                $this->n((float) $r->amountRUB),
                (float) $r->dsCommissionPercentage,
                $this->n((float) $r->poolEstimate),
            ], array_slice($rows, 0, $limit))
        );

        if (count($rows) > $limit) {
            $this->comment('Показаны первые ' . $limit . ' из ' . count($rows) . '. Полный список — увеличьте --limit.');
        }

        if (! $this->option('calculate')) {
            $this->newLine();
            $this->comment('Это только диагностика. Досчитать: php artisan finance:uncalculated-transactions'
                . ($month !== null ? " --month={$month}" : '') . ' --calculate');

            return self::SUCCESS;
        }

        return $this->calculate($rows);
    }

    /**
     * @param  array<int, \stdClass>  $rows
     */
    private function calculate(array $rows): int
    {
        $this->newLine();
        $this->warn('Расчёт комиссий по ' . count($rows) . ' транзакциям.');

        $done = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $ym = substr((string) $r->date, 0, 7);

            // Закрытый месяц калькулятор и сам не тронет, но сказать надо явно:
            // такую сделку досчитывают только после разморозки периода.
            if ($this->isFrozen($ym)) {
                $skipped++;
                $this->line("  #{$r->id}: период {$ym} закрыт — пропущено");

                continue;
            }

            try {
                $res = $this->calculator->calculateForTransaction((int) $r->id);
            } catch (Throwable $e) {
                $res = ['error' => $e->getMessage()];
            }

            if (! empty($res['error'])) {
                $failed++;
                $this->error("  #{$r->id}: {$res['error']}");

                continue;
            }

            $done++;
            $income = (float) DB::table('transaction')->where('id', $r->id)->value('commissionsAmountRUB');
            $this->info("  #{$r->id}: посчитано, доход ДС без НДС {$this->n($income)} ₽");
        }

        $this->newLine();
        $this->info("Посчитано: {$done}, не прошло: {$failed}, пропущено (закрытый период): {$skipped}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Те же транзакции, что PoolRunner::monthlyVatExclusiveRevenue берёт
     * оценкой вместо записанного дохода ДС.
     *
     * @return array<int, \stdClass>
     */
    private function find(?string $month): array
    {
        $q = DB::table('transaction as t')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->leftJoin('consultant as cn', 'cn.id', '=', 'c.consultant')
            ->whereNull('t.deletedAt')
            ->whereNull('t.commissionsAmountRUB')
            // Без суммы или %ДС оценка пула нулевая — расходиться нечему.
            ->whereRaw('COALESCE(t."amountRUB", 0) * COALESCE(t."dsCommissionPercentage", 0) <> 0');

        if ($month !== null) {
            $start = $month . '-01';
            $q->where('t.date', '>=', $start)
                ->where('t.date', '<', date('Y-m-d', (int) strtotime($start . ' +1 month')));
        } else {
            $q->where('t.date', '>=', CommissionCalculator::HISTORICAL_CUTOFF);
        }

        return $q->orderBy('t.date')
            ->get([
                't.id', 't.date', 't.amountRUB', 't.dsCommissionPercentage',
                'c.number', 'c.clientName', 'cn.personName', 'cn.dateDeleted',
                // Та же формула, что в PoolRunner::monthlyVatExclusiveRevenue.
                DB::raw('t."amountRUB" * COALESCE(t."dsCommissionPercentage", 0) / 105 AS "poolEstimate"'),
            ])
            ->all();
    }

    private function isFrozen(string $ym): bool
    {
        return $this->periodFreeze->isFrozen((int) substr($ym, 0, 4), (int) substr($ym, 5, 2));
    }

    private function n(float $v): string
    {
        return number_format($v, 2, '.', ' ');
    }
}
