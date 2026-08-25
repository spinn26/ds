<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * %ДС для ПРОГНОЗА («В работе» / «Активировано») — строго из карточки продукта.
 *
 * Источник истины по процентам — раздел «Продукты», а не legacy-сетка
 * `dsCommission`. Раньше прогноз ходил в сетку через
 * `CommissionCalculator::resolveLegacyDsCommission($program, $term, null, $date)`
 * с ГОДОМ = null: транзакции ещё нет, свойство выплаты неизвестно, поэтому
 * каскад отбрасывал фильтр по свойству и брал строку
 * `ORDER BY date DESC, id DESC LIMIT 1`. Даты у legacy-строк одинаковые
 * (2000-01-01), так что ставку выбирал ПОРЯДОК ВСТАВКИ.
 *
 * Чем это кончалось (разбор 25.08.2026): у РАНКС «РФ СТАНДАРТ» в карточке
 * upfront 1,5% и SF 10%, а в сетке лежала ещё невидимая МФ 0,25% с самым
 * большим id — прогноз считался по ней. Соседние «США СТАНДАРТ» брали 10%.
 * Одно семейство продуктов, разброс ставки в 40 раз, и оператор эту 0,25%
 * в карточке даже не видел.
 *
 * Порядок выбора повторяет подпись в карточке — «Пусто → берётся тариф по
 * строкам ниже»:
 *   1) «%ДС программы» (`ds_percent`);
 *   2) строка тарифа, выбранная «Свойством расчёта» / «Годом выплаты КВ»
 *      карточки, если они заданы;
 *   3) иначе апфронт — ставка, которую компания получает В МОМЕНТ сделки
 *      (СФ — success fee, он может не наступить, и прогноз по нему завышает);
 *   4) иначе первый год КВ;
 *   5) иначе первая строка карточки — та, что оператор видит сверху.
 *
 * ⚠ Строки «Искл.» (`is_red`) пропускаются — это ставки по старым контрактам.
 * ⚠ Тарифа нет вовсе → null. Прогноз тогда НЕ начисляет выручку, а ячейка
 *   помечается подсказкой «не указан % ДС в продукте» (см. SalesMatrixAssembler).
 */
class ForecastDsRate
{
    /** @var array<int,float>|null кэш на время запроса: legacy program id => %ДС */
    private static ?array $map = null;

    /** Синонимы свойства расчёта: как пишут в карточке → как ищем в строках. */
    private const UPFRONT = ['upfront', 'апфронт'];

    public static function forProgram(int|string|null $legacyProgramId): ?float
    {
        if ($legacyProgramId === null || $legacyProgramId === '') {
            return null;
        }
        self::$map ??= self::build();

        return self::$map[(int) $legacyProgramId] ?? null;
    }

    /** Сбросить кэш (после правки карточки продукта). */
    public static function flush(): void
    {
        self::$map = null;
    }

    /** @return array<int,float> */
    private static function build(): array
    {
        $out = [];

        $rows = DB::table('programs_catalog')
            ->whereNotNull('legacy_program_id')
            ->get(['legacy_program_id', 'ds_percent', 'tariffs', 'commission_calc_property', 'kv_payout_year']);

        foreach ($rows as $r) {
            $id = (int) $r->legacy_program_id;

            // 1) «%ДС программы» — общий процент карточки.
            if ($r->ds_percent !== null && (float) $r->ds_percent > 0) {
                $out[$id] = (float) $r->ds_percent;
                continue;
            }

            $pct = self::fromTariffs($r);
            if ($pct !== null) {
                $out[$id] = $pct;
            }
        }

        return $out;
    }

    private static function fromTariffs(object $r): ?float
    {
        $tariffs = json_decode((string) $r->tariffs, true);
        if (! is_array($tariffs) || ! $tariffs) {
            return null;
        }

        // Годные строки: не «Искл.» и с разобранным процентом.
        $rows = [];
        foreach ($tariffs as $t) {
            if (! is_array($t) || ! empty($t['is_red'])) {
                continue;
            }
            $pct = self::parsePct($t);
            if ($pct !== null && $pct > 0) {
                $rows[] = ['pct' => $pct, 'raw' => $t];
            }
        }
        if (! $rows) {
            return null;
        }

        // 2) Свойство / год выплаты, заданные в карточке.
        $prop = mb_strtolower(trim((string) ($r->commission_calc_property ?? '')));
        if ($prop !== '') {
            foreach ($rows as $row) {
                if (mb_strtolower(trim((string) ($row['raw']['property'] ?? ''))) === $prop) {
                    return $row['pct'];
                }
            }
        }
        $year = trim((string) ($r->kv_payout_year ?? ''));
        if ($year !== '') {
            foreach ($rows as $row) {
                if ((string) ($row['raw']['year_kv'] ?? '') === $year) {
                    return $row['pct'];
                }
            }
        }

        // 3) Апфронт — ставка момента сделки.
        foreach ($rows as $row) {
            if (in_array(mb_strtolower(trim((string) ($row['raw']['property'] ?? ''))), self::UPFRONT, true)) {
                return $row['pct'];
            }
        }

        // 4) Первый год КВ.
        foreach ($rows as $row) {
            if ((string) ($row['raw']['year_kv'] ?? '') === '1') {
                return $row['pct'];
            }
        }

        // 5) Первая строка карточки.
        return $rows[0]['pct'];
    }

    /** «1,5» / «6,00%» / 3.45 → float. */
    private static function parsePct(array $t): ?float
    {
        $raw = $t['ds_pct'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = str_replace([' ', "\u{00A0}", '%'], '', (string) $raw);
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : null;
    }
}
