<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Зеркало миграции 2026_05_28_000020 для products_catalog: продукт-зонтик
 * получает свои собственные флаги visible_to_resident / visible_to_calculator,
 * чтобы оператор мог одним кликом убрать всю продуктовую линейку (например
 * «Инсмарт») с калькулятора, не пробегая по каждой программе вручную.
 *
 * Семантика для калькулятора: программу видно, только если ОБА уровня
 * (продукт И программа) имеют visible_to_calculator = true. То же для
 * витрины с visible_to_resident.
 *
 * Default = true — обратная совместимость со всеми существующими строками.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products_catalog')) {
            return;
        }
        if (! Schema::hasColumn('products_catalog', 'visible_to_resident')) {
            DB::statement('ALTER TABLE products_catalog ADD COLUMN visible_to_resident BOOLEAN NOT NULL DEFAULT true');
        }
        if (! Schema::hasColumn('products_catalog', 'visible_to_calculator')) {
            DB::statement('ALTER TABLE products_catalog ADD COLUMN visible_to_calculator BOOLEAN NOT NULL DEFAULT true');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products_catalog')) {
            return;
        }
        if (Schema::hasColumn('products_catalog', 'visible_to_calculator')) {
            DB::statement('ALTER TABLE products_catalog DROP COLUMN visible_to_calculator');
        }
        if (Schema::hasColumn('products_catalog', 'visible_to_resident')) {
            DB::statement('ALTER TABLE products_catalog DROP COLUMN visible_to_resident');
        }
    }
};
