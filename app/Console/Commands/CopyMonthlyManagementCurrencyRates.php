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
        $thisMonthStart = now()->startOfMonth();

        // Идём от последнего известного месяца до текущего и добиваем ВСЕ
        // пропуски, а не только «прошлый месяц». Иначе один пропущенный месяц
        // (напр. справочник заведён в середине месяца) навсегда рвёт цепочку:
        // следующий запуск не находит источник и печатает «копировать нечего».
        $maxDate = DB::table('management_currency_rate')->max('date');
        if (! $maxDate) {
            $this->warn('management_currency_rate пуст — копировать нечего '
                .'(заполните первый месяц вручную или через copy-from-main)');
            return self::SUCCESS;
        }

        $cursor  = \Carbon\Carbon::parse($maxDate)->startOfMonth();
        $copied  = 0;
        $skipped = 0;
        $months  = 0;

        while ($cursor->lt($thisMonthStart)) {
            $srcStart = $cursor->copy();
            $srcEnd   = $cursor->copy()->endOfMonth();
            $target   = $cursor->copy()->addMonth()->toDateString();

            $rows = DB::table('management_currency_rate')
                ->whereBetween('date', [$srcStart->toDateString(), $srcEnd->toDateString()])
                ->orderByDesc('date')
                ->get();

            $latestPerCurrency = [];
            foreach ($rows as $row) {
                if (! isset($latestPerCurrency[$row->currency])) {
                    $latestPerCurrency[$row->currency] = $row;
                }
            }

            foreach ($latestPerCurrency as $currencyId => $row) {
                $exists = DB::table('management_currency_rate')
                    ->where('currency', $currencyId)
                    ->whereDate('date', $target)
                    ->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }
                DB::table('management_currency_rate')->insert([
                    'currency'   => $currencyId,
                    'rate'       => $row->rate,
                    'date'       => $target,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $copied++;
            }

            $months++;
            $cursor->addMonth();
        }

        $this->info("Management rates: месяцев обработано {$months}, "
            ."скопировано {$copied}, пропущено (уже есть) {$skipped}");
        return self::SUCCESS;
    }
}
