<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет поле `activation_forecast` (дата) в таблицу contract.
 * Обязательно для всех статусов кроме «Активирован» (contractStatus.id = 1).
 * Очищается автоматически при переводе контракта в статус «Активирован».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract', function (Blueprint $table) {
            $table->date('activation_forecast')->nullable()->after('closeDate');
        });
    }

    public function down(): void
    {
        Schema::table('contract', function (Blueprint $table) {
            $table->dropColumn('activation_forecast');
        });
    }
};
