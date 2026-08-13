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

    public static function send(int $userId, string $text, string $parseMode = 'HTML'): bool
    {
        $chatId = DB::table('WebUser')->where('id', $userId)->value('telegram_chat_id');
        if (! $chatId || ! self::enabled()) return false;
        return self::raw((string) $chatId, $text, null, $parseMode);
    }

    /**
     * Экранирование текста под MarkdownV2 — Telegram требует escape для
     * ВСЕХ спецсимволов, иначе sendMessage отвечает 400 на любой минус
     * или точку в тексте.
     */
    public static function escapeMarkdown(string $text): string
    {
        // Обратный слеш — строго первым: str_replace идёт по массиву слева
        // направо, и если экранировать его последним, он задвоит слеши,
        // добавленные предыдущими заменами.
        $special = ['\\', '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];

        return str_replace($special, array_map(fn ($c) => '\\'.$c, $special), $text);
    }

    /**
     * Отправка по chat_id напрямую. Опционально reply_markup
     * (keyboard / inline_keyboard / remove_keyboard) — Bot API формат.
     *
     * $parseMode: 'HTML' (исторический дефолт — так размечено большинство
     * наших уведомлений), 'MarkdownV2' или '' для сырого текста.
     */
    public static function raw(string $chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML'): bool
    {
        $token = config('services.telegram.bot_token');
        if (! $token) return false;
        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ];
            if ($parseMode !== '') $payload['parse_mode'] = $parseMode;
            if ($replyMarkup !== null) $payload['reply_markup'] = $replyMarkup;
            $res = Http::timeout(8)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
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

    /** Главная reply-клавиатура для бота. */
    public static function mainKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => '📊 Мой статус'], ['text' => 'ℹ️ Справка']],
                [['text' => '❌ Отвязать аккаунт']],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /** Рассылка всем пользователям с привязанным chat_id (без фильтра по ролям). */
    public static function broadcastAll(string $text, string $parseMode = 'HTML'): int
    {
        if (! self::enabled()) return 0;
        $sent = 0;
        DB::table('WebUser')
            ->whereNull('dateDeleted')
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($users) use ($text, $parseMode, &$sent) {
                foreach ($users as $u) {
                    if (self::raw((string) $u->telegram_chat_id, $text, null, $parseMode)) $sent++;
                }
            });
        return $sent;
    }

    /**
     * Персонализированная рассылка всем с привязанным chat_id.
     * $builder получает строку WebUser (id, firstName, patronymic, ...) и
     * возвращает готовый текст сообщения — или null/'' чтобы пропустить.
     */
    public static function broadcastAllPersonalized(callable $builder): int
    {
        if (! self::enabled()) return 0;
        $sent = 0;
        DB::table('WebUser')
            ->whereNull('dateDeleted')
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($users) use ($builder, &$sent) {
                foreach ($users as $u) {
                    $text = $builder($u);
                    if ($text === null || $text === '') continue;
                    if (self::raw((string) $u->telegram_chat_id, $text)) $sent++;
                }
            });
        return $sent;
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

    /**
     * Зарегистрировать webhook у Telegram. Вызывается артизан-командой
     * или вручную после смены домена/секрета.
     */
    public static function setWebhook(string $url, ?string $secret = null): array
    {
        $token = config('services.telegram.bot_token');
        if (! $token) return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN not set'];
        try {
            $res = Http::timeout(8)->post("https://api.telegram.org/bot{$token}/setWebhook", [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['message'],
                'drop_pending_updates' => true,
            ]);
            return ['ok' => $res->ok(), 'status' => $res->status(), 'body' => $res->json()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Получить инфо о текущем webhook (для health-check). */
    public static function getWebhookInfo(): array
    {
        $token = config('services.telegram.bot_token');
        if (! $token) return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN not set'];
        try {
            $res = Http::timeout(5)->get("https://api.telegram.org/bot{$token}/getWebhookInfo");
            return ['ok' => $res->ok(), 'body' => $res->json()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
