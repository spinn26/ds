<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Модуль «Задачи и Проекты» (Bitrix-style).
 *
 * projects        — проекты (доски). Каждый проект = канбан-доска.
 * project_members — участники проекта (WebUser.id).
 * task_stages     — колонки канбана внутри проекта (цвет, порядок, флаг «готово»).
 * tasks           — задачи: постановщик/исполнитель/срок/приоритет/статус/стадия,
 *                   parent_id для подзадач, sort_order — позиция внутри стадии.
 * task_watchers   — наблюдатели задачи.
 * task_comments   — комментарии (лента задачи).
 *
 * Ссылки на пользователей хранятся как WebUser.id (unsignedBigInteger + index),
 * без жёсткого FK — так же, как audit_log и прочие служебные таблицы (WebUser —
 * legacy-таблица с собственным id-пространством). FK выставлены только между
 * новыми таблицами модуля (cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 9)->default('#2E7D32');
            $table->unsignedBigInteger('created_by')->index();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('task_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 9)->default('#90A4AE');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_done')->default(false);
            $table->timestamps();
            $table->index(['project_id', 'sort_order']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('task_stages')->nullOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->index();
            $table->unsignedBigInteger('assignee_id')->nullable()->index();
            $table->string('priority', 16)->default('normal');   // low | normal | high
            $table->string('status', 16)->default('pending');    // pending | in_progress | done | deferred | rejected
            $table->timestamp('deadline')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['project_id', 'stage_id', 'sort_order']);
        });

        Schema::create('task_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_stages');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
