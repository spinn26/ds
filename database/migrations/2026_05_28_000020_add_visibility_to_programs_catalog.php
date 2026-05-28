<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Программы каталога получают флаги отдельной видимости — раньше форма
 * показывала чекбоксы «Виден партнёру» и «В калькуляторе», но catalog
 * не различал их от общего `active` → toggle игнорировался.
 *
 *   visible_to_resident   — показывать ли в модалке «Программы продукта»
 *                            на партнёрской витрине (`/products`).
 *   visible_to_calculator — показывать ли в дропдауне калькулятора
 *                            (Finance/Calculator.vue).
 *
 * Default = true для всех существующих строк, чтобы поведение не
 * сломалось у партнёров до того, как админ зайдёт и расставит флажки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('programs_catalog')) {
            return;
        }
        if (! Schema::hasColumn('programs_catalog', 'visible_to_resident')) {
            DB::statement('ALTER TABLE programs_catalog ADD COLUMN visible_to_resident BOOLEAN NOT NULL DEFAULT true');
        }
        if (! Schema::hasColumn('programs_catalog', 'visible_to_calculator')) {
            DB::statement('ALTER TABLE programs_catalog ADD COLUMN visible_to_calculator BOOLEAN NOT NULL DEFAULT true');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('programs_catalog')) {
            return;
        }
        if (Schema::hasColumn('programs_catalog', 'visible_to_calculator')) {
            DB::statement('ALTER TABLE programs_catalog DROP COLUMN visible_to_calculator');
        }
        if (Schema::hasColumn('programs_catalog', 'visible_to_resident')) {
            DB::statement('ALTER TABLE programs_catalog DROP COLUMN visible_to_resident');
        }
    }
};
