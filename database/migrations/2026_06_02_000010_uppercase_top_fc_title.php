<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Приводит заголовок 6-го уровня к каноническому виду таблицы квалификаций:
 * «Топ ФК» → «ТОП ФК» (как в актуальной матрице БМ). Чисто отображаемая
 * строка (title в status_levels), нигде в коде по значению не сравнивается.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('status_levels')->where('id', 6)
            ->where('title', 'Топ ФК')
            ->update(['title' => 'ТОП ФК']);
    }

    public function down(): void
    {
        DB::table('status_levels')->where('id', 6)
            ->where('title', 'ТОП ФК')
            ->update(['title' => 'Топ ФК']);
    }
};
