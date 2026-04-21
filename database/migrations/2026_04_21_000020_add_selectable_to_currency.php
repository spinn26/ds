<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currency-справочник разросся до ~30 строк, но в селекторах форм
 * (реквизиты, импорты, контракты) 95% трафика — RUB/USD/EUR/GBP.
 * Добавляем флаг `selectable`, ставим true только им; UI-эндпоинты
 * фильтруют по нему. Legacy currency остаются в БД — FK от старых
 * контрактов не теряются.
 */
return new class extends Migration
{
    private const SELECTABLE_IDS = [5, 10, 17, 67]; // USD, GBP, EUR, RUB

    public function up(): void
    {
        if (! Schema::hasColumn('currency', 'selectable')) {
            Schema::table('currency', function (Blueprint $t) {
                $t->boolean('selectable')->default(false);
            });
        }

        DB::table('currency')->update(['selectable' => false]);
        DB::table('currency')
            ->whereIn('id', self::SELECTABLE_IDS)
            ->update(['selectable' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('currency', 'selectable')) {
            Schema::table('currency', function (Blueprint $t) {
                $t->dropColumn('selectable');
            });
        }
    }
};
