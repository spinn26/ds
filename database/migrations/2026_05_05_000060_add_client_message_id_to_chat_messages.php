<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * client_message_id — идемпотентный токен для chat_messages.
 *
 * Фронт генерирует UUID при отправке сообщения и шлёт его в API. Backend
 * хранит, и если та же пара (ticket_id, client_message_id) приходит
 * повторно (retry / двойной click / гонка), backend возвращает уже
 * сохранённый id вместо создания дубля.
 *
 * Также используется на стороне Vue для дедупа socket-emit ↔ HTTP-response:
 * если приходит chat:new-message с тем же clientMessageId, фронт игнорирует.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('client_message_id', 64)->nullable()->after('reply_to_id');
            $table->index(['ticket_id', 'client_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'client_message_id']);
            $table->dropColumn('client_message_id');
        });
    }
};
