<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FK client.person → person(id). Защищает от «висячих» указателей: любая
 * будущая переномерация/запись, оставившая client.person без person, упадёт
 * громко, а не подставит клиенту чужие контакты (инцидент 2026-07-09).
 *
 * NOT VALID: добавляется мгновенно (без full-scan/долгого локала), но при этом
 * ЭНФОРСИТСЯ для всех новых INSERT/UPDATE. Существующие строки чистые
 * (0 orphan на проде и локали), так что VALIDATE можно выполнить отдельно.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("
            SELECT 1 FROM pg_constraint WHERE conname = 'client_person_fkey'
        ");
        if (! $exists) {
            DB::statement('ALTER TABLE client
                ADD CONSTRAINT client_person_fkey
                FOREIGN KEY (person) REFERENCES person(id) NOT VALID');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE client DROP CONSTRAINT IF EXISTS client_person_fkey');
    }
};
