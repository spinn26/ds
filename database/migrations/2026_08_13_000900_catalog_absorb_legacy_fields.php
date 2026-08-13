<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Перенос расчётных полей из legacy product/program в каталог.
 *
 * Каталог держал только витрину: названия, тарифную сетку, цвета, сводки. А
 * расчёт комиссий читает у legacy-программы совсем другое — как считать ЛП
 * (pointsMethod/Formula/Min/Max), как считать %ДС (dsPercent,
 * commissionCalcProperty, kvPayoutYear), условия сделки (term, termContract,
 * fixedCost, noComission). Этих полей в каталоге НЕ БЫЛО ВОВСЕ.
 *
 * Без переноса слияние каталогов считало бы комиссии по пустым полям —
 * поймано на репетиции 13.08.2026: техника перенумерации отработала, а расчёт
 * остался бы без входных данных.
 *
 * Значения копируются один в один по связке legacy_program_id/
 * legacy_product_id. Имена приводим к змеиному стилю каталога.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs_catalog', function (Blueprint $table) {
            // Как считать ЛП по сделке.
            $table->string('points_method')->nullable();
            $table->text('points_formula')->nullable();
            $table->decimal('points_min', 20, 4)->nullable();
            $table->decimal('points_max', 20, 4)->nullable();
            // Как считать %ДС и комиссию.
            $table->decimal('ds_percent', 12, 4)->nullable();
            $table->string('commission_calc_property')->nullable();
            $table->string('kv_payout_year')->nullable();
            $table->decimal('fixed_cost', 20, 4)->nullable();
            $table->boolean('no_commission')->default(false);
            // Условия и реквизиты.
            $table->string('term')->nullable();
            $table->string('term_contract')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('category_name')->nullable();
        });

        Schema::table('products_catalog', function (Blueprint $table) {
            // Признаки, по которым витрина решает, что спрашивать у оператора.
            $table->boolean('has_property')->default(false);
            $table->boolean('has_term')->default(false);
            $table->boolean('has_year_kv')->default(false);
            $table->boolean('no_commission')->default(false);
            $table->string('form_link')->nullable();
            $table->string('education_url')->nullable();
            $table->string('instruction_url')->nullable();
            $table->integer('priority')->nullable();
        });

        // Значения — из legacy по связке. Таблицы ещё на месте: слияние идёт
        // отдельной командой после этой миграции.
        if (DB::selectOne("SELECT to_regclass('public.program') AS t")->t) {
            DB::statement(<<<'SQL'
                UPDATE programs_catalog pg SET
                    points_method = pr."pointsMethod",
                    points_formula = pr."pointsFormula",
                    points_min = pr."pointsMin",
                    points_max = pr."pointsMax",
                    ds_percent = pr."dsPercent",
                    commission_calc_property = pr."commissionCalcProperty",
                    kv_payout_year = pr."kvPayoutYear",
                    fixed_cost = pr."fixedCost",
                    term = pr.term,
                    term_contract = pr."termContract",
                    provider_name = coalesce(pg.vendor, pr."providerName"),
                    category_name = pr."categoryName"
                FROM program pr
                WHERE pr.id = pg.legacy_program_id
                SQL);
        }

        if (DB::selectOne("SELECT to_regclass('public.product') AS t")->t) {
            DB::statement(<<<'SQL'
                UPDATE products_catalog pc SET
                    has_property = coalesce(p.has_property, false),
                    has_term = coalesce(p.has_term, false),
                    has_year_kv = coalesce(p.has_year_kv, false),
                    no_commission = coalesce(p."noComission", false),
                    form_link = p."formLink",
                    education_url = p."educationUrl",
                    instruction_url = p."instructionUrl",
                    priority = p.priority
                FROM product p
                WHERE p.id = pc.legacy_product_id
                SQL);
        }
    }

    public function down(): void
    {
        Schema::table('programs_catalog', function (Blueprint $table) {
            $table->dropColumn([
                'points_method', 'points_formula', 'points_min', 'points_max',
                'ds_percent', 'commission_calc_property', 'kv_payout_year',
                'fixed_cost', 'no_commission', 'term', 'term_contract',
                'provider_name', 'category_name',
            ]);
        });

        Schema::table('products_catalog', function (Blueprint $table) {
            $table->dropColumn([
                'has_property', 'has_term', 'has_year_kv', 'no_commission',
                'form_link', 'education_url', 'instruction_url', 'priority',
            ]);
        });
    }
};
