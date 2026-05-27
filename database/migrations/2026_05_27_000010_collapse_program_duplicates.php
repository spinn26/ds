<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-level normalization: program = unique (productId, name).
 *
 * Before this migration, the legacy "program" table stored one row per
 * (name, term, kvPayoutYear) combination — e.g. 21 rows for "Жизнь+",
 * 23 for "Мой капитал (рубли)", 152 dup rows in total across 48 (product,
 * name) pairs. term / kvPayoutYear / commissionCalcProperty are already
 * redundantly stored in dsCommission per-tariff, so the dup rows on
 * "program" carry no unique information.
 *
 * Strategy:
 *   1. For each duplicated (product, name) pair, pick canonical = MIN(id).
 *   2. Snapshot every FK row that will be rewritten into a backup table
 *      so we can roll back.
 *   3. Rewrite all six FK columns to point to canonical ids.
 *   4. Dedup dsCommission rows that collide after rewrite (same canonical
 *      program + termContract + property + kvPayoutYear + date window +
 *      tariff values) by deactivating extras.
 *   5. Deactivate non-canonical program rows (active=false,
 *      visibleToCalculator=false). NOT deleted physically — preserves the
 *      backup<->original join, and rollback can restore them.
 *
 * Verified on local newds dump 2026-05-27: 17548 contracts intact,
 * 152 non-canonical rows deactivated, dsCommission term/year/property
 * preserved on canonical id.
 */
return new class extends Migration
{
    private const BACKUP_TABLE = 'program_collapse_backup_2026_05_27';

    private const FK_TARGETS = [
        // table => column
        'contract' => 'program',
        'dsCommission' => 'program',
        'volumeCalculator' => 'program',
        'consultantProgramsData' => 'program',
        'getInsmartOrderWebHookData' => 'program',
        'consultant' => 'soldPrograms',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            // 1. Backup table for rollback fidelity.
            Schema::dropIfExists(self::BACKUP_TABLE);
            DB::statement('
                CREATE TABLE ' . self::BACKUP_TABLE . ' (
                    id BIGSERIAL PRIMARY KEY,
                    table_name TEXT NOT NULL,
                    column_name TEXT NOT NULL,
                    row_id INTEGER NOT NULL,
                    old_program INTEGER NOT NULL,
                    new_program INTEGER NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW()
                );
            ');

            // 2. variant_map: every non-canonical variant -> canonical id.
            //    Materialized so subsequent UPDATEs are deterministic and fast.
            DB::statement('
                CREATE TEMP TABLE _variant_map AS
                SELECT p.id AS variant_id, c.canonical_id
                FROM program p
                JOIN (
                    SELECT product, name, MIN(id) AS canonical_id
                    FROM program
                    WHERE active = true
                    GROUP BY product, name
                    HAVING COUNT(*) > 1
                ) c ON c.product = p.product AND c.name = p.name
                WHERE p.id <> c.canonical_id;
            ');
            DB::statement('CREATE INDEX ON _variant_map (variant_id);');

            $variantCount = (int) DB::selectOne('SELECT COUNT(*) AS n FROM _variant_map')->n;
            if ($variantCount === 0) {
                // Nothing to do — already normalized.
                return;
            }

            // 3. Rewrite FKs. Each table: snapshot affected rows, then UPDATE.
            foreach (self::FK_TARGETS as $table => $column) {
                $tableQ = '"' . $table . '"';
                $colQ = '"' . $column . '"';

                DB::statement("
                    INSERT INTO " . self::BACKUP_TABLE . " (table_name, column_name, row_id, old_program, new_program)
                    SELECT '$table', '$column', t.id, t.$colQ, m.canonical_id
                    FROM $tableQ t
                    JOIN _variant_map m ON m.variant_id = t.$colQ;
                ");

                DB::statement("
                    UPDATE $tableQ AS t
                    SET $colQ = m.canonical_id
                    FROM _variant_map m
                    WHERE t.$colQ = m.variant_id;
                ");
            }

            // 4. Dedup dsCommission rows that now collide on canonical id.
            //    Same (program, termContract, property, kvPayoutYear?, date,
            //    dateFinish, comission, commissionAbsolute) — same tariff.
            //    Keep MIN(id), deactivate the rest. Active=false leaves them
            //    auditable but excludes them from calculator/reports.
            $hasKvYear = Schema::hasColumn('dsCommission', 'kvPayoutYear');
            $kvCol = $hasKvYear ? '"kvPayoutYear"' : 'NULL';
            DB::statement("
                WITH groups AS (
                    SELECT id,
                        ROW_NUMBER() OVER (
                            PARTITION BY program, \"termContract\", \"commissionCalcProperty\", $kvCol,
                                         date, \"dateFinish\", comission, \"commissionAbsolute\"
                            ORDER BY id
                        ) AS rn
                    FROM \"dsCommission\"
                    WHERE active = true AND \"dateDeleted\" IS NULL
                )
                UPDATE \"dsCommission\" c
                SET active = false
                FROM groups g
                WHERE c.id = g.id AND g.rn > 1;
            ");

            // 5. Deactivate non-canonical program rows. visibleToCalculator
            //    flip is belt-and-suspenders — calculator already gates on
            //    active=true, but report code may look at visibility alone.
            DB::statement('
                UPDATE program
                SET active = false, "visibleToCalculator" = false
                WHERE id IN (SELECT variant_id FROM _variant_map);
            ');

            // 6. Sanity checks — abort transaction if anything looks off.
            $orphanContracts = (int) DB::selectOne('
                SELECT COUNT(*) AS n FROM contract c
                WHERE c.program IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM program p WHERE p.id = c.program);
            ')->n;
            if ($orphanContracts > 0) {
                throw new \RuntimeException("Migration would orphan {$orphanContracts} contracts — aborting.");
            }

            $deactivated = (int) DB::selectOne('
                SELECT COUNT(*) AS n FROM program p
                JOIN _variant_map m ON m.variant_id = p.id
                WHERE p.active = false;
            ')->n;
            if ($deactivated !== $variantCount) {
                throw new \RuntimeException("Expected to deactivate {$variantCount} variants, deactivated {$deactivated} — aborting.");
            }

            DB::statement('DROP TABLE _variant_map;');
        });

        // Invalidate the calculator matrix cache so the next request rebuilds
        // from the collapsed program list.
        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix');
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::BACKUP_TABLE)) {
            // Migration never ran or backup was dropped — nothing to revert.
            return;
        }

        DB::transaction(function () {
            // 1. Restore FK columns from the snapshot.
            foreach (self::FK_TARGETS as $table => $column) {
                $tableQ = '"' . $table . '"';
                $colQ = '"' . $column . '"';
                DB::statement("
                    UPDATE $tableQ AS t
                    SET $colQ = b.old_program
                    FROM " . self::BACKUP_TABLE . " b
                    WHERE b.table_name = '$table'
                      AND b.column_name = '$column'
                      AND t.id = b.row_id
                      AND t.$colQ = b.new_program;
                ");
            }

            // 2. Re-activate non-canonical programs.
            DB::statement('
                UPDATE program
                SET active = true, "visibleToCalculator" = true
                WHERE id IN (SELECT DISTINCT old_program FROM ' . self::BACKUP_TABLE . ');
            ');

            // NOTE: dsCommission rows we deactivated in step 4 are NOT
            // automatically re-activated. They were redundant duplicates;
            // re-activating them would re-introduce the same conflicts that
            // forced the dedup. Manual review required if you really want
            // them back.

            Schema::dropIfExists(self::BACKUP_TABLE);
        });

        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix');
    }
};
