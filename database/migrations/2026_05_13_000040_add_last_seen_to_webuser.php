<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WebUser.last_seen_at — для виджета «Кто онлайн».
 * Vue-клиент пингует /me/heartbeat раз в 30 сек, бэк ставит now().
 * Виджет считает онлайн всех со last_seen_at в пределах 90 сек.
 *
 * Schema::table() добавляет колонку с квотингом camelCase-имени —
 * нужно через raw, потому что legacy WebUser живёт как "WebUser",
 * а Laravel-builder ломает кавычки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('WebUser', 'last_seen_at')) {
            DB::statement('ALTER TABLE "WebUser" ADD COLUMN last_seen_at TIMESTAMP NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS webuser_last_seen_at_idx ON "WebUser" (last_seen_at)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS webuser_last_seen_at_idx');
        if (Schema::hasColumn('WebUser', 'last_seen_at')) {
            DB::statement('ALTER TABLE "WebUser" DROP COLUMN last_seen_at');
        }
    }
};
