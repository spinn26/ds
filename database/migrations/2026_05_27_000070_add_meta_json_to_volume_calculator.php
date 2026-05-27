<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * volumeCalculator теперь принимает расчёты из products_catalog —
 * программа из нового каталога не имеет FK-валидного id в legacy
 * `program`, поэтому FK-колонка `program` остаётся NULL. Чтобы история
 * расчётов оставалась читаемой (имя продукта / программы / тарифа),
 * добавляем JSONB-колонку `meta_json` с произвольным контекстом.
 *
 *   meta_json = {
 *     "source":      "products_catalog",
 *     "product_id":  41,
 *     "product_name":"Investor Trust Evolution",
 *     "program_id":  102,
 *     "program_name":"EVO",
 *     "property":    "MF",
 *     "term":        25,
 *     "year":        1,
 *     "ds_percent":  "0.775"
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('volumeCalculator', 'meta_json')) {
            DB::statement('ALTER TABLE "volumeCalculator" ADD COLUMN meta_json JSONB NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('volumeCalculator', 'meta_json')) {
            DB::statement('ALTER TABLE "volumeCalculator" DROP COLUMN meta_json');
        }
    }
};
