<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ставка НДС на дату операции (справочник `vat`, окно [dateFrom, dateTo]).
 *
 * ⚠ Зачем отдельный класс: раньше в каждом месте стояло
 *
 *     $vatPercent = (float) ($vat->value ?? 0);
 *     $amountNoVat = $amountRub / (1 + $vatPercent / 100);
 *
 * — и если строки `vat`, покрывающей дату сделки, не было, ставка молча
 * становилась нулевой. Тогда `amountNoVat = amountRub`, то есть база завышена
 * на всю ставку НДС, а дальше это тянет за собой доход ДС, ЛП, комиссии всей
 * цепочки наставников и базу лидерского пула.
 *
 * Ровно та же ситуация с ненайденным %ДС решена в CommissionCalculator
 * осознанно и наоборот: «отсутствие тарифа — это ошибка данных, и она должна
 * быть видна, а не молча оплачена». Здесь тот же принцип:
 *   - расчётные пути (каскад комиссий, превью ручной транзакции) зовут
 *     percentOrFail() и падают понятной ошибкой;
 *   - отчётные/справочные — percent() с явным фолбэком и предупреждением в лог.
 */
class VatRate
{
    /** @var array<string, float|null> кэш на время запроса: 'YYYY-MM-DD' => ставка */
    private static array $cache = [];

    /**
     * Ставка НДС в процентах на дату, либо null — если окна на эту дату нет.
     *
     * @param string|\DateTimeInterface|null $date null → сегодня
     */
    public static function percent($date = null): ?float
    {
        $day = self::dayOf($date);
        if (array_key_exists($day, self::$cache)) {
            return self::$cache[$day];
        }

        $value = DB::table('vat')
            ->where('dateFrom', '<=', $day)
            ->where('dateTo', '>=', $day)
            ->orderByDesc('dateFrom')
            ->value('value');

        return self::$cache[$day] = $value === null ? null : (float) $value;
    }

    /**
     * Ставка НДС на дату или исключение. Использовать везде, где результат
     * уходит в деньги: молча посчитанная без НДС сумма завышает всю цепочку.
     *
     * @throws \RuntimeException
     */
    public static function percentOrFail($date = null): float
    {
        $percent = self::percent($date);
        if ($percent === null) {
            throw new \RuntimeException(
                'Не найдена ставка НДС на дату ' . self::dayOf($date)
                . '. Заведите период в справочнике НДС (Финансы → Валюты и НДС) — '
                . 'расчёт по ставке 0% отключён, иначе доход ДС и все комиссии цепочки завышаются.'
            );
        }

        return $percent;
    }

    /**
     * Ставка НДС на дату с явным фолбэком — для отчётов и справочных выдач,
     * где падать нельзя. Отсутствие ставки пишется в лог: молча взятый ноль
     * искажает цифры отчёта ровно так же, как и расчёта.
     */
    public static function percentOrDefault($date = null, float $default = 0.0): float
    {
        $percent = self::percent($date);
        if ($percent === null) {
            Log::warning('VatRate: нет ставки НДС на дату, взят фолбэк', [
                'date' => self::dayOf($date),
                'fallback' => $default,
            ]);

            return $default;
        }

        return $percent;
    }

    /** Сбросить кэш (после правки справочника НДС). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /** 'YYYY-MM-DD' операции. Формат тот же, что понимает CurrencyRates. */
    private static function dayOf($date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        if (is_string($date) && trim($date) !== '') {
            $raw = trim($date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m)) {
                return $m[0];
            }
            $ts = strtotime($raw);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }

        return now()->toDateString();
    }
}
