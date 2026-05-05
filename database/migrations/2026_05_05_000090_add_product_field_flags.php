<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Config-флаги для условного показа полей транзакций / калькулятора.
 *
 * Раньше в формах/таблицах транзакций ВСЕГДА показывались колонки
 * «Свойство», «Срок контракта», «Год КВ» — даже у продуктов где эти
 * параметры не релевантны (страхование без срока, разовые услуги
 * без КВ-схемы). По правкам Лены 2026-05-05: «должно выводить
 * релевантную информацию».
 *
 * Backfill основан на фактическом использовании в существующих программах:
 * - has_property: продукт имеет программы с commissionCalcProperty IS NOT NULL
 * - has_term: продукт имеет программы с termContract IS NOT NULL
 * - has_year_kv: продукт имеет связанные dsCommission с score IS NOT NULL
 *
 * После накатки админ может ручно править флаги в Products.vue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product', 'has_property')) {
            Schema::table('product', function (Blueprint $table) {
                $table->boolean('has_property')->default(false)->after('publish_status');
                $table->boolean('has_term')->default(false)->after('has_property');
                $table->boolean('has_year_kv')->default(false)->after('has_term');
            });
        }

        // Backfill из реальных данных программ.
        DB::statement('
            UPDATE product p
            SET has_property = EXISTS (
                SELECT 1 FROM program pr
                WHERE pr.product = p.id
                  AND pr."commissionCalcProperty" IS NOT NULL
                  AND pr.active = true
            )
        ');

        // «Срок контракта» хранится в program.term (число лет) —
        // termContract это FK на справочник, не на каждой программе.
        DB::statement('
            UPDATE product p
            SET has_term = EXISTS (
                SELECT 1 FROM program pr
                WHERE pr.product = p.id
                  AND pr.term IS NOT NULL
                  AND pr.term > 0
                  AND pr.active = true
            )
        ');

        // «Год КВ» хранится в transaction.score (varchar). Backfill:
        // продукт имеет хотя бы одну транзакцию по своему контракту с
        // непустым score.
        DB::statement('
            UPDATE product p
            SET has_year_kv = EXISTS (
                SELECT 1 FROM "transaction" t
                JOIN contract c ON c.id = t.contract
                WHERE c.product = p.id
                  AND t.score IS NOT NULL
                  AND t.score <> \'\'
                  AND t."deletedAt" IS NULL
            )
        ');

        if (app()->runningInConsole()) {
            $stats = DB::selectOne('
                SELECT
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE has_property) AS with_property,
                    COUNT(*) FILTER (WHERE has_term) AS with_term,
                    COUNT(*) FILTER (WHERE has_year_kv) AS with_year_kv
                FROM product WHERE active = true
            ');
            echo "  product flags backfill: {$stats->total} total, "
               . "property={$stats->with_property}, term={$stats->with_term}, "
               . "year_kv={$stats->with_year_kv}\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product', 'has_property')) {
            Schema::table('product', function (Blueprint $table) {
                $table->dropColumn(['has_property', 'has_term', 'has_year_kv']);
            });
        }
    }
};
