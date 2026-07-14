<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Курс валюты на дату операции.
 *
 * Спека ✅Валюты и НДС §1/§2.1: справочник хранит СРЕДНЕВЗВЕШЕННЫЙ курс за МЕСЯЦ
 * (по строке на валюту на каждый месяц; 1-го числа система копирует прошлый месяц
 * как заглушку, затем финансисты вписывают фактический курс). Значит курс сделки —
 * это курс МЕСЯЦА сделки, а не «последний в справочнике».
 *
 * Раньше повсюду стоял `orderByDesc('date')->first()` — последний курс вообще.
 * Пока сделки заводят текущим месяцем, это случайно совпадает; но любая сделка
 * задним числом (типовой сценарий — донос майских выплат в июле) бралась по
 * свежему курсу, и смещение уходило вниз по всей цепочке: amountRUB -> доход ДС ->
 * ЛП -> комиссии всех наставников -> база пула.
 *
 * ВАЖНО про схему: в легаси-таблице `currencyRate` колонки `date` и `rate` — TEXT,
 * причём часть строк записана с временем (напр. '2026-06-01 03:00:00', сдвиг МСК).
 * Поэтому сравнивать даты «как есть» нельзя — сравниваем по НАЧАЛУ МЕСЯЦА.
 */
class CurrencyRates
{
    /** RUB — базовая валюта, курс всегда 1. */
    public const RUB_CURRENCY_ID = 67;

    public const USD_CURRENCY_ID = 5;

    /** @var array<string, float> кэш на время запроса: "currency|YYYY-MM" => rate */
    private static array $cache = [];

    /**
     * Курс валюты на дату операции = курс её месяца.
     * Если строки за этот месяц нет — последний известный курс более раннего
     * месяца (страховка на случай, если крон-копирование не отработало).
     *
     * @param string|\DateTimeInterface|null $date дата операции; null -> текущий месяц
     */
    public static function forDate(?int $currencyId, $date = null): float
    {
        $currencyId = $currencyId ?: self::RUB_CURRENCY_ID;
        if ($currencyId === self::RUB_CURRENCY_ID) {
            return 1.0;
        }

        $month = self::monthOf($date);
        $key = $currencyId . '|' . $month;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $rate = DB::table('currencyRate')
            ->where('currency', $currencyId)
            ->whereRaw("date_trunc('month', date::timestamp) <= ?::timestamp", [$month . '-01'])
            ->orderByRaw("date_trunc('month', date::timestamp) DESC")
            ->value('rate');

        return self::$cache[$key] = $rate !== null ? (float) $rate : 1.0;
    }

    /** Курс USD на дату операции — для колонок amountUSD / доход ДС в USD. */
    public static function usdForDate($date = null): float
    {
        return self::forDate(self::USD_CURRENCY_ID, $date);
    }

    /** Сбросить кэш (нужно после правки курса — иначе пересчёт возьмёт старое значение). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /** 'YYYY-MM' операции. */
    private static function monthOf($date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m');
        }
        if (is_string($date) && $date !== '') {
            return substr($date, 0, 7);
        }
        return now()->format('Y-m');
    }
}
