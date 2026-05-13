<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Одноразовые токены для привязки Telegram-аккаунта через бота.
 *
 * Flow:
 *  1. User жмёт «Привязать Telegram» → бек создаёт запись с UUID-токеном.
 *  2. User открывает deeplink t.me/<bot>?start=<token> → жмёт Start.
 *  3. Webhook /webhooks/telegram получает /start <token> → проверяет
 *     что токен валидный/не использованный/не просрочен → пишет
 *     chat_id в WebUser, помечает токен used_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_link_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('token', 64)->unique();
            $t->unsignedBigInteger('user_id');
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->string('used_chat_id', 64)->nullable();
            $t->timestamps();
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_tokens');
    }
};
