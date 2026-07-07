<?php

namespace App\Support;

/**
 * Парсинг «человеческих» чисел из отчётов (Google Sheets / Excel / CSV).
 *
 * Русская локаль Excel и Google Sheets форматируют суммы как «7 754,00»,
 * где разделитель разрядов — НЕ обычный ASCII-пробел (U+0020), а
 * НЕРАЗРЫВНЫЙ пробел (U+00A0) или узкий неразрывный (U+202F). Старый
 * `str_replace([' ', ','], ['', '.'])` убирал только ASCII-пробел, поэтому
 * «7 754,00» с U+00A0 после `(float)`-каста обрывался на первом же таком
 * символе и превращался в 7 (а «3 760,00» — в 3). См. инцидент импорта Робо.
 *
 * decimal() снимает ВСЕ юникод-пробелы (\pZ + \s: обычный, NBSP, narrow
 * no-break, thin space, табы) как разделители разрядов и переводит запятую
 * в точку. Ожидается русский формат: пробелы = разряды, запятая = дробь.
 */
class Numbers
{
    /**
     * Нормализовать строку-число к float. Пустое/нечисловое → $default.
     *
     * @param mixed $raw исходное значение из ячейки (string|int|float|null)
     */
    public static function decimal(mixed $raw, float $default = 0.0): float
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        $s = self::normalize((string) $raw);

        return is_numeric($s) ? (float) $s : $default;
    }

    /**
     * Нормализованное строковое представление числа (для случаев, где
     * значение затем связывается в БД как есть). Возвращает '' если строка
     * не является числом.
     */
    public static function normalizeString(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }
        $s = self::normalize((string) $raw);

        return is_numeric($s) ? $s : '';
    }

    private static function normalize(string $s): string
    {
        // \pZ — все юникод-разделители (space, NBSP U+00A0, narrow no-break
        // U+202F, thin space U+2009 …); \s — управляющие пробелы (tab/newline).
        $s = preg_replace('/[\pZ\s]/u', '', $s);
        // Русская дробная запятая → точка.
        return str_replace(',', '.', (string) $s);
    }
}
