<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таргетинг инструкций на конкретные роли.
 *
 * `audience` (partner|staff|both) остаётся грубым фильтром, `roles` —
 * уточнением: NULL/пустой массив = видно всем в рамках аудитории (так что
 * существующие инструкции продолжают работать как раньше).
 *
 * Формат и семантика те же, что у announcements.roles и feature_flags.roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructions', function (Blueprint $table) {
            $table->jsonb('roles')->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('instructions', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
