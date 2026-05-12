<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('WebUser', 'telegram_chat_id')) {
            DB::statement('ALTER TABLE "WebUser" ADD COLUMN telegram_chat_id text NULL');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE "WebUser" DROP COLUMN IF EXISTS telegram_chat_id');
    }
};
