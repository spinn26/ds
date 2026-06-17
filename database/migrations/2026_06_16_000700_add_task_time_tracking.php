<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Учёт времени по задачам (Bitrix-таймер):
 *  - tasks.time_spent  — накопленные секунды;
 *  - task_timers       — активный таймер пользователя по задаче (started_at).
 * Аддитивно/обратимо.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('time_spent')->default(0);
        });

        Schema::create('task_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('started_at');
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_timers');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('time_spent');
        });
    }
};
