<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Прогноз начисления» (Lena, 16.06.2026).
 *
 * - products_catalog.accrual_forecast_months — сколько месяцев прибавлять
 *   к месяцу активации контракта (из колонки «Период выплаты» матрицы
 *   тарифов: «в месяц оплаты клиента» = 0, «плюс 1 месяц» = 1, «плюс 2
 *   месяца» = 2). По умолчанию 0.
 * - contract.activated_at — дата перехода контракта в статус «Активирован»
 *   (раньше нигде не фиксировалась; нужна как точка отсчёта прогноза).
 * - contract.accrual_forecast — системное поле: прогноз/факт месяца
 *   начисления. Заполняется только для Активирован; при наличии транзакции
 *   подменяется фактической датой. Обслуживается пересчётом, не руками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_catalog', function (Blueprint $table) {
            $table->integer('accrual_forecast_months')->default(0);
        });

        Schema::table('contract', function (Blueprint $table) {
            if (! Schema::hasColumn('contract', 'activated_at')) {
                $table->date('activated_at')->nullable();
            }
            if (! Schema::hasColumn('contract', 'accrual_forecast')) {
                $table->date('accrual_forecast')->nullable();
            }
        });

        // Бэкфилл значений из тарифной матрицы (match by name — B-вариант,
        // владелец проверит руками). Всё, что не перечислено, остаётся 0.
        DB::table('products_catalog')
            ->whereIn('name', [
                'Axevil',
                'Альфа',
                'БКС Страхование Жизни',
                'ЗПИФ Акцент',
                'ЗПИФ Парус',
                'Инвестиционное консультирование',
            ])
            ->update(['accrual_forecast_months' => 1]);

        DB::table('products_catalog')
            ->where('name', 'Недвижимость Шефер')
            ->update(['accrual_forecast_months' => 2]);
    }

    public function down(): void
    {
        Schema::table('products_catalog', function (Blueprint $table) {
            $table->dropColumn('accrual_forecast_months');
        });

        Schema::table('contract', function (Blueprint $table) {
            $table->dropColumn(['activated_at', 'accrual_forecast']);
        });
    }
};
