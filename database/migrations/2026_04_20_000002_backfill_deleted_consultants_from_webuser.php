<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time cleanup: before AdminUserController::destroy cascaded into
 * `consultant`, deleting a WebUser left the matching consultant row
 * with dateDeleted NULL. Both "Партнёры" and "Статусы партнёров"
 * screens filter on consultant.dateDeleted, so those orphans kept
 * showing up even though the underlying user was gone.
 *
 * This migration closes the gap for rows that were orphaned before the
 * controller fix shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->whereIn('webUser', function ($q) {
                $q->select('id')
                    ->from('WebUser')
                    ->whereNotNull('dateDeleted');
            })
            ->update(['dateDeleted' => now()]);

        Log::info("backfill: soft-deleted {$affected} orphan consultant rows whose WebUser was already deleted");
    }

    public function down(): void
    {
        // Not reversible: this migration loses the distinction between
        // "deleted as part of this cleanup" and "deleted normally later".
        // Rolling it back would un-delete every consultant whose WebUser
        // is marked deleted, which is strictly wrong.
        Log::warning('backfill_deleted_consultants_from_webuser: down() is a no-op by design');
    }
};
