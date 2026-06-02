<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the redundant 4-column partial UNIQUE index on commission.
 *
 * Background: migration 2026_05_28_000030 already created
 *   commission_tx_consultant_chain_active_uniq
 *     UNIQUE (transaction, consultant, "chainOrder") WHERE "deletedAt" IS NULL
 * Ten minutes later 2026_05_28_000040 added
 *   commission_unique_per_transaction_idx
 *     UNIQUE (transaction, consultant, "chainOrder", type)
 *     WHERE transaction IS NOT NULL AND "deletedAt" IS NULL
 *
 * The 3-column index is strictly stronger: on the only rows the 4-column
 * index covers (transaction IS NOT NULL, alive), it already enforces
 * uniqueness of (transaction, consultant, "chainOrder"), so adding `type`
 * can never reject anything new. Confirmed against data: among alive rows
 * with transaction NOT NULL, `type` is constant ('transaction'); zero
 * groups have more than one type per (transaction, consultant, chainOrder).
 *
 * The 4-column index is therefore ~7 MB of dead weight on commission
 * (~551k rows) that is maintained on every insert/update for no benefit.
 * No ON CONFLICT clause references it by name or by its column set.
 *
 * Reversible: down() recreates it exactly as 000040 did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS commission_unique_per_transaction_idx');
    }

    public function down(): void
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
};
