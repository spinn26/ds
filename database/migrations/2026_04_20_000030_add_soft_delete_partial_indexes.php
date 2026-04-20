<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Partial indexes on `deletedAt IS NULL` for the hot tables.
 *
 * Before: COUNT(*) over 533k commissions triggered a parallel seq
 * scan — ~50ms out of every admin/commissions and admin/dashboard
 * request was spent just counting active rows. A partial index on
 * the soft-delete predicate drops that to <5ms.
 *
 * CONCURRENTLY so prod sees no lock. Runs outside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private array $indexes = [
        // commission — 533k rows, mostly alive
        ['commission_alive_idx', 'commission', '(id) WHERE "deletedAt" IS NULL'],
        // transaction — 50k rows
        ['transaction_alive_idx', '"transaction"', '(id) WHERE "deletedAt" IS NULL'],
        // contract — 17k rows
        ['contract_alive_idx', 'contract', '(id) WHERE "deletedAt" IS NULL'],
        // consultant — 2k rows, but "Партнёры" list is hit hardest
        ['consultant_alive_idx', 'consultant', '(id) WHERE "dateDeleted" IS NULL'],
        // qualificationLog — 36k rows
        ['qualificationlog_alive_idx', '"qualificationLog"', '(id) WHERE "dateDeleted" IS NULL'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$name, $table, $cols]) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} {$cols}");
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$name]) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
