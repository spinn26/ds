<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Синхронизирует %ДС из каталога «Продукты» (programs_catalog.tariffs, JSONB —
 * то, что редактирует оператор) в legacy-таблицу `dsCommission`, по которой
 * реально считаются комиссии транзакций. Источник истины — Продукты.
 *
 * Матчинг строки каталога → строки dsCommission (в рамках одной legacy-программы):
 *   term      = tariff.term        <-> dsCommission.termContract
 *   год КВ N  = tariff.year_kv      <-> dsCommission.commissionCalcProperty = id, где title = "N год"
 *   свойство  = tariff.property     <-> dsCommission.commissionCalcProperty по алиасу (SF↔СФ, MF↔МФ, upfront↔Апфронт…)
 *   без свойства/года и один ряд у программы → сопоставляем этот единственный ряд.
 *
 * БЕЗОПАСНО: только UPDATE comission у ОДНОЗНАЧНО сопоставленных строк.
 * Ничего не создаёт и не удаляет. Неоднозначные (0 или >1 совпадений) —
 * пропускает и логирует. Без --apply — dry-run (только показывает дифф).
 */
class SyncDsCommissionFromCatalog extends Command
{
    protected $signature = 'products:sync-dscommission {--apply : применить изменения (иначе dry-run)} {--program= : только одна legacy-программа}';

    protected $description = 'Синхронизировать %ДС из каталога Продуктов в dsCommission (источник расчёта комиссий)';

    /** Алиасы свойства каталога (нижний регистр) → title в commissionCalcProperty. */
    private const PROP_ALIAS = [
        'sf' => 'СФ', 'сф' => 'СФ',
        'mf' => 'МФ', 'мф' => 'МФ',
        'upfront' => 'Апфронт', 'апфронт' => 'Апфронт',
        'стандарт' => 'Стандарт', 'standard' => 'Стандарт',
        'регулярная' => 'Регулярная', 'regular' => 'Регулярная',
        'единоразовая' => 'Единоразовая',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyProgram = $this->option('program') !== null ? (int) $this->option('program') : null;

        // title → id справочника свойств (в т.ч. "1 год".."19 год", "СФ", "Апфронт"…)
        $ccpByTitle = DB::table('commissionCalcProperty')->pluck('id', 'title');

        $programs = DB::table('programs_catalog')
            ->whereNotNull('legacy_program_id')
            ->where('active', true)
            ->when($onlyProgram !== null, fn ($q) => $q->where('legacy_program_id', $onlyProgram))
            ->get(['legacy_program_id', 'name', 'tariffs']);

        $updated = 0;
        $diffs = [];
        $skips = [];

        foreach ($programs as $pr) {
            $tariffs = json_decode((string) $pr->tariffs, true);
            if (! is_array($tariffs)) {
                continue;
            }
            $rows = DB::table('dsCommission')
                ->where('program', $pr->legacy_program_id)
                ->where('active', true)
                ->whereNull('dateDeleted')
                ->get(['id', 'termContract', 'commissionCalcProperty', 'comission', 'date', 'dateFinish']);

            foreach ($tariffs as $t) {
                $pct = $this->parsePct($t);
                if ($pct === null) {
                    continue;
                }

                $term = isset($t['term']) && $t['term'] !== '' ? (int) $t['term'] : null;

                // Целевой commissionCalcProperty из года или свойства.
                $ccpId = null;
                if (! empty($t['year_kv'])) {
                    $ccpId = $ccpByTitle[((int) $t['year_kv']) . ' год'] ?? null;
                    if ($ccpId === null) {
                        $skips[] = "{$pr->name}: год {$t['year_kv']} — нет в справочнике";
                        continue;
                    }
                } elseif (! empty($t['property'])) {
                    $title = self::PROP_ALIAS[mb_strtolower(trim((string) $t['property']))] ?? null;
                    $ccpId = $title !== null ? ($ccpByTitle[$title] ?? null) : null;
                    if ($ccpId === null) {
                        $skips[] = "{$pr->name}: свойство «{$t['property']}» — нет алиаса/справочника";
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

                // Программа без свойства/года и с единственной строкой — сопоставляем её.
                if ($ccpId === null && $match->count() > 1 && $rows->count() === 1) {
                    $match = $rows;
                }

                // Несколько строк по одному ключу = разные окна дат. Берём текущее
                // (date ≤ сегодня ≤ dateFinish) — именно его использует движок расчёта.
                if ($match->count() > 1) {
                    $today = now()->toDateTimeString();
                    $current = $match->filter(fn ($r) => (string) $r->date <= $today && (string) $r->dateFinish >= $today);
                    if ($current->count() === 1) {
                        $match = $current;
                    }
                }

                if ($match->count() !== 1) {
                    $skips[] = "{$pr->name}: term={$term} ccp=" . ($ccpId ?? '—') . " → совпадений {$match->count()}";
                    continue;
                }

                $row = $match->first();
                if (round((float) $row->comission, 2) !== round($pct, 2)) {
                    $diffs[] = sprintf('%-28s term=%-3s ccp=%-3s  %6s → %-6s', mb_substr($pr->name, 0, 28),
                        $term ?? '—', $ccpId ?? '—', round((float) $row->comission, 2), round($pct, 2));
                    if ($apply) {
                        DB::table('dsCommission')->where('id', $row->id)->update(['comission' => round($pct, 4)]);
                    }
                    $updated++;
                }
            }
        }

        $this->info(($apply ? 'ПРИМЕНЕНО' : 'DRY-RUN') . " — расхождений: {$updated}");
        foreach ($diffs as $d) {
            $this->line('  ' . $d);
        }
        if ($skips) {
            $this->warn('Пропущено (неоднозначно/нет ключа): ' . count($skips));
            foreach (array_slice($skips, 0, 40) as $s) {
                $this->line('  · ' . $s);
            }
            if (count($skips) > 40) {
                $this->line('  … и ещё ' . (count($skips) - 40));
            }
        }

        return self::SUCCESS;
    }

    /** %ДС из строки тарифа: ds_pct («3,45%»/«3.45») в процентах, либо ds_percent (доля 0..1) ×100. */
    private function parsePct(array $t): ?float
    {
        $raw = $t['ds_pct'] ?? null;
        if (is_string($raw) && preg_match('/^[0-9]+([.,][0-9]+)?%?$/u', trim($raw))) {
            return (float) str_replace(',', '.', str_replace('%', '', trim($raw)));
        }
        $frac = $t['ds_percent'] ?? null;
        if (is_numeric($frac !== null ? str_replace(',', '.', (string) $frac) : null)) {
            return (float) str_replace(',', '.', (string) $frac) * 100;
        }
        return null;
    }
}
