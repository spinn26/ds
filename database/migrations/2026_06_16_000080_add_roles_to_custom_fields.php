<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Привязка кастомного поля к ролям: если roles непустой — поле показывается
 * в профиле только пользователям с одной из этих ролей. Пусто/NULL — всем.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_fields') && ! Schema::hasColumn('custom_fields', 'roles')) {
            Schema::table('custom_fields', function (Blueprint $t) {
                $t->jsonb('roles')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('custom_fields') && Schema::hasColumn('custom_fields', 'roles')) {
            Schema::table('custom_fields', function (Blueprint $t) {
                $t->dropColumn('roles');
            });
        }
    }
};
