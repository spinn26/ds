<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Добавляет недостающие статусы активности партнёров:
 * - Зарегистрирован (id=4)
 * - Исключен (id=5)
 *
 * Существующие: Активный(1), Неактивный(2), Терминирован(3)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Зарегистрирован
        if (! DB::table('directory_of_activities')->where('id', 4)->exists()) {
            DB::table('directory_of_activities')->insert([
                'id' => 4,
                'name' => 'Зарегистрирован',
                'comment' => '90 дней на активацию, ЛП >= 500 баллов',
            ]);
        }

        // Исключен
        if (! DB::table('directory_of_activities')->where('id', 5)->exists()) {
            DB::table('directory_of_activities')->insert([
                'id' => 5,
                'name' => 'Исключен',
                'comment' => 'Полный бан, без возможности восстановления',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('directory_of_activities')->whereIn('id', [4, 5])->delete();
    }
};
