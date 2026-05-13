<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram user_id (`from.id` в Bot API) — не меняется со временем,
 * в отличие от chat_id (теоретически у супергрупп может сменится).
 * Сохраняем оба: chat_id куда слать сообщения, user_id для трекинга.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('WebUser', 'telegram_user_id')) {
            DB::statement('ALTER TABLE "WebUser" ADD COLUMN telegram_user_id text NULL');
        }
        if (! Schema::hasColumn('WebUser', 'telegram_username')) {
            DB::statement('ALTER TABLE "WebUser" ADD COLUMN telegram_username text NULL');
        }
        if (! Schema::hasColumn('WebUser', 'telegram_linked_at')) {
            DB::statement('ALTER TABLE "WebUser" ADD COLUMN telegram_linked_at timestamp NULL');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "WebUser" DROP COLUMN IF EXISTS telegram_user_id');
        DB::statement('ALTER TABLE "WebUser" DROP COLUMN IF EXISTS telegram_username');
        DB::statement('ALTER TABLE "WebUser" DROP COLUMN IF EXISTS telegram_linked_at');
    }
};
