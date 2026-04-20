<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закрытие отчётных месяцев. Когда строка есть — месяц «серый»:
 * транзакции / комиссии / qualificationLog за этот месяц нельзя
 * редактировать или удалять (spec: ./.claude/specs/✅Комиссии .md
 * Part 2 §1). Исправления после закрытия — только через «Прочие
 * начисления» (other_accruals).
 *
 * Ключ (year, month) уникален; reopened_at позволяет «раззаморозить»
 * месяц админом, не теряя историю закрытия.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_closures', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year');
            $table->tinyInteger('month'); // 1..12
            $table->timestamp('closed_at')->useCurrent();
            $table->integer('closed_by')->nullable(); // WebUser.id
            $table->timestamp('reopened_at')->nullable();
            $table->integer('reopened_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->index(['year', 'month', 'reopened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_closures');
    }
};
