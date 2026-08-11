<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Шаг «наставник» после самовосстановления обязателен (2026-08-10).
 *
 * Раньше шаг жил только в открытом окне на клиенте: закрыл вкладку между
 * восстановлением и выбором — и шаг пропущен (партнёр молча оставался за
 * прежним наставником). Теперь состояние хранится на сервере: флаг ставится
 * при восстановлении и снимается ТОЛЬКО ответом партнёра — «остаться» или
 * «сменить». Пока флаг стоит, окно показывается при каждом входе, а акцепт
 * документов ждёт.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('consultant', 'reinstate_mentor_pending')) {
            return;
        }
        Schema::table('consultant', function (Blueprint $table) {
            $table->boolean('reinstate_mentor_pending')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('consultant', 'reinstate_mentor_pending')) {
            return;
        }
        Schema::table('consultant', function (Blueprint $table) {
            $table->dropColumn('reinstate_mentor_pending');
        });
    }
};
