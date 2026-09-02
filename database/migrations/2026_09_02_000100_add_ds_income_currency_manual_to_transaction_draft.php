<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручной ввод «Дохода ДС (валюта)» в черновике транзакции.
 *
 * Колонка считалась только автоматически: доход ДС в рублях, делённый на курс
 * валюты на месяц сделки. Но по части валютных сделок сумма приходит от
 * поставщика готовой (в счёте) и с курсом платформы не сходится — округления,
 * комиссии банка, иная дата фиксации. Тогда оператору нужно вписать её как
 * есть, а не подгонять курс.
 *
 * Семантика поля совпадает с соседним «Доход ДС»: оператор вводит сумму
 * С НДС — так она приходит в счёте. Пусто = считаем сами, как раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transaction_draft', 'dsIncomeCurrencyManual')) {
            return;
        }

        Schema::table('transaction_draft', function (Blueprint $table) {
            // nullable без default: NULL здесь — не «ноль дохода», а «оператор
            // не вмешивался, считай сам». Отличать одно от другого обязательно.
            $table->decimal('dsIncomeCurrencyManual', 18, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transaction_draft', 'dsIncomeCurrencyManual')) {
            return;
        }

        Schema::table('transaction_draft', function (Blueprint $table) {
            $table->dropColumn('dsIncomeCurrencyManual');
        });
    }
};
