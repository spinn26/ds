<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Второй справочник курсов валют — для отчётов руководителей компании.
 * Работает параллельно с `currencyRate` (для ФК).
 * Автозаполнение: CopyMonthlyManagementCurrencyRates (01 числа каждого месяца).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_currency_rate', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('currency');
            $table->decimal('rate', 15, 8)->default(1);
            $table->date('date');
            $table->timestamps();

            $table->unique(['currency', 'date'], 'mgmt_rate_currency_date_unique');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_currency_rate');
    }
};
