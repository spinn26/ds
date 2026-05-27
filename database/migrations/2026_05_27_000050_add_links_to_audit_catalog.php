<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add editable link fields to the audit catalog so operators can type a
 * destination URL straight from Admin/Products.vue without falling back
 * to the legacy `product`/`program` tables.
 *
 *   products_catalog.open_product_url  →  «Открыть продукт» button on
 *                                         the partner storefront card.
 *   programs_catalog.form_link         →  «Открыть» button inside the
 *                                         «Программы продукта» modal.
 *
 * Both columns are nullable strings — no backfill, existing rows keep
 * their NULL until an operator edits them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_catalog', function (Blueprint $t) {
            $t->string('open_product_url', 1000)->nullable()->after('type');
        });

        Schema::table('programs_catalog', function (Blueprint $t) {
            $t->string('form_link', 1000)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('programs_catalog', function (Blueprint $t) {
            $t->dropColumn('form_link');
        });

        Schema::table('products_catalog', function (Blueprint $t) {
            $t->dropColumn('open_product_url');
        });
    }
};
