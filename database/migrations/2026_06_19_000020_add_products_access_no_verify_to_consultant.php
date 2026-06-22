<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручной флаг «доступ к продуктам без верификации»: админ может открыть
 * раздел «Продукты» конкретному партнёру, не дожидаясь верификации реквизитов
 * (управляется в админке → Пользователи). Учитывается в ProductController::checkAccess.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consultant', 'products_access_no_verify')) {
            Schema::table('consultant', function (Blueprint $table) {
                $table->boolean('products_access_no_verify')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('consultant', 'products_access_no_verify')) {
            Schema::table('consultant', function (Blueprint $table) {
                $table->dropColumn('products_access_no_verify');
            });
        }
    }
};
