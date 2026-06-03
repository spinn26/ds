<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable `rejection_reason` to requisites.
 *
 * Populated when verification is denied — either the finance manager's comment
 * (manual reject), or an auto-derived reason (ИП оформлено не на своё имя / ФИО
 * mismatch, or налоговый режим не УСН). Presence of this text + verified=false
 * is what the partner-facing «отказано в верификации» banner keys off. Cleared
 * when the partner re-submits requisites or when verification succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('requisites', 'rejection_reason')) {
            Schema::table('requisites', function (Blueprint $table) {
                $table->text('rejection_reason')->nullable()->after('tax_regime');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('requisites', 'rejection_reason')) {
            Schema::table('requisites', function (Blueprint $table) {
                $table->dropColumn('rejection_reason');
            });
        }
    }
};
