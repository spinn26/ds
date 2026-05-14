<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дополнительные участники чата.
 * Один тикет может иметь сотрудников-наблюдателей сверх стандартных
 * created_by / recipient_id / assigned_to. Используется когда менеджер
 * подключает коллегу для совместного решения вопроса.
 *
 * Один user — одна строка на тикет; уникальный индекс (ticket_id, user_id)
 * защищает от дублей при гонке двух «добавить» одновременно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ticket_participants', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('ticket_id');
            $t->integer('user_id');
            $t->string('user_name', 200)->nullable();
            $t->integer('added_by')->nullable();
            $t->timestamp('added_at')->useCurrent();
            $t->unique(['ticket_id', 'user_id']);
            $t->index('user_id');
            $t->foreign('ticket_id')->references('id')->on('chat_tickets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ticket_participants');
    }
};
