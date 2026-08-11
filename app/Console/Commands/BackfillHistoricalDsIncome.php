<?php

namespace App\Console\Commands;

use App\Services\CommissionCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Привести «Доход ДС» исторических транзакций к смыслу платформы.
 *
 * ПРОБЛЕМА. До HISTORICAL_CUTOFF строки перенесены из Directual как есть, и в
 * `commissionsAmountRUB` там лежит СУММА ВЫПЛАТ ПАРТНЁРСКОЙ ЦЕПОЧКЕ. С cutoff
 * в ту же колонку наш калькулятор пишет ДОХОД ДС без НДС. Одна колонка — два
 * смысла: сверено на проде, до июня поле равно сумме цепочки у 35 381 из
 * 35 389 транзакций, после — у 14 из 2088. Отчёты читают колонку как выручку,
 * поэтому все месяцы до июня занижены (по Эволюции 35–42% вместо 87%).
 *
 * ЧТО ДЕЛАЕТ. Пересчитывает ТОЛЬКО денормализованные поля дохода ДС из суммы и
 * %ДС самой транзакции: amountRUB / (1 + НДС) × %ДС. Комиссии, балансы,
 * начисления и выплаты НЕ трогает — цепочка `commission` остаётся ровно такой,
 * какой была, поэтому деньги партнёров не двигаются.
 *
 * ОБРАТИМОСТЬ. Старые значения складываются в backup-таблицу до правки, откат —
 * одним UPDATE из неё.
 */
class BackfillHistoricalDsIncome extends Command
{
    protected $signature = 'finance:backfill-ds-income
                            {--apply : записать изменения (иначе dry-run)}
                            {--backup-table=transaction_ds_income_backup : куда сложить прежние значения}';

    protected $description = 'Пересчитать «Доход ДС» у исторических транзакций (до cutoff) из суммы и %ДС';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $backupTable = (string) $this->option('backup-table');
        $cutoff = CommissionCalculator::HISTORICAL_CUTOFF;

        // Ставки НДС по периодам — как в калькуляторе: база считается по
        // ставке, действовавшей на дату сделки, а не по текущей.
        $vatRows = DB::table('vat')->orderBy('dateFrom')->get(['dateFrom', 'dateTo', 'value']);
        if ($vatRows->isEmpty()) {
            $this->error('Нет ставок НДС — считать базу не от чего.');
            return self::FAILURE;
        }

        $rows = DB::table('transaction')
            ->whereNull('deletedAt')
            ->whereNotNull('date')
            ->where('date', '<', $cutoff)
            ->where(function ($q) {
                $q->where('dsCommissionPercentage', '>', 0)
                  ->orWhere(function ($q2) {
                      $q2->where('customCommission', true)
                         ->whereNotNull('dsCommissionAbsolute');
                  });
            })
            ->orderBy('id')
            ->get([
                'id', 'date', 'amountRUB', 'dsCommissionPercentage',
                'customCommission', 'dsCommissionAbsolute',
                'commissionsAmountRUB', 'commissionsAmountUSD',
            ]);

        $this->info("Кандидатов (дата < {$cutoff}): " . $rows->count());
        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $usdRates = [];   // 'YYYY-MM' → курс доллара
        $updates = [];
        $unchanged = 0;
        $sumOld = 0.0;
        $sumNew = 0.0;

        foreach ($rows as $r) {
            $vat = $this->vatFor($vatRows, (string) $r->date);
            $amountRub = (float) ($r->amountRUB ?? 0);
            $amountNoVat = $amountRub / (1 + $vat / 100);

            // «Своя комиссия»: доход ДС задан суммой и хранится БЕЗ НДС —
            // берём как есть, тот же приоритет, что в калькуляторе.
            if (! empty($r->customCommission) && abs((float) ($r->dsCommissionAbsolute ?? 0)) > 0.000001) {
                $income = (float) $r->dsCommissionAbsolute;
            } else {
                $income = $amountNoVat * (float) $r->dsCommissionPercentage / 100;
            }
            $income = round($income, 2);

            $old = round((float) ($r->commissionsAmountRUB ?? 0), 2);
            if (abs($old - $income) < 0.01) {
                $unchanged++;
                continue;
            }

            $month = substr((string) $r->date, 0, 7);
            if (! isset($usdRates[$month])) {
                $usdRates[$month] = \App\Support\CurrencyRates::usdForDate((string) $r->date);
            }
            $usd = $usdRates[$month] > 0 ? round($income / $usdRates[$month], 2) : 0;

            $updates[] = ['id' => (int) $r->id, 'income' => $income, 'usd' => $usd, 'old' => $old];
            $sumOld += $old;
            $sumNew += $income;
        }

        $this->info('Без изменений: ' . $unchanged);
        $this->info('К обновлению: ' . count($updates));
        $this->line(sprintf('  было суммарно:  %s ₽', number_format($sumOld, 2, ',', ' ')));
        $this->line(sprintf('  станет:         %s ₽', number_format($sumNew, 2, ',', ' ')));
        $this->line(sprintf('  разница:        %s ₽', number_format($sumNew - $sumOld, 2, ',', ' ')));

        if (! $updates) {
            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('DRY-RUN — ничего не записано. Повторите с --apply.');
            return self::SUCCESS;
        }

        // Бэкап прежних значений: откат — UPDATE из этой таблицы.
        if (! Schema::hasTable($backupTable)) {
            Schema::create($backupTable, function ($table) {
                $table->integer('transaction_id')->primary();
                $table->decimal('old_rub', 20, 6)->nullable();
                $table->decimal('old_usd', 20, 6)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        $now = now();
        $ids = array_column($updates, 'id');
        DB::table($backupTable)->whereIn('transaction_id', $ids)->delete();
        foreach (array_chunk($rows->whereIn('id', $ids)->all(), 500) as $chunk) {
            DB::table($backupTable)->insert(array_map(fn ($r) => [
                'transaction_id' => (int) $r->id,
                'old_rub' => $r->commissionsAmountRUB,
                'old_usd' => $r->commissionsAmountUSD,
                'created_at' => $now,
            ], $chunk));
        }
        $this->info("Прежние значения сохранены в «{$backupTable}»: " . count($ids));

        $done = 0;
        foreach (array_chunk($updates, 500) as $chunk) {
            DB::transaction(function () use ($chunk, &$done) {
                foreach ($chunk as $u) {
                    DB::table('transaction')->where('id', $u['id'])->update([
                        'commissionsAmountRUB' => $u['income'],
                        'commissionsAmountUSD' => $u['usd'],
                    ]);
                    $done++;
                }
            });
            $this->output->write('.');
        }
        $this->newLine();
        $this->info("Обновлено транзакций: {$done}");
        $this->warn('Комиссии, балансы и выплаты НЕ трогались — изменены только денорм-поля дохода ДС.');

        return self::SUCCESS;
    }

    /** Ставка НДС, действовавшая на дату сделки. */
    private function vatFor($vatRows, string $date): float
    {
        $d = substr($date, 0, 10);
        foreach ($vatRows as $v) {
            $from = substr((string) $v->dateFrom, 0, 10);
            $to = substr((string) $v->dateTo, 0, 10);
            if ($d >= $from && $d <= $to) {
                return (float) $v->value;
            }
        }
        return (float) ($vatRows->last()->value ?? 0);
    }
}
