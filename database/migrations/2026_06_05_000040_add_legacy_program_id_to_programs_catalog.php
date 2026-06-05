<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs_catalog', function ($table) {
            $table->integer('legacy_program_id')->nullable()->after('product_id');
        });

        // Backfill pass 1: match by name + product (via products_catalog.legacy_product_id).
        // PostgreSQL UPDATE…FROM: target table cannot be aliased in JOIN conditions,
        // so we use comma-separated FROM with WHERE predicates instead.
        DB::statement('
            UPDATE programs_catalog
            SET legacy_program_id = p.id
            FROM products_catalog pc,
                 product lp,
                 program p
            WHERE pc.id = programs_catalog.product_id
              AND lp.id = pc.legacy_product_id
              AND p.name = programs_catalog.name
              AND p.product = lp.id
              AND programs_catalog.legacy_program_id IS NULL
        ');

        // Insert still-unmatched active programs into legacy program table.
        DB::statement('
            WITH unmatched AS (
                SELECT programs_catalog.id AS cat_id,
                       programs_catalog.name,
                       lp.id AS legacy_product_id,
                       ROW_NUMBER() OVER (ORDER BY programs_catalog.product_id, programs_catalog.name) AS rn
                FROM programs_catalog
                JOIN products_catalog pc ON pc.id = programs_catalog.product_id
                                        AND pc.legacy_product_id IS NOT NULL
                JOIN product lp          ON lp.id = pc.legacy_product_id
                WHERE programs_catalog.active = true
                  AND programs_catalog.legacy_program_id IS NULL
            ), max_id AS (
                SELECT COALESCE(MAX(id), 0) AS v FROM program
            )
            INSERT INTO program (id, name, product, active)
            SELECT max_id.v + unmatched.rn,
                   unmatched.name,
                   unmatched.legacy_product_id,
                   true
            FROM unmatched, max_id
            ON CONFLICT (id) DO NOTHING
        ');

        // Backfill pass 2: pick up the rows just inserted above.
        DB::statement('
            UPDATE programs_catalog
            SET legacy_program_id = p.id
            FROM products_catalog pc,
                 product lp,
                 program p
            WHERE pc.id = programs_catalog.product_id
              AND lp.id = pc.legacy_product_id
              AND p.name = programs_catalog.name
              AND p.product = lp.id
              AND programs_catalog.legacy_program_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('programs_catalog', function ($table) {
            $table->dropColumn('legacy_program_id');
        });
    }
};
