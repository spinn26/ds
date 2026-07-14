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

        // Исторические данные (< HISTORICAL_CUTOFF) неизменны — курс к ним не применяем.
        if (CommissionCalculator::isHistorical(sprintf('%04d-%02d', $year, $month))) {
            return ['updated' => 0, 'skipped' => 0, 'frozen' => true, 'commissionsAffected' => 0];
        }

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

        // НДС здесь больше не нужен: экономику (доход ДС без НДС, прибыль,
        // комиссии) пересчитывает CommissionCalculator — он берёт ставку НДС по
        // дате самой сделки.
        $txs = DB::table('transaction')
            ->whereNull('deletedAt')
            ->where('currency', $rate->currency)
            ->where('date', '>=', $period)
            ->where('date', '<=', $monthEnd)
            ->get(['id', 'amount']);

        // Кэш курсов мог остаться с прежним значением этого месяца.
        \App\Support\CurrencyRates::flush();

        $updated = 0;
        foreach ($txs as $tx) {
            $amountRub = (float) $tx->amount * $newRate;
            $amountUsd = $usdRate > 0 ? $amountRub / $usdRate : 0;

            // Только валютный контекст. netRevenueRUB здесь НЕ трогаем: раньше в
            // него писали «доход ДС без НДС», хотя калькулятор кладёт туда
            // «сумма без НДС − комиссии цепочки» — семантика колонки ломалась, и
            // отчёты, читающие денорм, разъезжались. Всю экономику пересчитывает
            // CommissionCalculator ниже.
            DB::table('transaction')->where('id', $tx->id)->update([
                'currencyRate' => $newRate,
                'amountRUB' => round($amountRub, 2),
                'amountUSD' => round($amountUsd, 2),
                'usdRate' => $usdRate,
            ]);
            $updated++;
        }

        // Спека ✅Валюты и НДС §2.1 шаг 3: «Как только сотрудник сохраняет новый
        // курс, система применяет его ко всем операциям за этот период —
        // пересчитываются рублёвые эквиваленты И экономика (Доход ДС без НДС и
        // Прибыль)».
        //
        // Раньше пересчёт останавливался на суммах: commissionsAmountRUB,
        // profitRUB и НИ ОДНА строка commission не обновлялись (их только
        // подсчитывали «для подсказки»), поэтому комиссии партнёров и база пула
        // оставались на старом курсе. Теперь гоняем полный каскад — он же
        // пересобирает consultantBalance.
        $calc = app(CommissionCalculator::class);
        $commissionsAffected = 0;
        $failed = [];
        foreach ($txs as $tx) {
            $res = $calc->calculateForTransaction((int) $tx->id);
            if (isset($res['error'])) {
                $failed[(int) $tx->id] = $res['error'];
                continue;
            }
            $commissionsAffected += (int) ($res['commissionsCount'] ?? 0);
        }

        Log::info('CurrencyRecalculator applied', [
            'rateId' => $currencyRateId,
            'year' => $year, 'month' => $month,
            'updated' => $updated,
            'commissionsAffected' => $commissionsAffected,
            'failed' => count($failed),
        ]);

        return [
            'updated' => $updated,
            'skipped' => $txs->count() - $updated,
            'frozen' => false,
            'commissionsAffected' => $commissionsAffected,
            'failed' => count($failed),
        ];
    }
}
