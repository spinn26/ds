<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Синк %ДС из каталога «Продукты» (programs_catalog.tariffs) в legacy-таблицу
 * `dsCommission` (по ней считаются комиссии транзакций). Источник — Продукты.
 *
 * Вызывается при сохранении тарифов (AdminProductCatalogController) и из
 * команды products:sync-dscommission.
 *
 * БЕЗОПАСНО: UPDATE comission у ОДНОЗНАЧНО сопоставленных строк ТЕКУЩЕГО окна
 * дат + INSERT строки, если по ключу (term × свойство/год) её нет ВООБЩЕ.
 * Ничего не удаляет, при неоднозначности (>1 совпадение) — пропускает.
 * is_red-строки (старые контракты) не трогает — чтобы историческая ставка не
 * перезаписала текущее окно.
 *
 * Зачем INSERT (2026-08-06): у новых программ (напр. «Эволюция ГГА» program=1647,
 * 34 контракта) строк в dsCommission не было вообще, синк молча пропускал их как
 * «совпадений 0», и расчёт падал с «Не найден тариф %ДС» — при заведённом в
 * карточке проценте. Каталог = источник истины, поэтому недостающую строку
 * создаём.
 */
class DsCommissionSync
{
    private const PROP_ALIAS = [
        'sf' => 'СФ', 'сф' => 'СФ', 'mf' => 'МФ', 'мф' => 'МФ',
        'upfront' => 'Апфронт', 'апфронт' => 'Апфронт',
        'стандарт' => 'Стандарт', 'standard' => 'Стандарт',
        'регулярная' => 'Регулярная', 'regular' => 'Регулярная',
        'единоразовая' => 'Единоразовая',
    ];

    /** Окно дат создаваемой строки — как у исторических строк Directual. */
    private const NEW_ROW_DATE = '2000-01-01 00:00:00';

    private const NEW_ROW_DATE_FINISH = '2050-01-01 00:00:00';

    /**
     * @param  array<int,array<string,mixed>>  $tariffs
     * @param  bool  $fillGaps  создавать строки и там, где расчёт СЕЙЧАС
     *   работает. По умолчанию false — создаём только программам, у которых
     *   расчёт реально падает: строк dsCommission нет вообще И program.dsPercent
     *   пуст. Причины ограничения:
     *   - частично заполненная программа доезжает по фолбэку «любая строка
     *     программы» (resolveLegacyDsCommission);
     *   - при заданном program.dsPercent новая строка со свойством ПЕРЕБИВАЕТ
     *     его (см. CommissionCalculator, ветка property-specific), а расходятся
     *     они сильно (напр. «Стиль Жизни»: dsPercent 4.28 vs каталог 34–77).
     *   В обоих случаях достройка МЕНЯЕТ действующие ставки — это отдельное
     *   решение, только через products:sync-dscommission --fill-gaps.
     * @return array{updated:int, created:int, diffs:array<int,string>, skips:array<int,string>}
     */
    public static function syncFromTariffs(int $legacyProgramId, array $tariffs, bool $apply, bool $fillGaps = false): array
    {
        $ccpByTitle = DB::table('commissionCalcProperty')->pluck('id', 'title');
        $now = now()->toDateTimeString();

        $rows = DB::table('dsCommission')
            ->where('program', $legacyProgramId)
            ->where('active', true)->whereNull('dateDeleted')
            ->get(['id', 'termContract', 'commissionCalcProperty', 'comission', 'date', 'dateFinish']);

        $programDsPercent = DB::table('program')->where('id', $legacyProgramId)->value('dsPercent');
        $mayCreate = $fillGaps || ($rows->isEmpty() && $programDsPercent === null);

        $updated = 0;
        $created = 0;
        $diffs = [];
        $skips = [];

        foreach ($tariffs as $t) {
            if (! empty($t['is_red'])) {
                continue; // историческую ставку в текущее окно не пишем
            }
            $pct = self::parsePct($t);
            if ($pct === null) {
                continue;
            }

            $term = isset($t['term']) && $t['term'] !== '' ? (int) $t['term'] : null;

            $ccpId = null;
            if (! empty($t['year_kv'])) {
                $ccpId = $ccpByTitle[((int) $t['year_kv']) . ' год'] ?? null;
                if ($ccpId === null) {
                    $skips[] = "год {$t['year_kv']} — нет в справочнике";
                    continue;
                }
            } elseif (! empty($t['property'])) {
                $title = self::PROP_ALIAS[mb_strtolower(trim((string) $t['property']))] ?? null;
                $ccpId = $title !== null ? ($ccpByTitle[$title] ?? null) : null;
                if ($ccpId === null) {
                    $skips[] = "свойство «{$t['property']}» — нет алиаса";
                    continue;
                }
            }

            $match = $rows->filter(function ($r) use ($term, $ccpId) {
                $rt = $r->termContract === null || $r->termContract === '' ? null : (int) $r->termContract;
                if ($rt !== $term) {
                    return false;
                }
                return $ccpId === null ? true : (int) $r->commissionCalcProperty === $ccpId;
            });

            if ($ccpId === null && $match->count() > 1 && $rows->count() === 1) {
                $match = $rows;
            }
            if ($match->count() > 1) {
                $current = $match->filter(fn ($r) => (string) $r->date <= $now && (string) $r->dateFinish >= $now);
                if ($current->count() === 1) {
                    $match = $current;
                }
            }
            if ($match->count() === 0) {
                // Строки по этому ключу нет вообще — создаём. Иначе процент,
                // заведённый в карточке продукта, не доезжает до калькулятора
                // и расчёт падает «Не найден тариф %ДС».
                if (! $mayCreate) {
                    $skips[] = "term=" . ($term ?? '—') . " ccp=" . ($ccpId ?? '—') . " → нет строки (создание только с --fill-gaps)";
                    continue;
                }
                if ($pct <= 0) {
                    $skips[] = "term=" . ($term ?? '—') . " ccp=" . ($ccpId ?? '—') . " → нет строки, %ДС={$pct} — не создаём";
                    continue;
                }
                $diffs[] = "NEW term=" . ($term ?? '—') . " ccp=" . ($ccpId ?? '—') . ": " . round($pct, 2);
                if ($apply) {
                    $newRow = self::insertRow($legacyProgramId, $term, $ccpId, $pct);
                    if ($newRow === null) {
                        array_pop($diffs);
                        $skips[] = "term=" . ($term ?? '—') . " ccp=" . ($ccpId ?? '—') . " → нет legacy-программы {$legacyProgramId}";
                        continue;
                    }
                    // Чтобы следующие строки тарифа видели уже созданную и не
                    // плодили дубли по тому же ключу.
                    $rows->push($newRow);
                }
                $created++;
                continue;
            }

            if ($match->count() !== 1) {
                $skips[] = "term=" . ($term ?? '—') . " ccp=" . ($ccpId ?? '—') . " → совпадений {$match->count()}";
                continue;
            }

            $row = $match->first();
            if (round((float) $row->comission, 2) !== round($pct, 2)) {
                $diffs[] = "term=" . ($term ?? '—') . " ccp=" . ($ccpId ?? '—') . ": {$row->comission} → " . round($pct, 2);
                if ($apply) {
                    DB::table('dsCommission')->where('id', $row->id)->update(['comission' => round($pct, 4)]);
                }
                $updated++;
            }
        }

        return ['updated' => $updated, 'created' => $created, 'diffs' => $diffs, 'skips' => $skips];
    }

    /**
     * Создаёт строку dsCommission для legacy-программы. У таблицы (наследие
     * Directual) НЕТ сиквенса на id — считаем max(id)+1 в транзакции, как это
     * уже делает AdminProductCatalogController для legacy program/product.
     * Возвращает объект в форме выборки rows (или null, если программы нет).
     */
    private static function insertRow(int $legacyProgramId, ?int $term, ?int $ccpId, float $pct): ?object
    {
        return DB::transaction(function () use ($legacyProgramId, $term, $ccpId, $pct) {
            $program = DB::table('program')->where('id', $legacyProgramId)->first(['id', 'name', 'product']);
            if (! $program) {
                return null;
            }
            $productName = $program->product
                ? DB::table('product')->where('id', $program->product)->value('name')
                : null;

            $id = (int) (DB::table('dsCommission')->max('id') ?? 0) + 1;

            DB::table('dsCommission')->insert([
                'id'                     => $id,
                'program'                => $legacyProgramId,
                'programName'            => $program->name,
                'product'                => $program->product,
                'productName'            => $productName,
                'termContract'           => $term,
                // Без года/свойства пишем «Стандарт» — так заведены плоские
                // тарифы легаси (напр. program=296 «Эволюция ГГА 10 лет»).
                'commissionCalcProperty' => $ccpId ?? 1,
                'comission'              => round($pct, 4),
                'commissionAbsolute'     => 0,
                'active'                 => true,
                'date'                   => self::NEW_ROW_DATE,
                'dateFinish'             => self::NEW_ROW_DATE_FINISH,
            ]);

            return (object) [
                'id'                     => $id,
                'termContract'           => $term,
                'commissionCalcProperty' => $ccpId ?? 1,
                'comission'              => round($pct, 4),
                'date'                   => self::NEW_ROW_DATE,
                'dateFinish'             => self::NEW_ROW_DATE_FINISH,
            ];
        });
    }

    /** %ДС из строки тарифа: ds_pct («3,45%»/«3.45») в процентах, либо ds_percent (доля 0..1)×100. */
    private static function parsePct(array $t): ?float
    {
        $raw = $t['ds_pct'] ?? null;
        if (is_string($raw) && preg_match('/^[0-9]+([.,][0-9]+)?%?$/u', trim($raw))) {
            return (float) str_replace(',', '.', str_replace('%', '', trim($raw)));
        }
        $frac = $t['ds_percent'] ?? null;
        if ($frac !== null && is_numeric(str_replace(',', '.', (string) $frac))) {
            return (float) str_replace(',', '.', (string) $frac) * 100;
        }
        return null;
    }
}
