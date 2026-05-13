<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Личные задачи + личная заметка на рабочем столе.
 *  - user_tasks: чек-лист дел, привязан к WebUser.id; due_date / priority
 *    опциональны; sort_order для drag-and-drop в будущем.
 *  - user_notes: одна большая заметка (scratchpad) на юзера, single-row
 *    upsert при save. PK = user_id, без auto-id — каждому юзеру одна.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->index();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->boolean('is_done')->default(false);
            $table->date('due_date')->nullable();
            $table->string('priority', 10)->nullable(); // low/medium/high
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('user_notes', function (Blueprint $table) {
            $table->integer('user_id')->primary();
            $table->text('content')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tasks');
        Schema::dropIfExists('user_notes');
    }
};
