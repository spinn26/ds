<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Временно отключить проверку FK
        DB::statement('SET session_replication_role = replica');

        // 1. Перевести всех Неактивных (activity=2) в Активных (activity=1)
        DB::statement("UPDATE consultant SET activity = 1 WHERE activity = 2");

        // 2. Переименовать в directory_of_activities
        DB::statement("UPDATE directory_of_activities SET name = 'Активен' WHERE id = 1");
        DB::statement("UPDATE directory_of_activities SET name = 'Неактивный (deprecated)', comment = 'Удалён' WHERE id = 2");

        // 3. Переименовать статусы
        DB::statement("UPDATE status SET title = 'Партнёр' WHERE id = 2");
        DB::statement("UPDATE status SET title = 'Партнёр' WHERE id = 3");

        // 4. Обновить statusesName
        DB::statement("UPDATE consultant SET \"statusesName\" = 'Партнёр' WHERE \"statusesName\" = 'Финансовый консультант'");
        DB::statement("UPDATE consultant SET \"statusesName\" = 'Партнёр' WHERE \"statusesName\" = 'Резидент'");

        // Включить FK обратно
        DB::statement('SET session_replication_role = DEFAULT');
    }

    public function down(): void
    {
        DB::statement('SET session_replication_role = replica');
        DB::statement("UPDATE directory_of_activities SET name = 'Активный' WHERE id = 1");
        DB::statement("UPDATE directory_of_activities SET name = 'Неактивный', comment = NULL WHERE id = 2");
        DB::statement("UPDATE status SET title = 'Финансовый консультант' WHERE id = 2");
        DB::statement("UPDATE status SET title = 'Резидент' WHERE id = 3");
        DB::statement('SET session_replication_role = DEFAULT');
    }
};
