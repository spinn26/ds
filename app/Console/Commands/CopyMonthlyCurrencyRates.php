<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Per spec ✅Справочники для расчёта транзакций §2.1.
 *
 * 1-го числа каждого месяца в 00:00 копирует курсы валют (USD/GBP/EUR
 * и любые другие, что были в прошлом месяце) на наступивший месяц,
 * чтобы расчёты платформы не сломались из-за пустого значения.
 *
 * Финансовый отдел потом вручную заходит в админку и проставляет
 * реальные средневзвешенные курсы (карандаш в строке).
 *
 * Идемпотентно: если для пары (currency, period) уже есть строка —
 * не дублирует.
 */
class CopyMonthlyCurrencyRates extends Command
{
    protected $signature = 'currencies:copy-monthly-rates';
    protected $description = 'Скопировать курсы валют на наступивший месяц с прошлого (placeholder)';

    public function handle(): int
    {
        $now = now();
        $thisMonthStart = $now->copy()->startOfMonth()->toDateString();
        $prevMonthStart = $now->copy()->subMonth()->startOfMonth()->toDateString();
        $prevMonthEnd = $now->copy()->subMonth()->endOfMonth()->toDateString();

        // Берём последний курс каждой валюты за прошлый месяц.
        $prev = DB::table('currencyRate')
            ->whereBetween('date', [$prevMonthStart, $prevMonthEnd])
            ->orderByDesc('date')
            ->get();

        if ($prev->isEmpty()) {
            $this->warn('Нет курсов за прошлый месяц — копировать нечего');
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
            $exists = DB::table('currencyRate')
                ->where('currency', $currencyId)
                ->whereDate('date', $thisMonthStart)
                ->exists();
            if ($exists) {
                $skipped++;
                continue;
            }
            DB::table('currencyRate')->insert([
                'currency' => $currencyId,
                'rate' => $row->rate,
                'date' => $thisMonthStart,
            ]);
            $copied++;
        }

        $this->info("Скопировано курсов: {$copied}, пропущено (уже было): {$skipped}");
        return self::SUCCESS;
    }
}
