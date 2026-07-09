<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill products_catalog with product lines that live contracts reference by
 * name but which are missing from the catalog (Inssmart family, ITI Capital,
 * ICN, IPO, …). Such products exist only in legacy `product` (FK anchor) and in
 * denormalized contract.productName — so they cannot be managed/filtered via the
 * products UI (products_catalog is the UI source of truth).
 *
 * For every distinct alive contract.productName absent from products_catalog we
 * find its legacy product by name and create a catalog row linked via
 * legacy_product_id. When a name has several legacy rows (e.g. IPO 43/51,
 * "ВЗР Inssmart" 77/88) we keep the best one: active first, then
 * visibleToCalculator, then the lowest id.
 *
 * `active` is set to TRUE deliberately — these products are backfilled precisely
 * because they carry live contracts, so mirroring legacy's stale active=false
 * would reproduce the very drift we are fixing. Storefront/calculator visibility
 * stays FALSE (conservative — do not expose on the витрина automatically).
 *
 * Names with NO legacy anchor (e.g. «ИИ ДС») are reported, not inserted — they
 * need a manual decision (which legacy product, or a brand-new one).
 *
 * Idempotent: skips names already in the catalog and legacy ids already linked.
 * --dry-run shows the plan without writing.
 */
class BackfillProductsCatalog extends Command
{
    protected $signature = 'products:backfill-catalog {--dry-run : показать план без изменений}';

    protected $description = 'Завести в products_catalog продукты живых контрактов, которых нет в каталоге (Inssmart/ITI/ICN/IPO …)';

    /**
     * One winning legacy product per missing contract-product name, excluding
     * legacy ids already linked into the catalog. Orphan names (no legacy match)
     * fall out here by the JOIN and are counted separately.
     */
    private const PLAN_SQL = <<<'SQL'
        WITH missing AS (
            SELECT DISTINCT lower(btrim(c."productName")) AS nm
            FROM contract c
            WHERE c."deletedAt" IS NULL AND c."productName" IS NOT NULL
              AND lower(btrim(c."productName")) NOT IN (SELECT lower(btrim(name)) FROM products_catalog)
        ),
        ranked AS (
            SELECT m.nm,
                   p.id            AS legacy_id,
                   p.name          AS legacy_name,
                   p."typeName"    AS type_name,
                   row_number() OVER (
                       PARTITION BY m.nm
                       ORDER BY (p.active IS TRUE) DESC,
                                (p."visibleToCalculator" IS TRUE) DESC,
                                p.id ASC
                   ) AS rk
            FROM missing m
            JOIN product p ON lower(btrim(p.name)) = m.nm
        )
        SELECT r.legacy_id, r.legacy_name, r.type_name,
               (SELECT count(*) FROM contract c
                 WHERE c."deletedAt" IS NULL
                   AND lower(btrim(c."productName")) = r.nm) AS contracts
        FROM ranked r
        WHERE r.rk = 1
          AND NOT EXISTS (SELECT 1 FROM products_catalog pc WHERE pc.legacy_product_id = r.legacy_id)
        ORDER BY contracts DESC, r.legacy_name
        SQL;

    /** Missing contract-product names that have NO legacy anchor at all. */
    private const ORPHAN_SQL = <<<'SQL'
        SELECT c."productName" AS name, count(*) AS contracts
        FROM contract c
        WHERE c."deletedAt" IS NULL AND c."productName" IS NOT NULL
          AND lower(btrim(c."productName")) NOT IN (SELECT lower(btrim(name)) FROM products_catalog)
          AND lower(btrim(c."productName")) NOT IN (SELECT lower(btrim(name)) FROM product)
        GROUP BY c."productName"
        ORDER BY contracts DESC
        SQL;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $plan = DB::select(self::PLAN_SQL);
        $orphans = DB::select(self::ORPHAN_SQL);

        $this->info(($dry ? '[DRY-RUN] ' : '') . 'Продуктов к заведению в каталог: ' . count($plan));
        foreach ($plan as $r) {
            $this->line(sprintf('  + %-34s legacy #%-3d  контрактов: %d', $r->legacy_name, $r->legacy_id, $r->contracts));
        }

        if ($orphans) {
            $this->warn('Без legacy-якоря (НЕ завожу, нужно решить вручную):');
            foreach ($orphans as $o) {
                $this->line(sprintf('  ? %-34s контрактов: %d', $o->name, $o->contracts));
            }
        }

        if ($dry || ! $plan) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $r) {
                DB::table('products_catalog')->insert([
                    'name'                    => $r->legacy_name,
                    'type'                    => $r->type_name,
                    'active'                  => true,   // есть живые контракты
                    'visible_to_resident'     => false,  // на витрину вручную
                    'visible_to_calculator'   => false,
                    'is_primary'              => true,
                    'accrual_forecast_months' => 0,
                    'legacy_product_id'       => $r->legacy_id,
                    'imported_from'           => 'backfill-catalog',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);
            }
        });

        $this->info('Готово. Заведено продуктов: ' . count($plan) . '.');
        if ($orphans) {
            $this->warn('Осталось разобрать вручную (без legacy): ' . count($orphans) . '.');
        }

        return self::SUCCESS;
    }
}
