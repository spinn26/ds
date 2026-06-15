<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Аналог CopyMonthlyCurrencyRates, но для справочника курсов руководителей
 * (management_currency_rate). 1-го числа каждого месяца копирует последний
 * известный курс прошлого месяца как placeholder — сотрудник затем
 * проставляет реальные значения вручную.
 */
class CopyMonthlyManagementCurrencyRates extends Command
{
    protected $signature = 'currencies:copy-monthly-management-rates';
    protected $description = 'Скопировать курсы справочника руководителей на наступивший месяц';

    public function handle(): int
    {
        $now = now();
        $thisMonthStart = $now->copy()->startOfMonth()->toDateString();
        $prevMonthStart = $now->copy()->subMonth()->startOfMonth()->toDateString();
        $prevMonthEnd   = $now->copy()->subMonth()->endOfMonth()->toDateString();

        $prev = DB::table('management_currency_rate')
            ->whereBetween('date', [$prevMonthStart, $prevMonthEnd])
            ->orderByDesc('date')
            ->get();

        if ($prev->isEmpty()) {
            $this->warn('Нет курсов за прошлый месяц в management_currency_rate — копировать нечего');
            return self::SUCCESS;
        }

        $latestPerCurrency = [];
        foreach ($prev as $row) {
            if (! isset($latestPerCurrency[$row->currency])) {
                $latestPerCurrency[$row->currency] = $row;
            }
        }

        $copied = 0;
        $skipped = 0;
        foreach ($latestPerCurrency as $currencyId => $row) {
            $exists = DB::table('management_currency_rate')
                ->where('currency', $currencyId)
                ->whereDate('date', $thisMonthStart)
                ->exists();
            if ($exists) {
                $skipped++;
                continue;
            }
            DB::table('management_currency_rate')->insert([
                'currency'   => $currencyId,
                'rate'       => $row->rate,
                'date'       => $thisMonthStart,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $copied++;
        }

        $this->info("Management rates: скопировано {$copied}, пропущено (уже есть) {$skipped}");
        return self::SUCCESS;
    }
}
