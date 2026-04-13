<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. Убираем статус "Неактивный" (id=2) — все с activity=2 переводим в activity=1 (Активный)
 * 2. Переименовываем "Финансовый консультант" и "Резидент" → "Партнёр" в таблице status
 * 3. Обновляем statusesName на consultant
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Перевести всех Неактивных (activity=2) в Активных (activity=1)
        DB::table('consultant')
            ->where('activity', 2)
            ->update(['activity' => 1]);

        // 2. Переименовать в directory_of_activities
        DB::table('directory_of_activities')
            ->where('id', 1)
            ->update(['name' => 'Активен']);

        // Удалить "Неактивный" (id=2) — или переименовать в deprecated
        DB::table('directory_of_activities')
            ->where('id', 2)
            ->update(['name' => 'Неактивный (deprecated)', 'comment' => 'Статус удалён, записи переведены в Активен']);

        // 3. Переименовать статусы в таблице status
        // "Финансовый консультант" (id=2) → "Партнёр"
        DB::table('status')
            ->where('id', 2)
            ->update(['title' => 'Партнёр']);

        // "Резидент" (id=3) → "Партнёр"
        DB::table('status')
            ->where('id', 3)
            ->update(['title' => 'Партнёр']);

        // 4. Обновить statusesName на consultant
        DB::table('consultant')
            ->where('statusesName', 'Финансовый консультант')
            ->update(['statusesName' => 'Партнёр']);

        DB::table('consultant')
            ->where('statusesName', 'Резидент')
            ->update(['statusesName' => 'Партнёр']);
    }

    public function down(): void
    {
        // Откатить названия
        DB::table('directory_of_activities')->where('id', 1)->update(['name' => 'Активный']);
        DB::table('directory_of_activities')->where('id', 2)->update(['name' => 'Неактивный', 'comment' => null]);
        DB::table('status')->where('id', 2)->update(['title' => 'Финансовый консультант']);
        DB::table('status')->where('id', 3)->update(['title' => 'Резидент']);
    }
};
