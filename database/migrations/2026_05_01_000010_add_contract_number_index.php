<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Партиальный индекс на contract(number) для быстрого поиска
 * в Manual TX списке (~17k строк, ilike '%…%' с whereNotNull).
 * Без этого Postgres делает seq-scan на каждый запрос.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CONCURRENTLY нельзя использовать внутри транзакции — отключаем
        // обёртку Laravel и пишем напрямую.
        DB::statement('CREATE INDEX IF NOT EXISTS contract_number_idx ON contract(number) WHERE number IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contract_number_idx');
    }

    public $withinTransaction = false;
};
