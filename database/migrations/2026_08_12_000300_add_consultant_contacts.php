<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Собственные контакты партнёра — чтобы перестать читать person.
 *
 * У партнёра с логином канон контактов — WebUser. Но 897 партнёров логина не
 * имеют (импортированные ФК), и их почта/телефон/ДР лежат ТОЛЬКО в person: при
 * снятии person эти карточки остались бы пустыми. Колонки заполняет команда
 * partners:backfill-contacts, при отдаче WebUser имеет приоритет.
 *
 * Названия зеркалят client (email/phone/birthDate) — там контакты перенесли
 * в карточку 12.08.2026 тем же приёмом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            // Текст, как у client.birthDate: legacy-схема хранит дату строкой,
            // и своя типизация здесь разошлась бы с соседней таблицей.
            $table->string('birthDate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consultant', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone', 'birthDate']);
        });
    }
};
