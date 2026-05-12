<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Простой Telegram-нотифайер на Bot API.
 *
 * Партнёр / admin вводит свой chat_id в профиле (получив через @userinfobot),
 * мы шлём сообщения через `TELEGRAM_BOT_TOKEN` (env). Никакого
 * полноценного бота-приложения не нужно.
 *
 * Использование:
 *   Telegram::send($userId, "🔴 Критический инцидент: ...");
 *   Telegram::broadcastToRoles(['admin', 'head'], "Тест.");
 */
class Telegram
{
    public static function enabled(): bool
    {
        return ! empty(config('services.telegram.bot_token'));
    }

    public static function send(int $userId, string $text): bool
    {
        $chatId = DB::table('WebUser')->where('id', $userId)->value('telegram_chat_id');
        if (! $chatId || ! self::enabled()) return false;
        return self::raw((string) $chatId, $text);
    }

    /** Отправка по chat_id напрямую (используется при тестовой кнопке). */
    public static function raw(string $chatId, string $text): bool
    {
        $token = config('services.telegram.bot_token');
        if (! $token) return false;
        try {
            $res = Http::timeout(8)
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
            if (! $res->ok()) {
                Log::warning('telegram send failed', ['chat_id' => $chatId, 'status' => $res->status(), 'body' => $res->body()]);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            Log::warning('telegram send error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Рассылка по ролям всем у кого привязан chat_id. */
    public static function broadcastToRoles(array $roles, string $text): int
    {
        if (! self::enabled()) return 0;
        $sent = 0;
        $users = DB::table('WebUser')
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->get(['id', 'telegram_chat_id', 'role']);
        foreach ($users as $u) {
            $userRoles = array_map('trim', explode(',', $u->role ?? ''));
            if (! array_intersect($roles, $userRoles)) continue;
            if (self::raw((string) $u->telegram_chat_id, $text)) $sent++;
        }
        return $sent;
    }
}
