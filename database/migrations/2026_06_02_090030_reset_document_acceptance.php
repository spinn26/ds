<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time business reset (2026-06-02): every partner must accept the legal
 * documents again from scratch. Clean slate — old acceptance history is
 * removed so the admin "Акцепт документов" ledger and the profile show no
 * acceptances until the partner re-signs.
 *
 * Order matters (FKs):
 *   1. consultant.agreementlink → partnerAcceptance(id): detach first.
 *   2. partnerAcceptance.logAccepted → logAcceptance(id): delete child first.
 *   3. delete logAcceptance.
 *   consultant.acceptance is the legacy "documents accepted" flag → false.
 *
 * down() is a no-op: deleted acceptance audit cannot be reconstructed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('consultant')) {
            $update = ['acceptance' => false];
            if (Schema::hasColumn('consultant', 'agreementlink')) {
                $update['agreementlink'] = null; // detach FK before deleting parent rows
            }
            DB::table('consultant')->update($update);
        }

        if (Schema::hasTable('partnerAcceptance')) {
            DB::table('partnerAcceptance')->delete();
        }

        if (Schema::hasTable('logAcceptance')) {
            DB::table('logAcceptance')->delete();
        }
    }

    public function down(): void
    {
        // Irreversible one-time data reset — nothing to restore.
    }
};
