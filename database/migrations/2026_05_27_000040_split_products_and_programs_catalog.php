<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restructure the audit catalog into two tables to mirror the
 * domain hierarchy: product → program → (term, year as parameters).
 *
 *   products_catalog  one row per umbrella (e.g. «Investor Trust Evolution»)
 *   programs_catalog  one row per (product, program) pair, FK on product_id
 *                     Term and year are parameters that live inside the
 *                     per-program `tariffs` JSONB column — they are NOT
 *                     separate program rows.
 *
 * The previous program_catalog (flat, no FK) is dropped — it has been on
 * prod for only one migration step and nothing depends on it yet.
 * legacy_catalog stays as is (snapshot of pre-audit state).
 *
 * Source JSON: storage/app/audit-programs.json (produced by
 * scripts/audit-xlsx-to-json.py).
 */
return new class extends Migration
{
    private const AUDIT_JSON_PATH = 'app/audit-programs.json';

    public function up(): void
    {
        Schema::dropIfExists('program_catalog');

        Schema::create('products_catalog', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->unique();
            $t->string('type')->nullable();        // ТИП (top-level grouping)
            $t->boolean('active')->default(true);
            $t->string('imported_from')->nullable();
            $t->timestamps();
            $t->index('active');
        });

        Schema::create('programs_catalog', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('name');
            $t->string('vendor')->nullable();
            $t->string('currency')->nullable();
            $t->string('category')->nullable();
            $t->boolean('has_red')->default(false);
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('rate_lines')->default(0);
            // Term + year live both summarised here and in full detail in `tariffs`.
            $t->string('terms_summary')->nullable();   // e.g. "5,10,15,20,25"
            $t->string('years_summary')->nullable();   // e.g. "1 год,2 год,…"
            $t->text('comment_snippets')->nullable();
            $t->jsonb('tariffs')->nullable();          // per-rate-line matrix
            $t->jsonb('row_colors')->nullable();
            $t->string('dominant_color', 32)->nullable();
            $t->unsignedInteger('source_row_first')->nullable();
            $t->unsignedInteger('source_row_last')->nullable();
            $t->string('imported_from')->nullable();
            $t->timestamps();

            $t->foreign('product_id')->references('id')->on('products_catalog')->cascadeOnDelete();
            $t->unique(['product_id', 'name'], 'programs_catalog_product_name_unique');
            $t->index('has_red');
            $t->index('active');
        });

        $path = storage_path(self::AUDIT_JSON_PATH);
        if (! is_file($path)) {
            if (app()->runningInConsole()) {
                echo "  catalog: audit JSON not found at storage/" . self::AUDIT_JSON_PATH
                   . " — tables created empty.\n";
            }
            return;
        }
        $audit = json_decode((string) file_get_contents($path), true);
        if (! is_array($audit) || empty($audit['programs'])) {
            return;
        }
        $source = $audit['source'] ?? 'audit-xlsx';

        // ---------- products: distinct umbrella, preserve a sensible `type` ----------
        $byProduct = [];
        foreach ($audit['programs'] as $a) {
            $name = $a['product'] ?? null;
            if (! $name) continue;
            // Skip the title-row artifact «ПРОДУКТ» if it slipped through.
            if ($name === 'ПРОДУКТ') continue;
            if (! isset($byProduct[$name])) {
                $byProduct[$name] = [
                    'type'           => $a['type'] ?? null,
                    'all_red'        => true,
                    'imported_from'  => $source,
                ];
            }
            if (! ($a['has_red'] ?? false)) {
                $byProduct[$name]['all_red'] = false;
            }
            // Prefer a non-empty type encountered first.
            if (empty($byProduct[$name]['type']) && ! empty($a['type'])) {
                $byProduct[$name]['type'] = $a['type'];
            }
        }

        DB::transaction(function () use ($byProduct, $audit, $source) {
            $rows = [];
            foreach ($byProduct as $name => $meta) {
                $rows[] = [
                    'name'          => $name,
                    'type'          => $meta['type'],
                    'active'        => true,                      // umbrella stays available
                    'imported_from' => $meta['imported_from'],
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('products_catalog')->insert($chunk);
            }

            $productIdByName = DB::table('products_catalog')->pluck('id', 'name')->toArray();

            $progRows = [];
            foreach ($audit['programs'] as $a) {
                $product = $a['product'] ?? null;
                $program = $a['program'] ?? null;
                if (! $product || ! $program) continue;
                if ($product === 'ПРОДУКТ' && $program === 'ПРОГРАММА') continue;
                $productId = $productIdByName[$product] ?? null;
                if ($productId === null) continue;

                $progRows[] = [
                    'product_id'        => $productId,
                    'name'              => $program,
                    'vendor'            => isset($a['vendors']) && $a['vendors'] ? implode(' / ', $a['vendors']) : null,
                    'currency'          => isset($a['currencies']) && $a['currencies'] ? implode(' / ', $a['currencies']) : null,
                    'category'          => isset($a['categories']) && $a['categories'] ? implode(' / ', $a['categories']) : null,
                    'has_red'           => (bool) ($a['has_red'] ?? false),
                    'active'            => ! (bool) ($a['has_red'] ?? false),
                    'rate_lines'        => (int) ($a['rate_lines'] ?? 0),
                    'terms_summary'     => isset($a['terms']) && $a['terms'] ? implode(',', $a['terms']) : null,
                    'years_summary'     => isset($a['years']) && $a['years'] ? implode(',', $a['years']) : null,
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
            foreach (array_chunk($progRows, 200) as $chunk) {
                DB::table('programs_catalog')->insert($chunk);
            }
        });

        if (app()->runningInConsole()) {
            $p = DB::selectOne('SELECT COUNT(*) AS n FROM products_catalog');
            $g = DB::selectOne('
                SELECT COUNT(*) AS total,
                       COUNT(*) FILTER (WHERE active=true) AS active,
                       COUNT(*) FILTER (WHERE active=false) AS inactive
                FROM programs_catalog
            ');
            echo "  products_catalog: {$p->n} products\n";
            echo "  programs_catalog: total={$g->total}, active={$g->active}, inactive={$g->inactive}\n";
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('programs_catalog');
        Schema::dropIfExists('products_catalog');

        // Restore the previous flat program_catalog (empty — operator can
        // re-run the prior migration to repopulate from JSON).
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
};
