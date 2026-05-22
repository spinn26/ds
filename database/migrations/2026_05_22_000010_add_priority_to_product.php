<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Приоритет витрины продукта (per spec заказчика 2026-05-22):
 *  1 — высший (зелёные ячейки в Google Sheets)
 *  2 — обычный (жёлтые)
 *  3 — низший (без заливки)
 *  NULL — продукт-архив, не показываем на витрине, но активен для
 *         расчёта комиссий по существующим контрактам (красные ячейки).
 *
 * Витрина партнёра сортирует ASC, NULL исключает.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('product', 'priority')) {
            Schema::table('product', function (Blueprint $table) {
                $table->smallInteger('priority')->nullable()->after('publish_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product', 'priority')) {
            Schema::table('product', function (Blueprint $table) {
                $table->dropColumn('priority');
            });
        }
    }
};
