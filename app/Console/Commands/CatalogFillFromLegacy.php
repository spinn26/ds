<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Достроить каталог продуктов и программ по legacy-таблицам Directual.
 *
 * Каталог — витрина, но заведён не полностью: на проде 27 продуктов и 439
 * программ, на которые ссылаются контракты, тарифы и конкурсы, карточки не
 * имеют. Такие позиции не видно в отчётах по продуктам, им нельзя завести
 * тариф через интерфейс и они выпадают из калькулятора.
 *
 * Команда заводит недостающие карточки из legacy-строк и проставляет связку
 * legacy_product_id / legacy_program_id. Это же условие обязательно для
 * последующего слияния каталогов: без карточки ссылка повиснет.
 *
 * ⚠ Заводим ВЫКЛЮЧЕННЫМИ и невидимыми: это исторические позиции, часть из них
 * давно не продаётся. Оператор включит нужные руками — обратное (случайно
 * показать партнёру снятый с продажи продукт) хуже.
 * ⚠ Берём только те legacy-строки, на которые кто-то ссылается: мусор
 * Directual (в program 1655 строк, используется 645) в витрину не тащим.
 * ⚠ В legacy есть близнецы с одинаковым названием — две записи «Freedom Global
 * ETF», две «ЛФП» и так далее, а имя в каталоге уникально. Такой карточке
 * даём суффикс «(legacy N)»: это честнее, чем молча пропустить позицию с
 * контрактами. Оператор потом решит, сливать их или переименовать.
 */
class CatalogFillFromLegacy extends Command
{
    protected $signature = 'catalog:fill-from-legacy {--apply : записать (без флага — только показать)}';

    protected $description = 'Завести карточки каталога для legacy-продуктов и программ, на которые есть ссылки';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $products = $this->missingProducts();
        $programs = $this->missingPrograms();

        $this->info('Не хватает карточек: продуктов '.count($products).', программ '.count($programs).'.');

        if (! $apply) {
            foreach (array_slice($products, 0, 5) as $p) {
                $this->line("  продукт {$p->id}: {$p->name}");
            }
            foreach (array_slice($programs, 0, 5) as $p) {
                $this->line("  программа {$p->id}: {$p->name}");
            }
            $this->line('Сухой прогон. Повтори с --apply, чтобы записать.');

            return self::SUCCESS;
        }

        $createdProducts = 0;
        $createdPrograms = 0;

        DB::transaction(function () use ($products, $programs, &$createdProducts, &$createdPrograms) {
            $takenProduct = DB::table('products_catalog')->pluck('name')
                ->map(fn ($n) => mb_strtolower(trim((string) $n)))->flip()->all();

            foreach ($products as $p) {
                $name = $this->uniqueName($p->name ?: 'Продукт '.$p->id, $p->id, $takenProduct);
                DB::table('products_catalog')->insert([
                    'name' => $name,
                    'type' => $p->typeName ?: null,
                    'open_product_url' => $p->openProductUrl ?: null,
                    'description' => $p->description ?: null,
                    'active' => false,
                    'visible_to_resident' => false,
                    'visible_to_calculator' => false,
                    'is_primary' => true,
                    'accrual_forecast_months' => 0,
                    'legacy_product_id' => $p->id,
                    'imported_from' => 'legacy:product',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $takenProduct[mb_strtolower(trim($name))] = true;
                $createdProducts++;
            }

            // Карту «legacy-продукт → карточка» строим ПОСЛЕ вставки продуктов:
            // программе нужен product_id уже существующей карточки.
            $productByLegacy = DB::table('products_catalog')->whereNotNull('legacy_product_id')
                ->pluck('id', 'legacy_product_id');

            foreach ($programs as $pr) {
                $productId = $pr->product ? ($productByLegacy[$pr->product] ?? null) : null;
                if (! $productId) {
                    // Программа без продукта в каталоге — пропускаем: пустая
                    // ссылка сломает витрину сильнее, чем отсутствие карточки.
                    continue;
                }

                // Имя программы уникально В ПРЕДЕЛАХ продукта.
                $takenProgram = DB::table('programs_catalog')->where('product_id', $productId)
                    ->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->flip()->all();
                $programName = $this->uniqueName($pr->name ?: 'Программа '.$pr->id, $pr->id, $takenProgram);

                DB::table('programs_catalog')->insert([
                    'product_id' => $productId,
                    'name' => $programName,
                    'vendor' => $pr->vendorName ?: null,
                    'currency' => $pr->currencyName ?: null,
                    'has_red' => false,
                    'active' => false,
                    // rate_lines — счётчик строк тарифной сетки (smallint),
                    // а не список: у новой карточки сетки нет.
                    'rate_lines' => 0,
                    'tariffs' => '[]',
                    'visible_to_resident' => false,
                    'visible_to_calculator' => false,
                    'legacy_program_id' => $pr->id,
                    'imported_from' => 'legacy:program',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $createdPrograms++;
            }
        });

        $this->info("Заведено карточек: продуктов {$createdProducts}, программ {$createdPrograms}.");

        return self::SUCCESS;
    }

    /**
     * Имя, не конфликтующее с уже занятыми: при совпадении добавляем «(legacy N)».
     *
     * @param  array<string,mixed>  $taken
     */
    private function uniqueName(string $name, int $legacyId, array $taken): string
    {
        $key = mb_strtolower(trim($name));

        return isset($taken[$key]) ? $name.' (legacy '.$legacyId.')' : $name;
    }

    /** Legacy-продукты, на которые есть ссылки, но нет карточки. */
    private function missingProducts(): array
    {
        return DB::select(<<<'SQL'
            SELECT p.id, p.name, p."typeName", p."openProductUrl", p.description
            FROM product p
            WHERE NOT EXISTS (SELECT 1 FROM products_catalog pc WHERE pc.legacy_product_id = p.id)
              AND (EXISTS (SELECT 1 FROM contract c WHERE c.product = p.id)
                OR EXISTS (SELECT 1 FROM "dsCommission" d WHERE d.product = p.id)
                OR EXISTS (SELECT 1 FROM program pr WHERE pr.product = p.id
                           AND EXISTS (SELECT 1 FROM contract c2 WHERE c2.program = pr.id)))
            ORDER BY p.id
            SQL);
    }

    /** Legacy-программы, на которые есть ссылки, но нет карточки. */
    private function missingPrograms(): array
    {
        return DB::select(<<<'SQL'
            SELECT pr.id, pr.name, pr.product, pr."vendorName", pr."currencyName"
            FROM program pr
            WHERE NOT EXISTS (SELECT 1 FROM programs_catalog pg WHERE pg.legacy_program_id = pr.id)
              AND (EXISTS (SELECT 1 FROM contract c WHERE c.program = pr.id)
                OR EXISTS (SELECT 1 FROM "dsCommission" d WHERE d.program = pr.id))
            ORDER BY pr.id
            SQL);
    }
}
