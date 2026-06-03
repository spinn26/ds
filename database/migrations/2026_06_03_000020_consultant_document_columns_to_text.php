<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens consultant.passportScanPage1 / passportScanPage2 / applicationForPayment
 * from `integer` to `text`.
 *
 * DocumentController::upload() stores the document on the private local disk and
 * writes the relative path string (e.g. "documents/1487/xxxx.pdf") back into
 * these columns. While they were typed `integer` every upload crashed with
 * SQLSTATE[22P02] (invalid input syntax for integer). Widening to text is the
 * fix — the columns are meant to hold the storage path string.
 *
 * Safe: integer -> text is a lossless, non-destructive widening. Any pre-existing
 * numeric values are preserved as their string form. Reversible: down() casts
 * back to integer, nulling any value that is not a bare integer (i.e. the new
 * path strings, which could not have existed under the old integer type).
 */
return new class extends Migration
{
    private const COLUMNS = ['passportScanPage1', 'passportScanPage2', 'applicationForPayment'];

    public function up(): void
    {
        foreach (self::COLUMNS as $col) {
            DB::statement(sprintf(
                'ALTER TABLE consultant ALTER COLUMN "%s" TYPE text USING "%s"::text',
                $col,
                $col
            ));
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $col) {
            DB::statement(sprintf(
                'ALTER TABLE consultant ALTER COLUMN "%s" TYPE integer'
                . ' USING (CASE WHEN "%s" ~ \'^[0-9]+$\' THEN "%s"::integer ELSE NULL END)',
                $col,
                $col,
                $col
            ));
        }
    }
};
