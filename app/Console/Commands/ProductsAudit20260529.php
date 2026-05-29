<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Аудит каталога продуктов 2026-05-29 по обновлённой Google-таблице
 * 1dmBOUs4bPFDSxOu0uBk1FJ4n8-RkANOJhoSmDWqLJkc (лист ПРОДУКТЫ).
 *
 * Что делает (опции взаимонезависимые):
 *  --apply           : скрывает программы которые в листе ВСЕ red (visible→false)
 *  --update-tariffs  : переписывает programs_catalog.tariffs jsonb по данным из листа.
 *                      Каждая строка-тариф листа = объект {property, ds_pct,
 *                      points, price, currency, term, year_kv, formula, comment}.
 *                      Работает только для УЖЕ существующих в catalog программ —
 *                      не реактивирует archived продукты, не создаёт новые
 *                      (это политическое решение, делается вручную).
 *  --create-missing  : (deprecated, оставлен для совместимости) — создаёт
 *                      новые продукты/программы. Использовать с осторожностью.
 *
 * По умолчанию dry-run.
 */
class ProductsAudit20260529 extends Command
{
    protected $signature = 'products:audit-20260529
        {path : Путь к JSON-выгрузке Google Sheets}
        {--apply : Скрыть red-программы}
        {--update-tariffs : Перезаписать tariffs jsonb для существующих программ}
        {--create-missing : Создать в catalog те программы, которых там нет}';

    protected $description = 'Diff/apply нового списка продуктов из Google Sheets';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        $this->info("Парсинг {$path}…");
        $rows = $this->parseSheet($path);
        $this->info('Найдено строк продуктов: ' . count($rows));

        // Группируем по (product, program). Каждая строка-тариф остаётся
        // в массиве `lines` — пригодится для tariffs jsonb.
        $byProgram = [];
        foreach ($rows as $r) {
            $p = trim((string) ($r['product'] ?? ''));
            $pr = trim((string) ($r['program'] ?? ''));
            if ($p === '') continue;
            $key = mb_strtolower("{$p}||{$pr}");
            if (! isset($byProgram[$key])) {
                $byProgram[$key] = [
                    'product' => $p,
                    'program' => $pr,
                    'category' => $r['category'] ?? null,
                    'supplier' => $r['supplier'] ?? null,
                    'red_count' => 0,
                    'total_count' => 0,
                    'lines' => [],
                ];
            }
            $byProgram[$key]['total_count']++;
            if (! empty($r['is_red'])) $byProgram[$key]['red_count']++;
            $byProgram[$key]['lines'][] = [
                'property' => $r['property'] ?? null,
                'ds_pct' => $r['ds_pct'] ?? null,
                'points' => $r['points'] ?? null,
                'price' => $r['price'] ?? null,
                'currency' => $r['currency'] ?? null,
                'term' => $r['term'] ?? null,
                'year_kv' => $r['year_kv'] ?? null,
                'formula' => $r['formula'] ?? null,
                'comment' => $r['comment'] ?? null,
                'is_red' => (bool) ($r['is_red'] ?? false),
            ];
        }

        foreach ($byProgram as $k => $g) {
            $byProgram[$k]['active'] = $g['red_count'] < $g['total_count'];
        }

        $activeCnt = count(array_filter($byProgram, fn ($g) => $g['active']));
        $inactiveCnt = count($byProgram) - $activeCnt;
        $this->line('');
        $this->info('Уникальных программ в листе: ' . count($byProgram));
        $this->info("  активных: {$activeCnt}");
        $this->warn("  неактивных (red): {$inactiveCnt}");

        // Загружаем текущий catalog (все программы, не только active —
        // чтобы tariffs можно было заодно проставить и hidden).
        $catalog = DB::table('programs_catalog as pg')
            ->join('products_catalog as pc', 'pc.id', '=', 'pg.product_id')
            ->orderBy('pc.name')->orderBy('pg.name')
            ->get([
                'pg.id', 'pg.name as program_name', 'pg.active as pg_active',
                'pg.visible_to_resident', 'pg.visible_to_calculator', 'pg.tariffs',
                'pc.id as product_id', 'pc.name as product_name',
                'pc.active as product_active',
            ]);

        $catalogPairs = [];
        foreach ($catalog as $row) {
            $catalogPairs[mb_strtolower(trim($row->product_name) . '||' . trim($row->program_name))] = $row;
        }

        $toHide = [];          // red, есть в catalog, currently visible
        $toUpdateTariffs = []; // matched, можно записать tariffs
        $missingInCatalog = [];

        foreach ($byProgram as $key => $g) {
            $catalogRow = $catalogPairs[$key] ?? null;
            if (! $catalogRow) {
                if ($g['active']) $missingInCatalog[] = $g;
                continue;
            }
            $currentlyVisible = (bool) $catalogRow->visible_to_resident
                && (bool) $catalogRow->visible_to_calculator;
            if (! $g['active'] && $currentlyVisible) {
                $toHide[] = ['catalog' => $catalogRow, 'sheet' => $g];
            }
            // Tariffs всегда переписываем (если --update-tariffs), даже если
            // программа сейчас hidden — данные тарифа важны для admin-карточки.
            $toUpdateTariffs[] = ['catalog' => $catalogRow, 'sheet' => $g];
        }

        $this->line('');
        $this->info('=== СВОДКА ===');
        $this->line('К скрытию (red, visible): ' . count($toHide));
        $this->line('К обновлению tariffs (matched): ' . count($toUpdateTariffs));
        $this->warn('В листе, нет в каталоге: ' . count($missingInCatalog));

        if ($this->getOutput()->isVerbose()) {
            $this->line('');
            $this->warn('--- К скрытию (red) ---');
            foreach (array_slice($toHide, 0, 30) as $t) {
                $this->line("  - {$t['catalog']->product_name} → {$t['catalog']->program_name}");
            }
            $this->line('');
            $this->warn('--- Отсутствуют (active в листе) ---');
            foreach (array_slice($missingInCatalog, 0, 30) as $m) {
                $this->line("  ? {$m['product']} → {$m['program']}");
            }
        }

        $apply = (bool) $this->option('apply');
        $updateTariffs = (bool) $this->option('update-tariffs');
        $createMissing = (bool) $this->option('create-missing');

        if (! $apply && ! $updateTariffs && ! $createMissing) {
            $this->line('');
            $this->info('Dry-run. Опции:');
            $this->info('  --apply           — скрыть red');
            $this->info('  --update-tariffs  — записать tariffs jsonb');
            $this->info('  --create-missing  — создать недостающие (НЕ реактивирует archived)');
            return self::SUCCESS;
        }

        if ($apply) {
            $this->line('');
            $this->warn('=== APPLY (hide red) ===');
            $n = 0;
            DB::transaction(function () use ($toHide, &$n) {
                foreach ($toHide as $t) {
                    DB::table('programs_catalog')->where('id', $t['catalog']->id)->update([
                        'visible_to_resident' => false,
                        'visible_to_calculator' => false,
                        'updated_at' => now(),
                    ]);
                    $n++;
                }
            });
            $this->info("Скрыто программ: {$n}");
        }

        if ($updateTariffs) {
            $this->line('');
            $this->warn('=== UPDATE TARIFFS ===');
            $n = 0;
            DB::transaction(function () use ($toUpdateTariffs, &$n) {
                foreach ($toUpdateTariffs as $t) {
                    $lines = $t['sheet']['lines'] ?? [];
                    // rate_lines + terms_summary + years_summary — для удобства
                    // показа в списке. tariffs — детальный jsonb.
                    $terms = array_values(array_unique(array_filter(array_map(fn ($l) => $l['term'] ?? null, $lines))));
                    $years = array_values(array_unique(array_filter(array_map(fn ($l) => $l['year_kv'] ?? null, $lines))));

                    DB::table('programs_catalog')->where('id', $t['catalog']->id)->update([
                        'tariffs' => json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        'rate_lines' => count($lines),
                        'terms_summary' => $terms ? mb_substr(implode(', ', $terms), 0, 250) : null,
                        'years_summary' => $years ? mb_substr(implode(', ', $years), 0, 250) : null,
                        'vendor' => $t['sheet']['supplier'] ?? null,
                        'category' => $t['sheet']['category'] ?? null,
                        'has_red' => $t['sheet']['red_count'] === $t['sheet']['total_count'],
                        'updated_at' => now(),
                    ]);
                    $n++;
                }
            });
            $this->info("Обновлено tariffs у программ: {$n}");
        }

        if ($createMissing) {
            $this->line('');
            $this->warn('=== CREATE MISSING ===');
            $this->warn('Не реактивируем archived продукты. Создаём ТОЛЬКО для уже-active продуктов.');
            [$pNew, $prNew, $skipped] = $this->createMissing($missingInCatalog);
            $this->info("Создано продуктов: {$pNew}");
            $this->info("Создано программ: {$prNew}");
            $this->info("Пропущено (archived product): {$skipped}");
        }

        return self::SUCCESS;
    }

    /**
     * Создание новых (product, program) пар.
     * Реактивацию archived НЕ делаем — это политическое решение и должно
     * быть ручным в админ-UI.
     *
     * @param  list<array<string,mixed>>  $missing
     * @return array{0:int,1:int,2:int} [createdProducts, createdPrograms, skippedArchived]
     */
    private function createMissing(array $missing): array
    {
        if (! $missing) return [0, 0, 0];

        $byProduct = [];
        foreach ($missing as $m) {
            $byProduct[$m['product']][] = $m;
        }

        $prodCreated = 0;
        $progCreated = 0;
        $skipped = 0;
        $importedFrom = 'sheet-2026-05-29';

        DB::transaction(function () use ($byProduct, &$prodCreated, &$progCreated, &$skipped, $importedFrom) {
            foreach ($byProduct as $productName => $programs) {
                $existing = DB::table('products_catalog')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($productName))])
                    ->first(['id', 'active']);

                if ($existing) {
                    if (! $existing->active) {
                        // НЕ реактивируем — пропускаем все его программы.
                        $skipped += count($programs);
                        continue;
                    }
                    $productId = $existing->id;
                } else {
                    $productId = DB::table('products_catalog')->insertGetId([
                        'name' => $productName,
                        'type' => $programs[0]['category'] ?? null,
                        'active' => true,
                        'visible_to_resident' => true,
                        'visible_to_calculator' => true,
                        'imported_from' => $importedFrom,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $prodCreated++;
                }

                foreach ($programs as $g) {
                    $exists = DB::table('programs_catalog')
                        ->where('product_id', $productId)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($g['program']))])
                        ->exists();
                    if ($exists) continue;

                    $lines = $g['lines'] ?? [];
                    DB::table('programs_catalog')->insert([
                        'product_id' => $productId,
                        'name' => $g['program'] ?: '—',
                        'vendor' => $g['supplier'] ?? null,
                        'category' => $g['category'] ?? null,
                        'active' => true,
                        'visible_to_resident' => true,
                        'visible_to_calculator' => true,
                        'has_red' => false,
                        'rate_lines' => count($lines),
                        'tariffs' => json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        'imported_from' => $importedFrom,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $progCreated++;
                }
            }
        });

        return [$prodCreated, $progCreated, $skipped];
    }

    /**
     * Парсит JSON-выгрузку Sheets v4 (includeGridData).
     *
     * Колонки (zero-index): 2=Тип, 3=Продукт, 4=Программа, 5=Стоимость,
     * 6=Валюта, 7=Поставщик, 8=%DS, 9=Свойство, 10=Срок, 11=ГодКВ,
     * 12=Баллы, 13=Методика, 14=Комментарий, 15=Категория.
     */
    private function parseSheet(string $path): array
    {
        $raw = json_decode(file_get_contents($path), true);
        $rowsData = $raw['sheets'][0]['data'][0]['rowData'] ?? [];

        $out = [];
        foreach ($rowsData as $i => $row) {
            if ($i < 2) continue; // header + col-numbers
            $cells = $row['values'] ?? [];
            $get = function (int $idx) use ($cells) {
                $v = $cells[$idx]['formattedValue'] ?? null;
                return is_string($v) ? trim($v) : $v;
            };
            $product = $get(3);
            $program = $get(4);

            // Skip пустых и section-headers (нет product+program+price+points).
            $price = $get(5);
            $points = $get(12);
            if (! $product && ! $program && ! $price && ! $points) continue;
            if (! $product) continue; // строка без названия продукта — мусор

            // Red-detection в %DS-колонке (8) — приоритетная.
            $isRed = false;
            foreach ([8, 3, 4] as $ci) {
                $bg = $cells[$ci]['effectiveFormat']['backgroundColor'] ?? null;
                if (! is_array($bg)) continue;
                $r = (float) ($bg['red'] ?? 1);
                $g = (float) ($bg['green'] ?? 1);
                $b = (float) ($bg['blue'] ?? 1);
                if ($r > 0.85 && $r > $g + 0.08 && $r > $b + 0.08) {
                    $isRed = true;
                    break;
                }
            }

            $out[] = [
                'type' => $get(2),
                'product' => $product,
                'program' => $program,
                'price' => $price,
                'currency' => $get(6),
                'supplier' => $get(7),
                'ds_pct' => $get(8),
                'property' => $get(9),
                'term' => $get(10),
                'year_kv' => $get(11),
                'points' => $points,
                'formula' => $get(13),
                'comment' => $get(14),
                'category' => $get(15),
                'is_red' => $isRed,
            ];
        }
        return $out;
    }
}
