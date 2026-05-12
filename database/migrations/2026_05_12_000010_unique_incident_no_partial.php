<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Partial-unique index по incident_no — последний рубеж от дублей
 * номеров инцидентов. Основная защита от race condition в коде
 * (advisory lock в ChatController::markIncident), но индекс ловит
 * и обход через прямые SQL-апдейты.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS chat_tickets_incident_no_unique
            ON chat_tickets (incident_no)
            WHERE incident_no IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS chat_tickets_incident_no_unique');
    }
};
