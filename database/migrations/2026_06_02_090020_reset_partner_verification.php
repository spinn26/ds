<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time business reset (2026-06-02): every partner must re-enter their
 * requisites and pass MANUAL verification by a finance manager again.
 *
 * Strategy — full reset, but non-destructive to history:
 *   - requisites / bankrequisites: soft-delete (deletedAt = now, verified =
 *     false). The Requisite::active() scope filters deletedAt, so the
 *     blocking dialog in /products will prompt the partner to enter requisites
 *     anew; setupRequisites() then creates fresh rows. Old rows stay for audit.
 *   - consultant.statusRequisites = null → checkAccess() / ProfileController
 *     treat the partner as unverified (verified === (int)status === 3).
 *
 * down() is intentionally a no-op: a bulk data reset cannot be reliably
 * reversed (we cannot tell which rows we soft-deleted vs. pre-existing).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('requisites')) {
            DB::table('requisites')
                ->whereNull('deletedAt')
                ->update(['deletedAt' => $now, 'verified' => false]);
        }

        if (Schema::hasTable('bankrequisites')) {
            DB::table('bankrequisites')
                ->whereNull('deletedAt')
                ->update(['deletedAt' => $now, 'verified' => false]);
        }

        if (Schema::hasTable('consultant') && Schema::hasColumn('consultant', 'statusRequisites')) {
            // Raw update — no Eloquent events / activity log for a bulk reset.
            DB::table('consultant')->update(['statusRequisites' => null]);
        }
    }

    public function down(): void
    {
        // Irreversible one-time data reset — nothing to restore.
    }
};
