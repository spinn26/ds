<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расширение модуля задач (Bitrix-паритет):
 *  - tasks.tags        — теги задачи (jsonb-массив строк);
 *  - task_accomplices  — соисполнители (могут вести задачу наравне с исполнителем);
 *  - task_favorites    — избранные задачи (по пользователю).
 * Всё аддитивно и обратимо.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->jsonb('tags')->nullable();
        });

        Schema::create('task_accomplices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('task_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_favorites');
        Schema::dropIfExists('task_accomplices');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
