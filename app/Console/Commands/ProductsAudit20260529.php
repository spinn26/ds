<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Аудит каталога продуктов 2026-05-29 по обновлённой Google-таблице
 * 1dmBOUs4bPFDSxOu0uBk1FJ4n8-RkANOJhoSmDWqLJkc (лист ПРОДУКТЫ).
 *
 * Методика та же, что и для майского аудита (см. memory
 * project_products_audit_2026_05): красное выделение фона строки =
 * продукт неактивен, остальное — активно.
 *
 * Запуск:
 *   php artisan products:audit-20260529 /tmp/products_sheet_20260529.json
 *   php artisan products:audit-20260529 /tmp/products_sheet_20260529.json --apply
 *
 * По умолчанию — dry-run (только показывает diff). С --apply реально
 * пишет visible_to_resident/visible_to_calculator в products_catalog/
 * programs_catalog по результатам сверки.
 */
class ProductsAudit20260529 extends Command
{
    protected $signature = 'products:audit-20260529
        {path : Путь к JSON-выгрузке Google Sheets}
        {--apply : Реально скрыть red-программы}
        {--create-missing : Создать в catalog те программы, которых там нет (active в листе)}';
    protected $description = 'Diff/apply нового списка продуктов из Google Sheets';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $this->info("Парсинг {$path}…");
        $rows = $this->parseSheet($path);
        $this->info("Найдено строк продуктов: " . count($rows));

        // Группируем по (product, program). Если в таблице один и тот же
        // продукт×программа встречается несколько раз (с разными сроками
        // / годами выплат) — берём флаг red = ВСЕ строки красные (если
        // хотя бы одна некрасная — считаем что программа есть).
        $byProduct = [];
        foreach ($rows as $r) {
            $p = trim((string) ($r['product'] ?? ''));
            $pr = trim((string) ($r['program'] ?? ''));
            if ($p === '') continue;
            $key = mb_strtolower("{$p}||{$pr}");
            if (! isset($byProduct[$key])) {
                $byProduct[$key] = [
                    'product' => $p,
                    'program' => $pr,
                    'category' => $r['category'] ?? null,
                    'supplier' => $r['supplier'] ?? null,
                    'red_count' => 0,
                    'total_count' => 0,
                ];
            }
            $byProduct[$key]['total_count']++;
            if (! empty($r['is_red'])) $byProduct[$key]['red_count']++;
        }

        $programs = [];
        foreach ($byProduct as $k => $g) {
            $g['active'] = $g['red_count'] < $g['total_count']; // хотя бы одна не-красная = активно
            $programs[$k] = $g;
        }

        $activeCnt = count(array_filter($programs, fn ($g) => $g['active']));
        $inactiveCnt = count($programs) - $activeCnt;
        $this->line('');
        $this->info("Уникальных программ: " . count($programs));
        $this->info("  активных: {$activeCnt}");
        $this->warn("  неактивных (red): {$inactiveCnt}");

        // Уникальные продукты (агрегат верхнего уровня).
        $productNames = array_unique(array_map(fn ($g) => mb_strtolower($g['product']), $programs));
        $this->info("Уникальных продуктов: " . count($productNames));

        // Сравниваем с текущим products_catalog.
        $catalog = DB::table('products_catalog')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'visible_to_resident', 'visible_to_calculator'])
            ->keyBy(fn ($c) => mb_strtolower(trim($c->name)));

        $this->line('');
        $this->info("В products_catalog активных: " . $catalog->count());

        // Различия по продуктам.
        $sheetProductSet = array_flip($productNames);
        $newProducts = []; // в листе есть, в каталоге нет
        $goneProducts = []; // в каталоге есть, в листе нет
        $matched = [];

        foreach ($sheetProductSet as $name => $_) {
            if (isset($catalog[$name])) {
                $matched[$name] = $catalog[$name];
            } else {
                $newProducts[] = $name;
            }
        }
        foreach ($catalog as $name => $row) {
            if (! isset($sheetProductSet[$name])) {
                $goneProducts[] = $name;
            }
        }

        $this->line('');
        $this->info("=== СВОДКА ===");
        $this->line("В каталоге, но не в листе (кандидаты на скрытие): " . count($goneProducts));
        $this->line("В листе, но не в каталоге (новые): " . count($newProducts));
        $this->line("Совпадают по имени: " . count($matched));

        if ($this->getOutput()->isVerbose()) {
            $this->line('');
            $this->warn('--- Отсутствуют в листе ---');
            foreach ($goneProducts as $n) $this->line("  - {$n}");
            $this->line('');
            $this->warn('--- Новые ---');
            foreach ($newProducts as $n) $this->line("  + {$n}");
        }

        // Программы: какие из текущих programs_catalog помечены red?
        // Сверка идёт по паре (продукт.name, программа.name) — оба ilike.
        $programsCatalog = DB::table('programs_catalog as pg')
            ->join('products_catalog as pc', 'pc.id', '=', 'pg.product_id')
            ->where('pg.active', true)
            ->orderBy('pc.name')->orderBy('pg.name')
            ->get([
                'pg.id', 'pg.name as program_name', 'pg.visible_to_resident', 'pg.visible_to_calculator',
                'pc.id as product_id', 'pc.name as product_name',
            ]);

        $catalogPairs = [];
        foreach ($programsCatalog as $row) {
            $catalogPairs[mb_strtolower(trim($row->product_name) . '||' . trim($row->program_name))] = $row;
        }

        $toHide = [];   // программа есть в каталоге и должна стать invisible (red в листе)
        $toShow = [];   // программа есть в листе active, но в каталоге скрыта
        $missingInCatalog = []; // в листе active, но программы нет в каталоге

        foreach ($programs as $key => $g) {
            $catalogRow = $catalogPairs[$key] ?? null;
            if (! $catalogRow) {
                if ($g['active']) $missingInCatalog[] = $g;
                continue;
            }
            $currentlyVisible = (bool) $catalogRow->visible_to_resident
                && (bool) $catalogRow->visible_to_calculator;
            if (! $g['active'] && $currentlyVisible) {
                $toHide[] = ['catalog' => $catalogRow, 'sheet' => $g];
            } elseif ($g['active'] && ! $currentlyVisible) {
                $toShow[] = ['catalog' => $catalogRow, 'sheet' => $g];
            }
        }

        $this->line('');
        $this->info("=== ПРОГРАММЫ — что меняется ===");
        $this->line("К скрытию (red в листе, но сейчас visible): " . count($toHide));
        $this->line("К показу (active в листе, но сейчас hidden): " . count($toShow));
        $this->warn("В листе active, но нет в каталоге (нужно создать вручную): " . count($missingInCatalog));

        if ($this->getOutput()->isVerbose()) {
            $this->line('');
            $this->warn('--- К скрытию (red) ---');
            foreach ($toHide as $t) {
                $this->line("  - [{$t['catalog']->product_id}/{$t['catalog']->id}] {$t['catalog']->product_name} → {$t['catalog']->program_name}");
            }
            $this->line('');
            $this->warn('--- К показу ---');
            foreach ($toShow as $t) {
                $this->line("  + [{$t['catalog']->product_id}/{$t['catalog']->id}] {$t['catalog']->product_name} → {$t['catalog']->program_name}");
            }
            $this->line('');
            $this->warn('--- Отсутствуют в каталоге ---');
            foreach ($missingInCatalog as $m) {
                $this->line("  ? {$m['product']} → {$m['program']}");
            }
        }

        $createMissing = (bool) $this->option('create-missing');

        if (! $apply && ! $createMissing) {
            $this->line('');
            $this->info('Dry-run. Используй --apply для скрытия red-программ, --create-missing для импорта новых.');
            $this->info('Для подробного списка добавь -v.');
            return self::SUCCESS;
        }

        if ($apply) {
            $this->line('');
            $this->warn('=== APPLY (hide red) ===');
            $hidden = 0;
            DB::transaction(function () use ($toHide, &$hidden) {
                foreach ($toHide as $t) {
                    DB::table('programs_catalog')->where('id', $t['catalog']->id)->update([
                        'visible_to_resident' => false,
                        'visible_to_calculator' => false,
                        'updated_at' => now(),
                    ]);
                    $hidden++;
                }
            });
            $this->info("Скрыто программ: {$hidden}");
            $this->info("К показу — НЕ применяем автоматом (только лог).");
        }

        if ($createMissing) {
            $this->line('');
            $this->warn('=== CREATE MISSING ===');
            [$prodCreated, $progCreated] = $this->createMissing($missingInCatalog);
            $this->info("Создано продуктов: {$prodCreated}");
            $this->info("Создано программ: {$progCreated}");
        }

        return self::SUCCESS;
    }

    /**
     * Создать в products_catalog/programs_catalog те (product, program)
     * пары, которых там нет. legacy_product_id остаётся NULL (продукты
     * пристыкуются к legacy `product` позже, когда придёт первый контракт
     * или вручную). visible_to_resident/calculator = true.
     *
     * @param  list<array<string,mixed>>  $missing
     * @return array{0:int,1:int}  [createdProducts, createdPrograms]
     */
    private function createMissing(array $missing): array
    {
        if (! $missing) return [0, 0];

        // Группируем по продукту, чтобы один INSERT в products_catalog
        // на много программ.
        $byProduct = [];
        foreach ($missing as $m) {
            $byProduct[$m['product']][] = $m;
        }

        $prodCreated = 0;
        $progCreated = 0;
        $importedFrom = 'sheet-2026-05-29';

        $prodReactivated = 0;
        $progReactivated = 0;

        DB::transaction(function () use ($byProduct, &$prodCreated, &$progCreated, &$prodReactivated, &$progReactivated, $importedFrom) {
            foreach ($byProduct as $productName => $programs) {
                // Если продукт уже есть в products_catalog по имени — используем.
                // Берём ЛЮБОЙ статус (active или нет): майский аудит мог
                // заархивировать продукт, а новая таблица возвращает его в строй.
                $existing = DB::table('products_catalog')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($productName))])
                    ->first(['id', 'active', 'visible_to_resident', 'visible_to_calculator']);

                if ($existing) {
                    $productId = $existing->id;
                    // Если archived — реактивируем (раз продукт снова в листе active).
                    if (! $existing->active || ! $existing->visible_to_resident || ! $existing->visible_to_calculator) {
                        DB::table('products_catalog')->where('id', $productId)->update([
                            'active' => true,
                            'visible_to_resident' => true,
                            'visible_to_calculator' => true,
                            'updated_at' => now(),
                        ]);
                        $prodReactivated++;
                    }
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
                    // Идемпотентность: если такая программа уже есть —
                    // либо реактивируем (если выключена), либо пропускаем.
                    $existingPg = DB::table('programs_catalog')
                        ->where('product_id', $productId)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($g['program']))])
                        ->first(['id', 'active', 'visible_to_resident', 'visible_to_calculator']);

                    if ($existingPg) {
                        if (! $existingPg->active || ! $existingPg->visible_to_resident || ! $existingPg->visible_to_calculator) {
                            DB::table('programs_catalog')->where('id', $existingPg->id)->update([
                                'active' => true,
                                'visible_to_resident' => true,
                                'visible_to_calculator' => true,
                                'has_red' => false,
                                'updated_at' => now(),
                            ]);
                            $progReactivated++;
                        }
                        continue;
                    }

                    DB::table('programs_catalog')->insert([
                        'product_id' => $productId,
                        'name' => $g['program'] ?: '—',
                        'vendor' => $g['supplier'] ?? null,
                        'category' => $g['category'] ?? null,
                        'active' => true,
                        'visible_to_resident' => true,
                        'visible_to_calculator' => true,
                        'has_red' => false,
                        'rate_lines' => (int) ($g['total_count'] ?? 1),
                        'imported_from' => $importedFrom,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $progCreated++;
                }
            }
        });

        $this->info("Реактивировано продуктов: {$prodReactivated}");
        $this->info("Реактивировано программ: {$progReactivated}");
        return [$prodCreated, $progCreated];
    }

    /**
     * Парсит JSON-выгрузку Sheets v4 (includeGridData). Возвращает строки
     * с данными продуктов (skip-аем header row + section-header rows).
     *
     * @return list<array{type:?string,product:?string,program:?string,price:?string,currency:?string,supplier:?string,ds_pct:?string,points:?string,category:?string,is_red:bool}>
     */
    private function parseSheet(string $path): array
    {
        $raw = json_decode(file_get_contents($path), true);
        $rowsData = $raw['sheets'][0]['data'][0]['rowData'] ?? [];

        // Заголовок — первая строка. Колонки (1-based из листа):
        //  C=3 ТИП, D=4 ПРОДУКТ, E=5 ПРОГРАММА, F=6 Стоимость, G=7 Валюта,
        //  H=8 Поставщик, I=9 % DS, J=10 Свойство, K=11 Срок, L=12 Год КВ,
        //  M=13 Баллы, N=14 Методика, O=15 Комментарий, P=16 Категория
        // В zero-based индексах: 2,3,4,5,6,7,8,9,10,11,12,13,14,15
        $out = [];
        foreach ($rowsData as $i => $row) {
            if ($i === 0) continue; // header
            if ($i === 1) continue; // 2-я строка — нумерация колонок (3,4,5,6,…)
            $cells = $row['values'] ?? [];
            $get = function (int $idx) use ($cells) {
                $v = $cells[$idx]['formattedValue'] ?? null;
                return is_string($v) ? trim($v) : $v;
            };
            $product = $get(3);
            $program = $get(4);
            $type = $get(2);

            // Skip полностью пустых строк.
            if (! $product && ! $program && ! $type) continue;

            // Section-header: заполнено только C (ТИП) и иногда E — но без F/H/M.
            // Игнорируем строки где одновременно нет ни program, ни price, ни points.
            $price = $get(5);
            $points = $get(12);
            if (! $product && ! $program && ! $price && ! $points) continue;

            // Red-detection: красят колонку 8 (% DS) — это индикатор
            // «тариф / линия программы деактивирован». Проверка по эмпирике
            // из выгрузки: реально красят I (zero-index 8). Если ВСЕ
            // строки одной программы красные → программа целиком
            // деактивирована (агрегируется ниже).
            // Светло-красный/розовый из Google: r~0.96, g~0.8, b~0.8.
            $isRed = false;
            foreach ([8, 3, 4] as $colIdx) {
                $bg = $cells[$colIdx]['effectiveFormat']['backgroundColor'] ?? null;
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
                'type' => $type,
                'product' => $product,
                'program' => $program,
                'price' => $price,
                'currency' => $get(6),
                'supplier' => $get(7),
                'ds_pct' => $get(8),
                'points' => $points,
                'category' => $get(15),
                'is_red' => $isRed,
            ];
        }
        return $out;
    }
}
