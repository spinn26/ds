<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметка «предупреждение о скорой терминации отправлено».
 *
 * Хранит НЕ дату отправки, а дедлайн, ПОД КОТОРЫЙ предупреждение ушло. Так
 * команда сама перевзводится: сдвинули партнёру срок активации (продлили,
 * восстановился после терминации) — дедлайн стал другим, и человек получит
 * новое предупреждение. Просто timestamp «когда слали» такого не умеет.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consultant', 'termination_warning_for')) {
            Schema::table('consultant', function (Blueprint $table) {
                $table->date('termination_warning_for')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('consultant', 'termination_warning_for')) {
            Schema::table('consultant', function (Blueprint $table) {
                $table->dropColumn('termination_warning_for');
            });
        }
    }
};
