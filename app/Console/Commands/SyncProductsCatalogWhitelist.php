<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Синхронизация audit-каталога (`products_catalog` + `programs_catalog`)
 * с белым списком из `storage/app/products-whitelist.json`.
 *
 * Источник JSON: лист «ПРОДУКТЫ» из Google Sheet
 * (1tZDROF9cDsK3zZnBe_A0EeeT-UgkrgnZH-NByrUnZ4s), классифицированный по
 * текстовым маркерам колонки B («договор оставить но не пиарить» →
 * HIDDEN, «не добавлем на платформу» → SKIP и т.д.).
 *
 * Поведение:
 *   1. Продукты/программы из whitelist:
 *        - ищем в БД по нормализованному имени (lower + trim);
 *        - найдено → обновляем active (и type, если category в whitelist
 *          задан; пустая category → type НЕ трогаем, по правилу
 *          «категории нет — не ставим»);
 *        - не найдено → создаём.
 *   2. Сироты (в БД, но не в whitelist): active=false. Не удаляем — на
 *      них могут ссылаться контракты/transactions.
 *   3. Дубли по name (например, Medlife в двух категориях whitelist'а)
 *      сводятся к одной записи в БД: программы обеих умбрелл уезжают
 *      под одного product_id.
 *
 * По умолчанию команда работает в DRY-RUN: показывает план и ничего не
 * пишет. Для применения нужен флаг `--apply`.
 */
class SyncProductsCatalogWhitelist extends Command
{
    protected $signature = 'products-catalog:sync-whitelist
                            {--file= : Путь к JSON-whitelist (по умолчанию database/data/products-whitelist.json)}
                            {--apply : Применить изменения (по умолчанию DRY-RUN)}';

    protected $description = 'Синхронизирует products_catalog/programs_catalog с JSON-whitelist из листа ПРОДУКТЫ';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $file  = $this->option('file') ?: base_path('database/data/products-whitelist.json');
        // Если передан относительный путь — пробуем относительно base_path.
        $path  = (str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $file))
            ? $file
            : base_path($file);

        if (! is_file($path)) {
            $this->error("JSON не найден: {$path}");
            return self::FAILURE;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || ! isset($json['products'])) {
            $this->error('Неверный формат JSON: ожидаются ключи "products".');
            return self::FAILURE;
        }

        $this->info('Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->info('Source: ' . $path);
        $this->info('Products in whitelist: ' . count($json['products']));

        // === STEP 1: snapshot БД ===
        $dbProducts = DB::table('products_catalog')->get();
        $dbProductsByKey = $dbProducts->keyBy(fn ($r) => self::norm($r->name));
        $dbProgramsByProduct = DB::table('programs_catalog')->get()->groupBy('product_id');

        $this->info('В БД: products_catalog=' . $dbProducts->count() . ', programs_catalog=' .
            $dbProgramsByProduct->flatten()->count());

        // === STEP 2: построение плана ===
        $plan = [
            'products_create' => [],     // [{name, type, active}]
            'products_update' => [],     // [{id, name, changes:{type?,active?}}]
            'products_deactivate' => [], // [{id, name}]
            'programs_create' => [],     // [{product_name, name, active}]
            'programs_update' => [],     // [{id, name, changes:{active}}]
            'programs_deactivate' => [], // [{id, product_id, name}]
        ];

        $touchedProductIds = [];
        $touchedProgramIds = [];

        // Список умбрелл, на которые в БД есть запись — нужен для матчинга программ
        // у новых продуктов (когда сам продукт create'ится в этом же ране).
        $productIdByKey = [];
        foreach ($dbProductsByKey as $k => $row) $productIdByKey[$k] = $row->id;

        foreach ($json['products'] as $wp) {
            $name  = trim((string) ($wp['name'] ?? ''));
            if ($name === '') continue;
            $nkey  = self::norm($name);
            $cat   = $wp['category'] ?? null;
            $act   = (bool) ($wp['active'] ?? true);

            $existing = $dbProductsByKey->get($nkey);
            if ($existing) {
                $changes = [];
                if ($cat !== null && $cat !== '' && $existing->type !== $cat) {
                    $changes['type'] = $cat;
                }
                if ((bool) $existing->active !== $act) {
                    $changes['active'] = $act;
                }
                if ($changes) {
                    $plan['products_update'][] = [
                        'id' => $existing->id, 'name' => $existing->name, 'changes' => $changes,
                    ];
                }
                $touchedProductIds[$existing->id] = true;
                $productId = $existing->id;
            } else {
                $plan['products_create'][] = [
                    'name'     => $name,
                    'type'     => $cat,
                    'active'   => $act,
                ];
                $productId = null; // создадим при apply
            }

            // === Программы этого продукта ===
            $existingProgs = $productId
                ? ($dbProgramsByProduct->get($productId) ?? collect())
                : collect();
            $existingByKey = $existingProgs->keyBy(fn ($r) => self::norm($r->name));

            foreach (($wp['programs'] ?? []) as $wpr) {
                $pname = trim((string) ($wpr['name'] ?? ''));
                if ($pname === '') continue;
                $pkey  = self::norm($pname);
                $pact  = (bool) ($wpr['active'] ?? true);
                $row   = $existingByKey->get($pkey);

                if ($row) {
                    if ((bool) $row->active !== $pact) {
                        $plan['programs_update'][] = [
                            'id' => $row->id, 'name' => $row->name,
                            'changes' => ['active' => $pact],
                        ];
                    }
                    $touchedProgramIds[$row->id] = true;
                } else {
                    $plan['programs_create'][] = [
                        'product_name' => $name,
                        'name'         => $pname,
                        'active'       => $pact,
                    ];
                }
            }
        }

        // === STEP 3: сироты — деактивируем ===
        foreach ($dbProducts as $row) {
            if (isset($touchedProductIds[$row->id])) continue;
            if (! $row->active) continue;  // уже скрыт — пропускаем
            $plan['products_deactivate'][] = ['id' => $row->id, 'name' => $row->name];
        }
        foreach ($dbProgramsByProduct as $progs) {
            foreach ($progs as $prog) {
                if (isset($touchedProgramIds[$prog->id])) continue;
                if (! $prog->active) continue;
                $plan['programs_deactivate'][] = [
                    'id' => $prog->id, 'product_id' => $prog->product_id, 'name' => $prog->name,
                ];
            }
        }

        // === STEP 4: печать плана ===
        $this->newLine();
        $this->info('=== ПЛАН ===');
        $this->line('Продукты — создать:      ' . count($plan['products_create']));
        foreach (array_slice($plan['products_create'], 0, 50) as $p) {
            $this->line(sprintf('  + %-35s [%s] active=%s', $p['name'], $p['type'] ?? '<NULL>', $p['active'] ? 't' : 'f'));
        }
        $this->line('Продукты — обновить:     ' . count($plan['products_update']));
        foreach (array_slice($plan['products_update'], 0, 50) as $u) {
            $this->line(sprintf('  ~ #%d %-30s %s', $u['id'], $u['name'], json_encode($u['changes'], JSON_UNESCAPED_UNICODE)));
        }
        $this->line('Продукты — деактивировать: ' . count($plan['products_deactivate']));
        foreach (array_slice($plan['products_deactivate'], 0, 50) as $u) {
            $this->line(sprintf('  - #%d %s', $u['id'], $u['name']));
        }

        $this->line('Программы — создать:      ' . count($plan['programs_create']));
        foreach (array_slice($plan['programs_create'], 0, 30) as $p) {
            $this->line(sprintf('  + %s :: %s (active=%s)', $p['product_name'], $p['name'], $p['active'] ? 't' : 'f'));
        }
        $this->line('Программы — обновить:     ' . count($plan['programs_update']));
        foreach (array_slice($plan['programs_update'], 0, 30) as $u) {
            $this->line(sprintf('  ~ #%d %-30s %s', $u['id'], $u['name'], json_encode($u['changes'])));
        }
        $this->line('Программы — деактивировать: ' . count($plan['programs_deactivate']));
        foreach (array_slice($plan['programs_deactivate'], 0, 30) as $u) {
            $this->line(sprintf('  - #%d %s', $u['id'], $u['name']));
        }

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY-RUN — ничего не изменено. Запусти с --apply для применения.');
            return self::SUCCESS;
        }

        // === STEP 5: APPLY ===
        $this->newLine();
        $this->info('=== APPLY ===');
        DB::transaction(function () use (&$plan, &$productIdByKey) {
            $now = now();

            // 5.1 Создаём недостающие продукты
            foreach ($plan['products_create'] as $info) {
                $id = DB::table('products_catalog')->insertGetId([
                    'name'          => $info['name'],
                    'type'          => $info['type'],
                    'active'        => $info['active'],
                    'imported_from' => 'whitelist-sync',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                $productIdByKey[self::norm($info['name'])] = $id;
            }

            // 5.2 Обновляем существующие
            foreach ($plan['products_update'] as $u) {
                $u['changes']['updated_at'] = $now;
                DB::table('products_catalog')->where('id', $u['id'])->update($u['changes']);
            }

            // 5.3 Сироты-продукты → active=false
            if ($plan['products_deactivate']) {
                $ids = array_column($plan['products_deactivate'], 'id');
                DB::table('products_catalog')->whereIn('id', $ids)->update([
                    'active' => false, 'updated_at' => $now,
                ]);
            }

            // 5.4 Создаём новые программы (как только product_id известен)
            foreach ($plan['programs_create'] as $p) {
                $pid = $productIdByKey[self::norm($p['product_name'])] ?? null;
                if (! $pid) continue;  // защита от расхождения
                DB::table('programs_catalog')->insert([
                    'product_id'    => $pid,
                    'name'          => $p['name'],
                    'active'        => $p['active'],
                    'imported_from' => 'whitelist-sync',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }

            // 5.5 Обновляем существующие программы
            foreach ($plan['programs_update'] as $u) {
                $u['changes']['updated_at'] = $now;
                DB::table('programs_catalog')->where('id', $u['id'])->update($u['changes']);
            }

            // 5.6 Сироты-программы → active=false
            if ($plan['programs_deactivate']) {
                $ids = array_column($plan['programs_deactivate'], 'id');
                DB::table('programs_catalog')->whereIn('id', $ids)->update([
                    'active' => false, 'updated_at' => $now,
                ]);
            }
        });

        $this->info('Готово.');
        return self::SUCCESS;
    }

    /** Нормализация имени для матчинга: lower + collapse whitespace. */
    private static function norm(?string $s): string
    {
        $s = mb_strtolower((string) $s);
        $s = preg_replace('/\s+/u', ' ', trim($s));
        return $s;
    }
}
