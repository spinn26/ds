<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable `tax_regime` column to requisites.
 *
 * Populated from DaData (party finance.tax_system) when the partner enters
 * their ИНН — stored as a human label (УСН / ОСН / ПСН / ЕСХН / …). Display
 * only; the field is informational and does not affect verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('requisites', 'tax_regime')) {
            Schema::table('requisites', function (Blueprint $table) {
                $table->string('tax_regime')->nullable()->after('uproshenka');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('requisites', 'tax_regime')) {
            Schema::table('requisites', function (Blueprint $table) {
                $table->dropColumn('tax_regime');
            });
        }
    }
};
