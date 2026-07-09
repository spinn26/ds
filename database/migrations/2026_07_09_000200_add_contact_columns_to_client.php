<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Клиент владеет своими контактами. Раньше у `client` НЕ было email/phone/
 * birthDate/city — они брались только из связанной `person`, и один неверный
 * (но валидный) указатель client.person подставлял клиенту чужие контакты
 * (инцидент 2026-07-09). Теперь client хранит собственную копию контактов;
 * person остаётся фолбэком (историч.). Backfill из ВЕРНОЙ person — командой
 * clients:backfill-contacts.
 *
 * Типы = как в person (text): birthDate хранится текстом (YYYY-MM-DD),
 * city — id города текстом (резолвится через таблицу city), email/phone — text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client', function ($table) {
            if (! Schema::hasColumn('client', 'email')) $table->text('email')->nullable();
            if (! Schema::hasColumn('client', 'phone')) $table->text('phone')->nullable();
            if (! Schema::hasColumn('client', 'birthDate')) $table->text('birthDate')->nullable();
            if (! Schema::hasColumn('client', 'city')) $table->text('city')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client', function ($table) {
            foreach (['email', 'phone', 'birthDate', 'city'] as $col) {
                if (Schema::hasColumn('client', $col)) $table->dropColumn($col);
            }
        });
    }
};
