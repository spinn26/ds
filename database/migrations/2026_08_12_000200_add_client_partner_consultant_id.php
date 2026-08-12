<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Явная связь «карточка клиента ↔ партнёр» вместо общего person.
 *
 * Признак «этот клиент является партнёром» считался через совпадение
 * client.person = consultant.person — наследство Directual. По факту связь
 * неверна: из 812 таких пар 32 ведут на другого человека (id person разошлись
 * при консолидации), а person заполнен лишь у 894 партнёров из 1968 — то есть
 * у большинства признак не определялся вовсе.
 *
 * Колонка заполняется командой clients:link-partners и дальше ведётся кодом.
 * FK намеренно нет: consultant мягко удаляется, жёсткая ссылка мешала бы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_consultant_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('client', function (Blueprint $table) {
            $table->dropColumn('partner_consultant_id');
        });
    }
};
