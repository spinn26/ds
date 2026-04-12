<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет поле terminationCount в таблицу consultant.
 * По статусной схеме партнёр может быть терминирован до 3 раз,
 * после чего переходит в статус «Исключен».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consultant', 'terminationCount')) {
            Schema::table('consultant', function (Blueprint $table) {
                $table->smallInteger('terminationCount')->default(0)->after('activity');
            });
        }

        if (! Schema::hasColumn('consultant', 'activationDeadline')) {
            Schema::table('consultant', function (Blueprint $table) {
                $table->timestamp('activationDeadline')->nullable()->after('terminationCount');
            });
        }

        if (! Schema::hasColumn('consultant', 'yearPeriodEnd')) {
            Schema::table('consultant', function (Blueprint $table) {
                $table->timestamp('yearPeriodEnd')->nullable()->after('activationDeadline');
            });
        }
    }

    public function down(): void
    {
        Schema::table('consultant', function (Blueprint $table) {
            if (Schema::hasColumn('consultant', 'terminationCount')) {
                $table->dropColumn('terminationCount');
            }
            if (Schema::hasColumn('consultant', 'activationDeadline')) {
                $table->dropColumn('activationDeadline');
            }
            if (Schema::hasColumn('consultant', 'yearPeriodEnd')) {
                $table->dropColumn('yearPeriodEnd');
            }
        });
    }
};
