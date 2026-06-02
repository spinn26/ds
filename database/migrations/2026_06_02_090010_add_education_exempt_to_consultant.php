<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grandfather the course-gate on products for every CURRENT consultant.
 *
 * Product unlocking (ProductController::index) gates catalog products behind
 * completion of the education_courses linked to their legacy product. We want
 * that gate to apply ONLY to partners who register after this change — all
 * existing partners keep their products open without passing courses.
 *
 * Mechanism: consultant.education_exempt (boolean, default false).
 *   - true  → skip the linked-course check, products open by activity alone.
 *   - false → must complete all linked courses (the new default for new rows).
 *
 * up() sets the flag true for every existing consultant; new consultants
 * created later inherit the column default (false) and are therefore gated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consultant')) {
            return;
        }

        if (! Schema::hasColumn('consultant', 'education_exempt')) {
            Schema::table('consultant', function ($table) {
                $table->boolean('education_exempt')->default(false);
            });
        }

        // Raw update — bypasses Eloquent events / activity log on purpose
        // (we don't want ~2k audit rows for a bulk grandfather flag).
        DB::table('consultant')->update(['education_exempt' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('consultant', 'education_exempt')) {
            Schema::table('consultant', function ($table) {
                $table->dropColumn('education_exempt');
            });
        }
    }
};
