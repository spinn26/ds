<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per spec ✅Профиль §1: для сотрудников показывается поле «Должность».
 * Добавляем nullable string-колонку position в WebUser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('WebUser', function (Blueprint $t) {
            if (! Schema::hasColumn('WebUser', 'position')) {
                $t->string('position', 200)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('WebUser', function (Blueprint $t) {
            if (Schema::hasColumn('WebUser', 'position')) {
                $t->dropColumn('position');
            }
        });
    }
};
