<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История попыток тестов в LMS.
 *
 * Раньше сохранялся только финальный 100 %-результат в
 * `education_course_completions`. Куратору нужны все попытки —
 * сколько раз партнёр пробовал, сколько баллов набрал, какие
 * вопросы ответил. На каждую попытку — одна строка.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_test_attempts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->bigInteger('course_id')->index();
            $table->smallInteger('score');
            $table->smallInteger('total');
            $table->boolean('passed')->default(false);
            $table->timestamp('attempted_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
            $table->index('passed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_test_attempts');
    }
};
