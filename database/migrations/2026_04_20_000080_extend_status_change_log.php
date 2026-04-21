<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расширяем legacy chageConsultanStatusLog недостающими полями для аудита
 * (spec ✅Статусы партнеров.md §3): from/to статусы, комментарий, источник.
 *
 * Параллельно смены статуса пишутся в activity_log через Spatie, но старая
 * таблица остаётся как единый источник для legacy-отчётов.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chageConsultanStatusLog')) return;

        Schema::table('chageConsultanStatusLog', function (Blueprint $t) {
            if (! Schema::hasColumn('chageConsultanStatusLog', 'from_status'))  $t->string('from_status', 40)->nullable();
            if (! Schema::hasColumn('chageConsultanStatusLog', 'to_status'))    $t->string('to_status', 40)->nullable();
            if (! Schema::hasColumn('chageConsultanStatusLog', 'comment'))      $t->text('comment')->nullable();
            if (! Schema::hasColumn('chageConsultanStatusLog', 'source'))       $t->string('source', 40)->nullable();  // auto | manual | system
            if (! Schema::hasColumn('chageConsultanStatusLog', 'changed_by'))   $t->unsignedBigInteger('changed_by')->nullable(); // WebUser.id инициатор
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('chageConsultanStatusLog')) return;
        Schema::table('chageConsultanStatusLog', function (Blueprint $t) {
            foreach (['from_status', 'to_status', 'comment', 'source', 'changed_by'] as $col) {
                if (Schema::hasColumn('chageConsultanStatusLog', $col)) $t->dropColumn($col);
            }
        });
    }
};
