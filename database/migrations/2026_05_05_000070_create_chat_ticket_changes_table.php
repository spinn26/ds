<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log изменений тикетов чата.
 *
 * Сейчас при смене статуса в `chat_messages` пишется `is_system`-запись —
 * её хватает для UI таймлайна, но не для compliance-разборок: нет
 * структурированного from→to, нет фильтрации по полям, нельзя строить
 * SLA-отчёты.
 *
 * Эта таблица — append-only, по строке на каждое изменение поля
 * (`status`, `priority`, `assigned_to`, `tags`, `pinned_at`, `closed_at`).
 * Ничего не удаляется, миграция down — drop таблицы целиком.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ticket_changes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id');
            $table->string('field', 32);          // status / priority / assigned_to / tags / pinned_at / closed_at
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->bigInteger('changed_by')->nullable(); // WebUser.id; NULL для системных изменений
            $table->string('changed_by_name')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['ticket_id', 'changed_at']);
            $table->index('field');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ticket_changes');
    }
};
