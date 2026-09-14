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
 * Порядок выбора повторяет подпись в карточке — у поля «%ДС программы» там
 * написано «Общий процент, ЕСЛИ НЕ ЗАДАН построчный тариф», то есть строки
 * главнее общего процента:
 *   0) есть строки на срок договора — выбираем только среди них;
 *   1) строка тарифа, выбранная «Свойством расчёта» / «Годом выплаты КВ»
 *      карточки, если они заданы;
 *   2) иначе апфронт — ставка, которую компания получает В МОМЕНТ сделки
 *      (СФ — success fee, он может не наступить, и прогноз по нему завышает);
 *   3) иначе первый год КВ;
 *   4) иначе первая строка карточки — та, что оператор видит сверху;
 *   5) строк нет вовсе → «%ДС программы» (`ds_percent`).
 *
 * ⚠ Срок договора (шаг 0). У части карточек строки заведены по сроку —
 * Medlife, Зетта, ИТА, «Мой капитал», всего 46 карточек на 14.09.2026, — и
 * ставка первого года от срока зависит сильно: у Medlife KIP 26,05% при сроке
 * 10 и 36,96% при сроке 14. Срок раньше не учитывался, шаги 1–4 находили
 * первую строку «1 год» — срок 10 — для всех договоров, и матрица показывала
 * выручку KIP со сроком 14 как 24,8% суммы вместо 35,2%. Строк на срок
 * договора в карточке нет — выбор идёт среди всех строк, как раньше.
 *
 * ⚠ Приоритет строк над общим процентом именно такой: у части карточек
 * `ds_percent` разошёлся со строками и выглядит артефактом импорта. У
 * Hansard_CB, WPP_Z5 и WPP_Z8 там стоит 75 при единственной строке 6,00% — и
 * ровно «75%» написано в их поле «Категория» («75% от суммы полученной
 * комиссии от клиента»). Всего таких карточек со строками И общим процентом — 138.
 *
 * ⚠ Строки «Искл.» (`is_red`) пропускаются — это ставки по старым контрактам.
 * ⚠ Тарифа нет вовсе → null. Прогноз тогда НЕ начисляет выручку, а ячейка
 *   помечается подсказкой «не указан % ДС в продукте» (см. SalesMatrixAssembler).
 */
class ForecastDsRate
{
    /**
     * Кэш на время запроса: legacy program id => годные строки карточки и её
     * поля выбора. Ставка выбирается уже при запросе — она зависит от срока.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $cards = null;

    /** @var array<string, float|null> выбранная ставка по ключу «программа|срок» */
    private static array $picked = [];

    /** Синонимы свойства расчёта: как пишут в карточке → как ищем в строках. */
    private const UPFRONT = ['upfront', 'апфронт'];

    /**
     * @param  int|string|null  $term  срок договора (contract.term); null — без учёта срока
     */
    public static function forProgram(int|string|null $legacyProgramId, int|string|null $term = null): ?float
    {
        if ($legacyProgramId === null || $legacyProgramId === '') {
            return null;
        }
        self::$cards ??= self::build();

        $id = (int) $legacyProgramId;
        $termKey = self::normalizeTerm($term);
        $key = $id . '|' . ($termKey ?? '');
        if (array_key_exists($key, self::$picked)) {
            return self::$picked[$key];
        }

        $card = self::$cards[$id] ?? null;

        return self::$picked[$key] = $card === null ? null : self::pick($card, $termKey);
    }

    /** Сбросить кэш (после правки карточки продукта). */
    public static function flush(): void
    {
        self::$cards = null;
        self::$picked = [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function build(): array
    {
        $out = [];

        $rows = DB::table('programs_catalog')
            ->whereNotNull('legacy_program_id')
            ->get(['legacy_program_id', 'ds_percent', 'tariffs', 'commission_calc_property', 'kv_payout_year']);

        foreach ($rows as $r) {
            $out[(int) $r->legacy_program_id] = [
                'rows' => self::usableRows($r->tariffs),
                'prop' => mb_strtolower(trim((string) ($r->commission_calc_property ?? ''))),
                'year' => trim((string) ($r->kv_payout_year ?? '')),
                'ds' => $r->ds_percent !== null && (float) $r->ds_percent > 0 ? (float) $r->ds_percent : null,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $card */
    private static function pick(array $card, ?string $term): ?float
    {
        $rows = $card['rows'];

        // Строк нет — тогда «%ДС программы».
        if (! $rows) {
            return $card['ds'];
        }

        // 0) Строки на срок договора, если такие есть.
        if ($term !== null) {
            $sameTerm = array_values(array_filter(
                $rows,
                fn ($row) => self::normalizeTerm($row['raw']['term'] ?? null) === $term,
            ));
            if ($sameTerm) {
                $rows = $sameTerm;
            }
        }

        // 1) Свойство / год выплаты, заданные в карточке.
        if ($card['prop'] !== '') {
            foreach ($rows as $row) {
                if (mb_strtolower(trim((string) ($row['raw']['property'] ?? ''))) === $card['prop']) {
                    return $row['pct'];
                }
            }
        }
        if ($card['year'] !== '') {
            foreach ($rows as $row) {
                if ((string) ($row['raw']['year_kv'] ?? '') === $card['year']) {
                    return $row['pct'];
                }
            }
        }

        // 2) Апфронт — ставка момента сделки.
        foreach ($rows as $row) {
            if (in_array(mb_strtolower(trim((string) ($row['raw']['property'] ?? ''))), self::UPFRONT, true)) {
                return $row['pct'];
            }
        }

        // 3) Первый год КВ.
        foreach ($rows as $row) {
            if ((string) ($row['raw']['year_kv'] ?? '') === '1') {
                return $row['pct'];
            }
        }

        // 4) Первая строка карточки.
        return $rows[0]['pct'];
    }

    /**
     * Годные строки тарифа: не «Искл.» и с разобранным процентом.
     *
     * @return list<array{pct: float, raw: array<mixed>}>
     */
    private static function usableRows(mixed $tariffs): array
    {
        $tariffs = json_decode((string) $tariffs, true);
        if (! is_array($tariffs)) {
            return [];
        }

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

        return $rows;
    }

    /** «14» / 14 / « 14 » → «14»; пусто или не целое число → null. */
    private static function normalizeTerm(mixed $term): ?string
    {
        if ($term === null) {
            return null;
        }
        $s = trim((string) $term);

        return ctype_digit($s) ? (string) (int) $s : null;
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
