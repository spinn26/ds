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

        // Backfill: match by name + product (via products_catalog.legacy_product_id)
        DB::statement('
            UPDATE programs_catalog g
            SET legacy_program_id = p.id
            FROM products_catalog pc
            JOIN product lp ON lp.id = pc.legacy_product_id
            JOIN program p  ON p.name = g.name AND p.product = lp.id
            WHERE pc.id = g.product_id
              AND g.legacy_program_id IS NULL
        ');

        // Insert still-unmatched active programs into legacy program table
        DB::statement('
            WITH unmatched AS (
                SELECT g.id AS cat_id, g.name, lp.id AS legacy_product_id,
                       ROW_NUMBER() OVER (ORDER BY g.product_id, g.name) AS rn
                FROM programs_catalog g
                JOIN products_catalog pc ON pc.id = g.product_id AND pc.legacy_product_id IS NOT NULL
                JOIN product lp          ON lp.id = pc.legacy_product_id
                WHERE g.active = true AND g.legacy_program_id IS NULL
            ), max_id AS (
                SELECT COALESCE(MAX(id), 0) AS v FROM program
            )
            INSERT INTO program (id, name, product, active)
            SELECT max_id.v + unmatched.rn, unmatched.name, unmatched.legacy_product_id, true
            FROM unmatched, max_id
            ON CONFLICT (id) DO NOTHING
        ');

        // Second backfill pass: pick up just-inserted rows
        DB::statement('
            UPDATE programs_catalog g
            SET legacy_program_id = p.id
            FROM products_catalog pc
            JOIN product lp ON lp.id = pc.legacy_product_id
            JOIN program p  ON p.name = g.name AND p.product = lp.id
            WHERE pc.id = g.product_id
              AND g.legacy_program_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('programs_catalog', function ($table) {
            $table->dropColumn('legacy_program_id');
        });
    }
};
