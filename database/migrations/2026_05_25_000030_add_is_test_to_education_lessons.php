<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('education_lessons', 'is_test')) {
            Schema::table('education_lessons', function (Blueprint $t) {
                $t->boolean('is_test')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('education_lessons', 'is_test')) {
            Schema::table('education_lessons', function (Blueprint $t) {
                $t->dropColumn('is_test');
            });
        }
    }
};
