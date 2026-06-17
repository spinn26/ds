<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Шаблоны задач + повторяющиеся задачи (Bitrix).
 * Шаблон хранит заготовку полей задачи и опциональное расписание повтора;
 * cron создаёт задачи по next_run_at. Аддитивно/обратимо.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->jsonb('tags')->nullable();
            $table->boolean('requires_result')->default(false);
            $table->jsonb('checklist')->nullable();          // массив строк подзадач
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            // Повтор: none | daily | weekly | monthly
            $table->string('recurrence_freq', 16)->default('none');
            $table->integer('recurrence_interval')->default(1);
            $table->integer('recurrence_weekday')->nullable();   // 1=Пн .. 7=Вс
            $table->integer('recurrence_monthday')->nullable();  // 1..31
            $table->string('recurrence_time', 5)->nullable();    // 'HH:MM'
            $table->boolean('active')->default(true);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
