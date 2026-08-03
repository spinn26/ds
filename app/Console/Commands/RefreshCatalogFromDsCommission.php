<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Актуализирует каталог «Продукты» (programs_catalog.tariffs, JSONB — UI +
 * калькулятор объёмов) из ТЕКУЩЕГО окна дат `dsCommission` (по которому реально
 * считаются комиссии). Каталог местами отстал (напр. инвест-фонды: в каталоге
 * ставки 2023-го, в dsCommission — обновление с 2023-10). Здесь движок/деньги
 * НЕ трогаются — только приводим витрину в соответствие фактическим ставкам.
 *
 * Делает две вещи (только по НЕ-is_red строкам — is_red = старые контракты,
 * их исторические ставки не трогаем):
 *   1) UPDATE ds_pct существующей строки каталога → значение текущего окна dsCommission.
 *   2) ADD недостающих ГОД-строк (dsCommission имеет год, которого нет в каталоге).
 *
 * Матчинг: term ↔ termContract; год N ↔ commissionCalcProperty с title «N год»;
 * свойство ↔ ccp по алиасу. Без --apply — dry-run.
 */
class RefreshCatalogFromDsCommission extends Command
{
    protected $signature = 'products:refresh-catalog {--apply : применить (иначе dry-run)} {--program= : одна legacy-программа}';

    protected $description = 'Актуализировать каталог Продуктов из текущего окна dsCommission (+добавить недостающие года)';

    private const PROP_ALIAS = [
        'sf' => 'СФ', 'сф' => 'СФ', 'mf' => 'МФ', 'мф' => 'МФ',
        'upfront' => 'Апфронт', 'апфронт' => 'Апфронт',
        'стандарт' => 'Стандарт', 'standard' => 'Стандарт',
        'регулярная' => 'Регулярная', 'regular' => 'Регулярная',
        'единоразовая' => 'Единоразовая',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyProgram = $this->option('program') !== null ? (int) $this->option('program') : null;

        $ccpById = DB::table('commissionCalcProperty')->pluck('title', 'id');   // id => title
        $ccpByTitle = $ccpById->flip();                                          // title => id

        $programs = DB::table('programs_catalog')
            ->whereNotNull('legacy_program_id')->where('active', true)
            ->when($onlyProgram !== null, fn ($q) => $q->where('legacy_program_id', $onlyProgram))
            ->get(['id', 'legacy_program_id', 'name', 'tariffs', 'currency']);

        $now = now()->toDateTimeString();
        $updated = 0;
        $added = 0;
        $progChanged = 0;
        $log = [];

        foreach ($programs as $pr) {
            $tariffs = json_decode((string) $pr->tariffs, true);
            if (! is_array($tariffs)) {
                continue;
            }

            // Текущее окно dsCommission по программе: [term|null][ccpId] => row.
            $dsRows = DB::table('dsCommission')
                ->where('program', $pr->legacy_program_id)
                ->where('active', true)->whereNull('dateDeleted')
                ->whereRaw('date <= ? AND "dateFinish" >= ?', [$now, $now])
                ->get(['id', 'termContract', 'commissionCalcProperty', 'comission']);

            $dsByKey = [];   // "term|ccp" => [comission, matched=false]
            foreach ($dsRows as $r) {
                $t = $r->termContract === null || $r->termContract === '' ? '' : (int) $r->termContract;
                $dsByKey[$t . '|' . (int) $r->commissionCalcProperty] = ['pct' => (float) $r->comission, 'used' => false];
            }

            $changed = false;

            // 1) Обновляем существующие (не is_red) строки каталога.
            foreach ($tariffs as $i => $row) {
                $ccpId = $this->rowCcp($row, $ccpByTitle);
                $term = isset($row['term']) && $row['term'] !== '' ? (int) $row['term'] : '';

                $key = $ccpId !== null ? ($term . '|' . $ccpId) : null;
                if ($key === null && count($dsByKey) === 1 && empty($row['year_kv']) && empty($row['property'])) {
                    $key = array_key_first($dsByKey);
                }
                if ($key === null || ! isset($dsByKey[$key])) {
                    continue;
                }
                // Помечаем ключ занятым даже для is_red — чтобы блок ADD не создал дубль.
                $dsByKey[$key]['used'] = true;
                // Историческую (is_red) ставку не трогаем.
                if (! empty($row['is_red'])) {
                    continue;
                }
                $cur = $this->parsePct($row);
                $target = round($dsByKey[$key]['pct'], 2);
                if ($cur === null || round($cur, 2) !== $target) {
                    $tariffs[$i]['ds_pct'] = $this->fmt($target);
                    $changed = true;
                    $updated++;
                    if (count($log) < 60) {
                        $log[] = sprintf('UPD %-24s %s → %s', mb_substr($pr->name, 0, 24), $cur ?? '—', $this->fmt($target));
                    }
                }
            }

            // 2) Добавляем недостающие ГОД-строки (ccp title = "N год").
            foreach ($dsByKey as $k => $v) {
                if ($v['used']) {
                    continue;
                }
                [$term, $ccpId] = array_map(fn ($x) => $x === '' ? null : (int) $x, explode('|', $k));
                $title = (string) ($ccpById[$ccpId] ?? '');
                if (! preg_match('/^(\d+) год$/u', $title, $m)) {
                    continue; // добавляем только года, не свойства
                }
                $tariffs[] = [
                    'term' => $term !== null ? (string) $term : null,
                    'year_kv' => $m[1],
                    'ds_pct' => $this->fmt(round($v['pct'], 2)),
                    'property' => null,
                    'formula' => null,
                    'comment' => 'добавлено из dsCommission (актуализация)',
                    'currency' => $pr->currency,
                    'is_red' => false,
                ];
                $changed = true;
                $added++;
                if (count($log) < 60) {
                    $log[] = sprintf('ADD %-24s год %s (срок %s) = %s', mb_substr($pr->name, 0, 24), $m[1], $term ?? '—', $this->fmt(round($v['pct'], 2)));
                }
            }

            if ($changed) {
                $progChanged++;
                if ($apply) {
                    $terms = [];
                    $years = [];
                    foreach ($tariffs as $t) {
                        if (($t['term'] ?? null) !== null) $terms[(string) $t['term']] = true;
                        if (($t['year_kv'] ?? null) !== null) $years[(string) $t['year_kv']] = true;
                    }
                    DB::table('programs_catalog')->where('id', $pr->id)->update([
                        'tariffs' => json_encode(array_values($tariffs), JSON_UNESCAPED_UNICODE),
                        'rate_lines' => count($tariffs),
                        'terms_summary' => $terms ? implode(',', array_keys($terms)) : null,
                        'years_summary' => $years ? implode(',', array_keys($years)) : null,
                    ]);
                }
            }
        }

        $this->info(($apply ? 'ПРИМЕНЕНО' : 'DRY-RUN') . " — программ изменено: {$progChanged}, обновлено строк: {$updated}, добавлено: {$added}");
        foreach ($log as $l) {
            $this->line('  ' . $l);
        }

        return self::SUCCESS;
    }

    /** ccpId строки каталога из year_kv («N год») или property (алиас). */
    private function rowCcp(array $row, $ccpByTitle): ?int
    {
        if (! empty($row['year_kv'])) {
            return $ccpByTitle[((int) $row['year_kv']) . ' год'] ?? null;
        }
        if (! empty($row['property'])) {
            $title = self::PROP_ALIAS[mb_strtolower(trim((string) $row['property']))] ?? null;
            return $title !== null ? ($ccpByTitle[$title] ?? null) : null;
        }
        return null;
    }

    private function parsePct(array $t): ?float
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

    private function fmt(float $pct): string
    {
        return number_format($pct, 2, '.', '') . '%';
    }
}
