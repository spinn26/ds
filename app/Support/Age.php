<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Возраст партнёра по дате рождения и возрастные группы для аналитики.
 *
 * ⚠ Дата рождения лежит в двух местах и в разных типах: WebUser.birthDate —
 * timestamp, consultant.birthDate — varchar (наследство Directual, там
 * встречается и «1980-02-18», и «18.02.1980»). Поэтому парсим терпимо и
 * молча возвращаем null на мусоре: в выгрузке лучше «не указан», чем
 * посчитанный от битой строки возраст.
 */
final class Age
{
    /** Границы групп: [подпись, от, до включительно]. */
    private const BUCKETS = [
        ['до 25', 0, 24],
        ['25–34', 25, 34],
        ['35–44', 35, 44],
        ['45–54', 45, 54],
        ['55–64', 55, 64],
        ['65 и старше', 65, 200],
    ];

    /** Полных лет на сегодня. null — если дата пустая, битая или в будущем. */
    public static function years(mixed $birthDate, ?Carbon $now = null): ?int
    {
        $date = self::parse($birthDate);
        if ($date === null) {
            return null;
        }

        // Carbon 3 отдаёт diffInYears дробным и со знаком: приводим к полным
        // годам, а отрицательное (дата в будущем) отсечёт проверка ниже.
        $years = (int) $date->diffInYears($now ?? Carbon::now());

        // Возраст вне человеческого диапазона — почти всегда битая дата
        // («0001-01-01», опечатка в годе). Такие в статистику не пускаем.
        return ($years >= 14 && $years <= 100) ? $years : null;
    }

    /**
     * Дата рождения в едином виде «Y-m-d» — для выгрузок. null на пустом
     * и битом значении. Без этого в одной колонке Excel оказываются и
     * «1988-03-14» из WebUser, и «18.02.1980» из карточки партнёра, и
     * сводная таблица по ним уже не строится.
     */
    public static function date(mixed $birthDate): ?string
    {
        return self::parse($birthDate)?->format('Y-m-d');
    }

    /** Возрастная группа. «Не указан» — если возраст посчитать не удалось. */
    public static function bucket(?int $years): string
    {
        if ($years === null) {
            return 'Не указан';
        }
        foreach (self::BUCKETS as [$label, $from, $to]) {
            if ($years >= $from && $years <= $to) {
                return $label;
            }
        }

        return 'Не указан';
    }

    /** Подписи групп в порядке возрастания — для осей и сводок. */
    public static function bucketLabels(): array
    {
        return array_merge(array_column(self::BUCKETS, 0), ['Не указан']);
    }

    private static function parse(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        try {
            // «18.02.1980» Carbon читает как d.m.Y только через createFromFormat:
            // parse() на точечном формате уезжает в американский порядок.
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $s)) {
                $dotted = Carbon::createFromFormat('d.m.Y', $s);

                return $dotted ? $dotted->startOfDay() : null;
            }

            return Carbon::parse($s);
        } catch (\Throwable) {
            return null;
        }
    }
}
