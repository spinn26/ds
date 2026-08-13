<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Остаток полей person → собственные колонки клиента и партнёра.
 *
 * Контакты (почта/телефон/ДР/город у клиента) перенесены раньше; здесь то, что
 * ещё жило только в person: телеграм, пол, налоговое резидентство, а у
 * партнёра — ещё и город.
 *
 * Заполняют команды clients:backfill-contacts и partners:backfill-contacts из
 * ВЕРНОЙ person (ФИО связанной person совпадает с именем карточки); у партнёра
 * с логином приоритет у WebUser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client', function (Blueprint $table) {
            $table->string('nicTG')->nullable();
            $table->string('gender')->nullable();
            $table->string('taxResidency')->nullable();
        });

        Schema::table('consultant', function (Blueprint $table) {
            $table->string('city')->nullable();
            $table->string('nicTG')->nullable();
            $table->string('gender')->nullable();
            $table->string('taxResidency')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client', function (Blueprint $table) {
            $table->dropColumn(['nicTG', 'gender', 'taxResidency']);
        });

        Schema::table('consultant', function (Blueprint $table) {
            $table->dropColumn(['city', 'nicTG', 'gender', 'taxResidency']);
        });
    }
};
