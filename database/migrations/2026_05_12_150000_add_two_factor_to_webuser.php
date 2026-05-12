<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Поля 2FA на WebUser. Legacy-таблица camelCase — добавляем через
 * raw SQL чтобы Postgres правильно квотировал имена.
 *
 * two_factor_secret  — TOTP shared-secret (зашифрован Crypt::encrypt)
 * two_factor_enabled — bool (true = шаг 2 при логине активен)
 * two_factor_confirmed_at — момент подтверждения (показывает что setup завершён)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('WebUser', 'two_factor_secret')) {
            DB::statement('ALTER TABLE "WebUser" ADD COLUMN two_factor_secret text NULL');
        }
        if (! Schema::hasColumn('WebUser', 'two_factor_enabled')) {
            DB::statement('ALTER TABLE "WebUser" ADD COLUMN two_factor_enabled boolean NOT NULL DEFAULT false');
        }
        if (! Schema::hasColumn('WebUser', 'two_factor_confirmed_at')) {
            DB::statement('ALTER TABLE "WebUser" ADD COLUMN two_factor_confirmed_at timestamp NULL');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "WebUser" DROP COLUMN IF EXISTS two_factor_secret');
        DB::statement('ALTER TABLE "WebUser" DROP COLUMN IF EXISTS two_factor_enabled');
        DB::statement('ALTER TABLE "WebUser" DROP COLUMN IF EXISTS two_factor_confirmed_at');
    }
};
