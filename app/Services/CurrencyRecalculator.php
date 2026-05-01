<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Пересчёт сумм транзакций при изменении месячного курса валюты.
 *
 * Per spec ✅Валюты и НДС.md §2.1 шаг 3 «Глобальный пересчёт»:
 *   «Как только сотрудник сохраняет новый курс, система автоматически
 *    применяет его ко всем операциям за этот период:
 *      - Пересчитываются рублевые эквиваленты валютных транзакций
 *      - Пересчитывается экономика (Доход ДС без НДС и Прибыль)»
 *
 * Логика:
 *   1. Найти все transaction за месяц курса с currency=X (не RUB).
 *   2. Перезаписать amountRUB = amount × newRate, amountUSD по USD-курсу.
 *   3. Перезаписать netRevenueRUB через ту же VAT-формулу /105*100.
 *   4. Защищено PeriodFreezeService — закрытые периоды не трогаем.
 *   5. Уведомить о наличии commission-строк за этот период (commission
 *      пересчёт делается отдельно через CommissionCalculator).
 */
class CurrencyRecalculator
{
    private const RUB_CURRENCY_ID = 67;
    private const USD_CURRENCY_ID = 5;

    public function __construct(private readonly PeriodFreezeService $periodFreeze) {}

    /**
     * @return array{updated:int, skipped:int, frozen:bool, commissionsAffected:int}
     */
    public function recalcForRate(int $currencyRateId): array
    {
        $rate = DB::table('currencyRate')->where('id', $currencyRateId)->first();
        if (! $rate) {
            return ['updated' => 0, 'skipped' => 0, 'frozen' => false, 'commissionsAffected' => 0, 'error' => 'Курс не найден'];
        }
        if ($rate->currency == self::RUB_CURRENCY_ID) {
            // Рубль не пересчитывается — он базовая валюта.
            return ['updated' => 0, 'skipped' => 0, 'frozen' => false, 'commissionsAffected' => 0];
        }

        $period = \Carbon\Carbon::parse($rate->date)->startOfMonth();
        $year = (int) $period->format('Y');
        $month = (int) $period->format('m');

        if ($this->periodFreeze->isFrozen($year, $month)) {
            return ['updated' => 0, 'skipped' => 0, 'frozen' => true, 'commissionsAffected' => 0];
        }

        $monthEnd = $period->copy()->endOfMonth();
        $newRate = (float) $rate->rate;

        // USD-курс на этот же месяц нужен для пересчёта amountUSD.
        // Используем диапазон дат вместо whereYear/whereMonth — это
        // позволяет Postgres применить btree-индекс на currencyRate(date)
        // вместо seq-scan'а по всей таблице.
        $usdRow = DB::table('currencyRate')
            ->where('currency', self::USD_CURRENCY_ID)
            ->where('date', '>=', $period)
            ->where('date', '<=', $monthEnd)
            ->first();
        $usdRate = (float) ($usdRow->rate ?? 0);

        // VAT в этом месяце (для пересчёта netRevenueRUB)
        $vat = DB::table('vat')
            ->where('dateFrom', '<=', $monthEnd)
            ->where('dateTo', '>=', $period)
            ->first();
        $vatPercent = (float) ($vat->value ?? 5);

        $txs = DB::table('transaction')
            ->whereNull('deletedAt')
            ->where('currency', $rate->currency)
            ->where('date', '>=', $period)
            ->where('date', '<=', $monthEnd)
            ->get(['id', 'amount', 'dsCommissionPercentage']);

        $updated = 0;
        foreach ($txs as $tx) {
            $amountRub = (float) $tx->amount * $newRate;
            $amountUsd = $usdRate > 0 ? $amountRub / $usdRate : 0;
            $dsPercent = (float) ($tx->dsCommissionPercentage ?? 0);
            // netRevenueRUB сразу пересчитываем если у транзакции есть %DS
            $netRevenue = $dsPercent > 0
                ? round(($amountRub * $dsPercent / 100) / (1 + $vatPercent / 100), 2)
                : null;

            $update = [
                'currencyRate' => $newRate,
                'amountRUB' => round($amountRub, 2),
                'amountUSD' => round($amountUsd, 2),
                'usdRate' => $usdRate,
            ];
            if ($netRevenue !== null) {
                $update['netRevenueRUB'] = $netRevenue;
            }
            DB::table('transaction')->where('id', $tx->id)->update($update);
            $updated++;
        }

        // Считаем commission-строки за период (для пользователя — подсказка
        // что нужно их перерасчитать через CommissionCalculator).
        $monthStr = sprintf('%02d', $month);
        $commissionsAffected = DB::table('commission')
            ->where('dateMonth', $monthStr)
            ->where('dateYear', (string) $year)
            ->whereIn('transaction', $txs->pluck('id'))
            ->whereNull('deletedAt')
            ->count();

        Log::info('CurrencyRecalculator applied', [
            'rateId' => $currencyRateId,
            'year' => $year, 'month' => $month,
            'updated' => $updated, 'commissionsAffected' => $commissionsAffected,
        ]);

        return [
            'updated' => $updated,
            'skipped' => $txs->count() - $updated,
            'frozen' => false,
            'commissionsAffected' => $commissionsAffected,
        ];
    }
}
