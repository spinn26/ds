<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add calculator-driving fields to `program`.
 *
 * Source: `.claude/specs/commission-spec.md` + Google-Sheets reference
 * (ПРОДУКТЫ tab). Each program can now declare:
 *
 *  - fixedCost        — fixed product price (educational products: СПФК 320k, PROРост 9.9k).
 *                       Nullable — insurance/broker programs use the transaction amount instead.
 *  - pointsMethod     — enum-like machine id:
 *                         cost_div_100       points = fixedCost / 100
 *                         amount_div_100     points = amount / 100 (default legacy behaviour)
 *                         amount_times_ds    points = amount * %DS / 100 / 100
 *                         fixed              points = pointsMin (no math, flat)
 *  - pointsFormula    — human-readable formula copy (goes into tooltip).
 *  - pointsMin / Max  — flat or range for the "Баллы" column from the sheet.
 *  - kvPayoutYear     — for regular-premium insurance: year 1/2/3/... the KV is paid.
 *  - dsPercent        — the I-column "%DS" value stored on the program itself
 *                       (mirrors the legacy dsCommission FK but with a direct value
 *                       so a BackOffice staff member can edit without managing
 *                       separate dsCommission rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program', function (Blueprint $table) {
            if (! Schema::hasColumn('program', 'fixedCost')) {
                $table->decimal('fixedCost', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('program', 'pointsMethod')) {
                $table->string('pointsMethod', 32)->nullable();
            }
            if (! Schema::hasColumn('program', 'pointsFormula')) {
                $table->text('pointsFormula')->nullable();
            }
            if (! Schema::hasColumn('program', 'pointsMin')) {
                $table->decimal('pointsMin', 15, 4)->nullable();
            }
            if (! Schema::hasColumn('program', 'pointsMax')) {
                $table->decimal('pointsMax', 15, 4)->nullable();
            }
            if (! Schema::hasColumn('program', 'kvPayoutYear')) {
                $table->smallInteger('kvPayoutYear')->nullable();
            }
            if (! Schema::hasColumn('program', 'dsPercent')) {
                $table->decimal('dsPercent', 6, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('program', function (Blueprint $table) {
            foreach (['fixedCost', 'pointsMethod', 'pointsFormula', 'pointsMin', 'pointsMax', 'kvPayoutYear', 'dsPercent'] as $col) {
                if (Schema::hasColumn('program', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
