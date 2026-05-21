<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-program "formLink" — the external URL used by the partner-facing
 * "Программы продукта" modal (see ProductController::index → product.programs[].formLink).
 * Without this column, the partner /products page 500s with 42703.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('program', 'formLink')) {
            Schema::table('program', function (Blueprint $table) {
                $table->string('formLink')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('program', 'formLink')) {
            Schema::table('program', function (Blueprint $table) {
                $table->dropColumn('formLink');
            });
        }
    }
};
