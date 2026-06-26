<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поставщик на уровне продукта (products_catalog.provider_name).
 * Раньше поставщик задавался только построчно в каждой программе
 * (programs_catalog.vendor → legacy program.providerName). Теперь его
 * можно выбрать один раз в форме продукта; при сохранении значение
 * проставляется всем программам продукта, чтобы отчёты («Комиссии»,
 * «Матрица продаж») его подхватили.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('products_catalog')) return;
        if (Schema::hasColumn('products_catalog', 'provider_name')) return;

        Schema::table('products_catalog', function (Blueprint $table) {
            $table->string('provider_name', 255)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products_catalog')) return;
        if (! Schema::hasColumn('products_catalog', 'provider_name')) return;

        Schema::table('products_catalog', function (Blueprint $table) {
            $table->dropColumn('provider_name');
        });
    }
};
