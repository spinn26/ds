<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Доход ДС» в ВАЛЮТЕ КОНТРАКТА (2026-08-07, ТЗ «Отображение поля Доход ДС
 * в валюте контракта»).
 *
 * Существующее зеркало `commissionsAmountUSD` для этого не годится: оно всегда
 * делит рублёвый доход на курс ДОЛЛАРА, поэтому для евровых контрактов
 * показывает долларовый эквивалент. Пример с прода: транзакция 650 €, доход ДС
 * 1499.49 ₽, в commissionsAmountUSD лежит 21.43 — это $, а в евро было бы
 * 18.57. Переиспользовать колонку с именем ...USD под евро нельзя: имя будет
 * врать отчётам, которые её уже читают (AdminFinanceController, /manage/commissions).
 *
 * Поэтому отдельное поле. Считается по курсу валюты транзакции на МЕСЯЦ сделки —
 * тем же справочником, что и amountRUB, чтобы обратный пересчёт сходился.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transaction', 'commissionsAmountCurrency')) {
            return;
        }
        Schema::table('transaction', function (Blueprint $table) {
            $table->decimal('commissionsAmountCurrency', 20, 6)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transaction', 'commissionsAmountCurrency')) {
            return;
        }
        Schema::table('transaction', function (Blueprint $table) {
            $table->dropColumn('commissionsAmountCurrency');
        });
    }
};
