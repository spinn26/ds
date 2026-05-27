<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit-driven products catalog (2026-05-27).
 *
 * Two new tables, originals stay untouched (so every contract keeps its FK):
 *
 *   legacy_catalog
 *     Flat denormalised snapshot — one row per `program` that has at least
 *     one alive contract, with the parent `product` fields joined in.
 *     This is the "old programs and products that contracts/transactions
 *     reference" view, kept for history.  No FK from contracts here —
 *     contracts continue to point at the original program/product tables.
 *
 *   program_catalog
 *     Clean Excel-driven catalog (the «Аудит Продукты и баллы без учёта НДС»
 *     workbook).  Year of КВ payout and contract term live as parameters
 *     inside the per-program `tariffs` JSONB array, not as separate program
 *     rows.  Red rows from the audit start their life with active=false.
 *     This is what the partner cabinet «Продукты» section will read from.
 *
 * Source JSON (storage/app/audit-programs.json) is produced by
 * scripts/audit-xlsx-to-json.py and must exist before `php artisan migrate`
 * runs.  Without it the catalog table is created empty — re-run the
 * preprocessor and re-execute the populate part manually.
 */
return new class extends Migration
{
    private const AUDIT_JSON_PATH = 'app/audit-programs.json';

    public function up(): void
    {
        // ---------- 1. legacy_catalog (denormalised snapshot) ----------
        if (! Schema::hasTable('legacy_catalog')) {
            Schema::create('legacy_catalog', function (Blueprint $t) {
                $t->bigIncrements('id');

                // Original FK targets — kept so an operator can match a
                // legacy_catalog row back to the live program/product/contract.
                $t->unsignedInteger('program_id')->index();
                $t->unsignedInteger('product_id')->nullable()->index();

                // Program-side fields.
                $t->string('program_name')->nullable();
                $t->string('product_name_denorm')->nullable();  // program.productName
                $t->string('vendor_name')->nullable();          // program.vendorName
                $t->string('currency_name')->nullable();        // program.currencyName
                $t->string('category_name')->nullable();        // program.categoryName
                $t->string('points_method', 32)->nullable();    // program.pointsMethod
                $t->text('points_formula')->nullable();         // program.pointsFormula
                $t->decimal('points_min', 15, 4)->nullable();
                $t->decimal('points_max', 15, 4)->nullable();
                $t->decimal('ds_percent', 6, 3)->nullable();
                $t->decimal('fixed_cost', 15, 2)->nullable();
                $t->smallInteger('kv_payout_year')->nullable();
                $t->integer('term')->nullable();
                $t->integer('term_contract')->nullable();
                $t->boolean('program_active')->nullable();
                $t->boolean('program_visible_to_calculator')->nullable();
                $t->boolean('program_visible_to_resident')->nullable();
                $t->text('calc_comment')->nullable();

                // Product-side fields (denormalised via JOIN).
                $t->string('product_name')->nullable();
                $t->string('product_type_name')->nullable();
                $t->integer('product_type_id')->nullable();
                $t->boolean('product_active')->nullable();
                $t->boolean('product_visible_to_calculator')->nullable();
                $t->boolean('product_visible_to_resident')->nullable();
                $t->boolean('product_has_property')->nullable();
                $t->boolean('product_has_term')->nullable();
                $t->boolean('product_has_year_kv')->nullable();

                // Usage stats at snapshot time — easy filter for "what's
                // actually being used".
                $t->unsignedInteger('alive_contracts')->default(0);

                $t->timestamp('snapshot_taken_at')->useCurrent();
            });

            // Populate: every program with at least one alive contract.
            DB::statement(<<<'SQL'
                INSERT INTO legacy_catalog (
                    program_id, product_id,
                    program_name, product_name_denorm, vendor_name,
                    currency_name, category_name, points_method, points_formula,
                    points_min, points_max, ds_percent, fixed_cost,
                    kv_payout_year, term, term_contract,
                    program_active, program_visible_to_calculator, program_visible_to_resident,
                    calc_comment,
                    product_name, product_type_name, product_type_id,
                    product_active, product_visible_to_calculator, product_visible_to_resident,
                    product_has_property, product_has_term, product_has_year_kv,
                    alive_contracts, snapshot_taken_at
                )
                SELECT
                    pr.id, pr.product,
                    pr.name, pr."productName", pr."vendorName",
                    pr."currencyName", pr."categoryName", pr."pointsMethod", pr."pointsFormula",
                    pr."pointsMin", pr."pointsMax", pr."dsPercent", pr."fixedCost",
                    pr."kvPayoutYear", pr.term, pr."termContract",
                    pr.active, pr."visibleToCalculator", pr."visibleToResident",
                    pr."calcComment",
                    p.name, p."typeName", p."productType",
                    p.active, p."visibleToCalculator", p."visibleToResident",
                    p.has_property, p.has_term, p.has_year_kv,
                    cc.cnt, NOW()
                FROM program pr
                LEFT JOIN product p ON p.id = pr.product
                JOIN (
                    SELECT program, COUNT(*) AS cnt
                    FROM contract
                    WHERE "deletedAt" IS NULL AND program IS NOT NULL
                    GROUP BY program
                ) cc ON cc.program = pr.id
            SQL);
        }

        // ---------- 2. program_catalog (clean Excel-driven) ----------
        if (! Schema::hasTable('program_catalog')) {
            Schema::create('program_catalog', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('type')->nullable();
                $t->string('product')->nullable();
                $t->string('program')->nullable();
                $t->string('vendor')->nullable();
                $t->string('currency')->nullable();
                $t->string('category')->nullable();
                $t->boolean('has_red')->default(false);
                $t->boolean('active')->default(true);
                $t->unsignedSmallInteger('rate_lines')->default(0);
                $t->string('years_summary')->nullable();
                $t->string('terms_summary')->nullable();
                $t->text('comment_snippets')->nullable();
                $t->jsonb('tariffs')->nullable();
                $t->jsonb('row_colors')->nullable();
                $t->string('dominant_color', 32)->nullable();
                $t->unsignedInteger('source_row_first')->nullable();
                $t->unsignedInteger('source_row_last')->nullable();
                $t->string('imported_from')->nullable();
                $t->timestamps();
                $t->unique(['product', 'program'], 'program_catalog_product_program_unique');
                $t->index('has_red');
                $t->index('active');
            });
        }

        // ---------- 3. populate program_catalog from JSON ----------
        $path = storage_path(self::AUDIT_JSON_PATH);
        if (! is_file($path)) {
            if (app()->runningInConsole()) {
                echo "  program_catalog: audit JSON not found at storage/" . self::AUDIT_JSON_PATH
                   . " — table created empty, run scripts/audit-xlsx-to-json.py and re-populate.\n";
            }
            return;
        }
        $audit = json_decode((string) file_get_contents($path), true);
        if (! is_array($audit) || empty($audit['programs'])) {
            return;
        }
        $source = $audit['source'] ?? 'audit-xlsx';

        $rows = [];
        foreach ($audit['programs'] as $a) {
            $rows[] = [
                'type'              => $a['type'] ?? null,
                'product'           => $a['product'] ?? null,
                'program'           => $a['program'] ?? null,
                'vendor'            => isset($a['vendors']) && $a['vendors'] ? implode(' / ', $a['vendors']) : null,
                'currency'          => isset($a['currencies']) && $a['currencies'] ? implode(' / ', $a['currencies']) : null,
                'category'          => isset($a['categories']) && $a['categories'] ? implode(' / ', $a['categories']) : null,
                'has_red'           => (bool) ($a['has_red'] ?? false),
                'active'            => ! (bool) ($a['has_red'] ?? false),
                'rate_lines'        => (int) ($a['rate_lines'] ?? 0),
                'years_summary'     => isset($a['years']) && $a['years'] ? implode(',', $a['years']) : null,
                'terms_summary'     => isset($a['terms']) && $a['terms'] ? implode(',', $a['terms']) : null,
                'comment_snippets'  => isset($a['comment_snippets']) && $a['comment_snippets']
                                        ? implode(' | ', $a['comment_snippets']) : null,
                'tariffs'           => json_encode($a['tariffs'] ?? [], JSON_UNESCAPED_UNICODE),
                'row_colors'        => json_encode($a['row_colors'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                'dominant_color'    => $a['dominant_color'] ?? null,
                'source_row_first'  => $a['first_row'] ?? null,
                'source_row_last'   => $a['last_row'] ?? null,
                'imported_from'     => $source,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('program_catalog')->upsert(
                    $chunk,
                    ['product', 'program'],
                    [
                        'type', 'vendor', 'currency', 'category',
                        'has_red', 'active', 'rate_lines',
                        'years_summary', 'terms_summary', 'comment_snippets',
                        'tariffs', 'row_colors', 'dominant_color',
                        'source_row_first', 'source_row_last',
                        'imported_from', 'updated_at',
                    ]
                );
            }
        });

        if (app()->runningInConsole()) {
            $cat = DB::selectOne('
                SELECT COUNT(*) AS total,
                       COUNT(*) FILTER (WHERE active=true) AS active,
                       COUNT(*) FILTER (WHERE active=false) AS inactive
                FROM program_catalog
            ');
            $leg = DB::selectOne('SELECT COUNT(*) AS total, SUM(alive_contracts) AS contracts FROM legacy_catalog');
            echo "  legacy_catalog:  {$leg->total} programs ({$leg->contracts} alive contracts)\n";
            echo "  program_catalog: total={$cat->total}, active={$cat->active}, inactive={$cat->inactive}\n";
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('program_catalog');
        Schema::dropIfExists('legacy_catalog');
    }
};
