<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the denormalized consultant.groupVolumeCumulative (НГП) from the
 * latest non-null qualificationLog snapshot.
 *
 * Why: the denorm field is never maintained — it stays at the initial seed
 * (e.g. 350000) while the real cumulative lives in qualificationLog. Several
 * read paths (admin Top-10, chat partner panel) read the denorm field
 * directly and therefore show the stale value. This command realigns the
 * denorm field with the qualificationLog source of truth.
 *
 * We deliberately pick the latest row WITH a non-null cumulative: the monthly
 * finalize (MonthlyPenaltyRunner) historically inserted end-of-month penalty
 * rows with NULL cumulative, which must not overwrite the real value.
 *
 * RAISE-ONLY: НГП is a monotonically non-decreasing cumulative, so we only
 * raise the denorm field when qualificationLog is higher (= max(denorm, qLog)).
 * We never lower it — for some consultants the qualificationLog series is
 * sparse and its latest non-null cumulative is 0/low while the denorm field
 * holds the truer (higher) value; lowering would destroy real НГП.
 *
 * Safe by default: dry-run unless --apply is given. Before applying, the old
 * values of every changed row are dumped to storage/app for rollback.
 */
class BackfillNgpCumulative extends Command
{
    protected $signature = 'partners:backfill-ngp-cumulative {--apply : Persist the changes (otherwise dry-run preview only)}';

    protected $description = 'Realign consultant.groupVolumeCumulative (НГП) with the latest non-null qualificationLog snapshot';

    public function handle(): int
    {
        // Rows that would change: denorm value differs from the latest
        // non-null qualificationLog cumulative.
        $diffSql = <<<'SQL'
            SELECT c.id,
                   c."groupVolumeCumulative" AS old_value,
                   sub.gvc                   AS new_value
            FROM consultant c
            JOIN (
                SELECT DISTINCT ON (consultant)
                       consultant,
                       "groupVolumeCumulative" AS gvc
                FROM "qualificationLog"
                WHERE "dateDeleted" IS NULL
                  AND "groupVolumeCumulative" IS NOT NULL
                ORDER BY consultant, date DESC, id DESC
            ) sub ON sub.consultant = c.id
            WHERE sub.gvc > COALESCE(c."groupVolumeCumulative", 0)
            ORDER BY c.id
        SQL;

        $changes = DB::select($diffSql);
        $count = count($changes);

        $this->info("Consultants whose НГП (groupVolumeCumulative) differs from qualificationLog: {$count}");

        if ($count === 0) {
            $this->info('Nothing to backfill — denorm field already aligned.');
            return self::SUCCESS;
        }

        // Preview a handful so the operator can sanity-check magnitudes.
        $this->table(
            ['consultant', 'old (denorm)', 'new (qLog)'],
            collect($changes)->take(15)->map(fn ($r) => [
                $r->id,
                rtrim(rtrim(number_format((float) $r->old_value, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format((float) $r->new_value, 2, '.', ''), '0'), '.'),
            ])->all()
        );
        if ($count > 15) {
            $this->line('… and '.($count - 15).' more');
        }

        if (! $this->option('apply')) {
            $this->warn('DRY-RUN — no changes written. Re-run with --apply to persist.');
            return self::SUCCESS;
        }

        // Rollback dump: id -> old value, so the change is fully reversible.
        $stamp = now()->format('Ymd_His');
        $rollbackPath = storage_path("app/ngp_backfill_rollback_{$stamp}.csv");
        $fh = fopen($rollbackPath, 'w');
        fputcsv($fh, ['consultant_id', 'old_groupVolumeCumulative', 'new_groupVolumeCumulative']);
        foreach ($changes as $r) {
            fputcsv($fh, [$r->id, $r->old_value, $r->new_value]);
        }
        fclose($fh);
        $this->info("Rollback snapshot written: {$rollbackPath}");

        // Single set-based UPDATE inside a transaction.
        $affected = DB::transaction(function () {
            return DB::update(<<<'SQL'
                UPDATE consultant c
                SET "groupVolumeCumulative" = sub.gvc
                FROM (
                    SELECT DISTINCT ON (consultant)
                           consultant,
                           "groupVolumeCumulative" AS gvc
                    FROM "qualificationLog"
                    WHERE "dateDeleted" IS NULL
                      AND "groupVolumeCumulative" IS NOT NULL
                    ORDER BY consultant, date DESC, id DESC
                ) sub
                WHERE c.id = sub.consultant
                  AND sub.gvc > COALESCE(c."groupVolumeCumulative", 0)
            SQL);
        });

        $this->info("Done. Updated {$affected} consultant rows.");
        return self::SUCCESS;
    }
}
