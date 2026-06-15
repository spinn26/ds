<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Признак «основной / дополнительный» продукт в каталоге.
 *
 * Оператор при создании/редактировании продукта выбирает, основной он или
 * дополнительный. В партнёрской витрине (ФК-каталог) основные продукты
 * выводятся первыми, дополнительные — после.
 *
 * Default = true (основной) — обратная совместимость: все существующие
 * продукты считаются основными, порядок витрины для них не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products_catalog')) {
            return;
        }
        if (! Schema::hasColumn('products_catalog', 'is_primary')) {
            DB::statement('ALTER TABLE products_catalog ADD COLUMN is_primary BOOLEAN NOT NULL DEFAULT true');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products_catalog')) {
            return;
        }
        if (Schema::hasColumn('products_catalog', 'is_primary')) {
            DB::statement('ALTER TABLE products_catalog DROP COLUMN is_primary');
        }
    }
};
