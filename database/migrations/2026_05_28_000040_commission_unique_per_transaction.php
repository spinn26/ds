<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Страховка от дублирования commission-строк при race-condition в
 * CommissionCalculator. Уникальность только для commission, привязанной
 * к транзакции — non-transactional строки (ручные начисления, pool)
 * могут множиться легитимно (несколько начислений одному партнёру
 * в один месяц).
 *
 * Partial unique index, чтобы:
 *   - transaction IS NOT NULL → каждая (tx, consultant, chainOrder, type)
 *     встречается ровно один раз среди живых (deletedAt IS NULL);
 *   - transaction IS NULL → не ограничиваем (nonTransactional / poolLog).
 *
 * Если миграция упадёт из-за существующих дублей (на проде сейчас 0,
 * но на других средах может быть) — нужно сначала вручную почистить
 * лишние строки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission')) {
            return;
        }
        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS
                commission_unique_per_transaction_idx
            ON commission (transaction, consultant, "chainOrder", type)
            WHERE transaction IS NOT NULL AND "deletedAt" IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS commission_unique_per_transaction_idx');
    }
};
