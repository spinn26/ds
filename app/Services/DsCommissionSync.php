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
 * БЕЗОПАСНО: только UPDATE comission у ОДНОЗНАЧНО сопоставленных строк ТЕКУЩЕГО
 * окна дат. Ничего не создаёт/не удаляет. is_red-строки (старые контракты) не
 * трогает — чтобы историческая ставка не перезаписала текущее окно.
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

    /**
     * @param  array<int,array<string,mixed>>  $tariffs
     * @return array{updated:int, diffs:array<int,string>, skips:array<int,string>}
     */
    public static function syncFromTariffs(int $legacyProgramId, array $tariffs, bool $apply): array
    {
        $ccpByTitle = DB::table('commissionCalcProperty')->pluck('id', 'title');
        $now = now()->toDateTimeString();

        $rows = DB::table('dsCommission')
            ->where('program', $legacyProgramId)
            ->where('active', true)->whereNull('dateDeleted')
            ->get(['id', 'termContract', 'commissionCalcProperty', 'comission', 'date', 'dateFinish']);

        $updated = 0;
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

        return ['updated' => $updated, 'diffs' => $diffs, 'skips' => $skips];
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
