<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per spec ✅Доступность отчётов §1: управление видимостью отчётов
 * за конкретный месяц для партнёров (независимо от заморозки).
 *
 * По умолчанию отчёт за месяц скрыт; админ нажимает «Сделать
 * доступным» и партнёры видят свои детализации. Запись присутствует
 * только если значение отличается от дефолта или менялось.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('period_visibility')) {
            Schema::create('period_visibility', function (Blueprint $t) {
                $t->id();
                $t->smallInteger('year');
                $t->smallInteger('month');
                $t->boolean('is_visible')->default(false);
                $t->unsignedBigInteger('changed_by')->nullable();
                $t->timestamp('changed_at')->nullable();
                $t->timestamps();
                $t->unique(['year', 'month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('period_visibility');
    }
};
