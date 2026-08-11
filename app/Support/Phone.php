<?php

namespace App\Support;

/**
 * Нормализация телефонов для сравнения.
 *
 * В базе один и тот же номер лежит во всех видах: «+7 904 390 46 79»,
 * «+79043904679», «79043904679», «89043904679». Сравнение строк даёт ложное
 * «не найдено» → интеграции плодят дубли клиентов. Канон для сверки —
 * ПОСЛЕДНИЕ 10 ЦИФР: они одинаковы у 7/8-префиксов (РФ) и у КЗ (7707…/8707…).
 */
final class Phone
{
    /** SQL-выражение с тем же каноном, для сравнения на стороне БД. */
    public const SQL_NORM = "right(regexp_replace(coalesce(%s, ''), '[^0-9]', '', 'g'), 10)";

    /**
     * Последние 10 цифр номера или null, если цифр меньше 10 (обрывки вроде
     * «12345» матчить нельзя — склеит разных людей).
     */
    public static function norm(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /** SQL-выражение нормализации для колонки (колонка уже в кавычках, если надо). */
    public static function sql(string $column): string
    {
        return sprintf(self::SQL_NORM, $column);
    }
}
