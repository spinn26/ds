<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Колонка product.description нужна для краткого описания, которое
 * показывается в карточке витрины и редактируется в Admin/Products.vue.
 * UI её показывает, бэк пытается записать — но в legacy schema из
 * Directual колонки нет, save падал с SQLSTATE[42703].
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product', 'description')) {
            Schema::table('product', function (Blueprint $table) {
                $table->text('description')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product', 'description')) {
            Schema::table('product', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
