<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business decision (2026-06-02): align the activation date of every CURRENT
 * active partner to 2026-06-01, and shift the matching one-year period end to
 * 2027-06-01 (yearPeriodEnd = activation + 1y is what the nightly status cron
 * checks for ЛП>=500 renew/terminate, and what getStatusInfo() displays).
 *
 * Scope: consultant.activity = 1 (Активен) only. Registered/Terminated/Excluded
 * are left untouched.
 *
 * Reversibility: a bulk overwrite is not self-reversing, so up() first snapshots
 * the original (id, dateActivity, yearPeriodEnd) into a backup table; down()
 * restores from it and drops the table.
 */
return new class extends Migration
{
    private const BACKUP = 'consultant_activation_backfill_20260602';
    private const ACTIVITY_ACTIVE = 1;
    private const ACTIVATION_DATE = '2026-06-01 00:00:00';
    private const YEAR_PERIOD_END = '2027-06-01 00:00:00';

    public function up(): void
    {
        if (! Schema::hasTable('consultant')) {
            return;
        }

        // Snapshot current values so down() can restore them exactly.
        Schema::dropIfExists(self::BACKUP);
        Schema::create(self::BACKUP, function ($table) {
            $table->integer('id')->primary();
            $table->timestamp('dateActivity')->nullable();
            $table->timestamp('yearPeriodEnd')->nullable();
        });

        DB::statement(
            'INSERT INTO ' . self::BACKUP . ' (id, "dateActivity", "yearPeriodEnd") '
            . 'SELECT id, "dateActivity", "yearPeriodEnd" FROM consultant WHERE activity = ?',
            [self::ACTIVITY_ACTIVE]
        );

        $affected = DB::table('consultant')
            ->where('activity', self::ACTIVITY_ACTIVE)
            ->update([
                'dateActivity' => self::ACTIVATION_DATE,
                'yearPeriodEnd' => self::YEAR_PERIOD_END,
            ]);

        if (app()->runningInConsole()) {
            echo '  set activation date for active partners: ' . $affected . " rows\n";
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::BACKUP)) {
            return;
        }

        // Restore each consultant's original dates from the snapshot.
        DB::statement(
            'UPDATE consultant c SET "dateActivity" = b."dateActivity", '
            . '"yearPeriodEnd" = b."yearPeriodEnd" '
            . 'FROM ' . self::BACKUP . ' b WHERE c.id = b.id'
        );

        Schema::dropIfExists(self::BACKUP);
    }
};
