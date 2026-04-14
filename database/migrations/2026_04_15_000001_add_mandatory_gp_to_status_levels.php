<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add mandatoryGP (ОП по ГП) column — mandatory monthly group volume plan per qualification.
 * Values from the business model specification.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('status_levels', 'mandatoryGP')) {
            Schema::table('status_levels', function (Blueprint $table) {
                $table->decimal('mandatoryGP', 15, 2)->default(0)->after('groupVolume');
            });
        }

        // ОП по ГП values from spec
        $values = [
            1  => 0,       // Start
            2  => 0,       // Pro
            3  => 300,     // Expert
            4  => 3000,    // FC
            5  => 8000,    // Master FC
            6  => 12000,   // TOP FC
            7  => 20000,   // Silver DS
            8  => 45000,   // Gold DS
            9  => 75000,   // Platinum DS
            10 => 100000,  // Co-founder DS
        ];

        foreach ($values as $id => $gp) {
            DB::table('status_levels')->where('id', $id)->update(['mandatoryGP' => $gp]);
        }
    }

    public function down(): void
    {
        Schema::table('status_levels', function (Blueprint $table) {
            if (Schema::hasColumn('status_levels', 'mandatoryGP')) {
                $table->dropColumn('mandatoryGP');
            }
        });
    }
};
