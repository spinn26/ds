<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Минимальный Telegram-клиент. Читает token и chat_id из ApiSettingsService,
 * отправляет сообщения через Bot API.
 *
 * Best-effort: любые сетевые/HTTP-ошибки ловятся и логируются, но наружу
 * не бросаются — уведомления не должны ронять бизнес-операции.
 */
class TelegramNotifier
{
    public function __construct(
        private readonly ApiSettingsService $settings,
    ) {}

    /**
     * Отправить сообщение в заданный чат (или в дефолтный status-чат).
     * Возвращает true при HTTP 200, false иначе.
     */
    public function send(string $text, ?string $chatId = null, string $parseMode = 'HTML'): bool
    {
        $logger = app(IntegrationLogger::class);
        $token = $this->settings->get('telegram.bot.token');
        if (! $token) {
            Log::debug('telegram: bot token not configured, skipping', ['text' => mb_substr($text, 0, 60)]);
            return false;
        }

        $chatId = $chatId ?: $this->settings->get('telegram.status.chat_id');
        if (! $chatId) {
            Log::debug('telegram: chat_id not configured, skipping');
            return false;
        }

        $event = $logger->begin('telegram', 'outbound', 'send_message',
            null, null, (string) $chatId, ['text' => mb_substr($text, 0, 200)]);

        try {
            $response = Http::timeout(8)->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => $parseMode,
                    'disable_web_page_preview' => true,
                ],
            );

            if (! $response->ok()) {
                Log::warning('telegram: send failed', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 200),
                ]);
                $logger->finish($event, 'error',
                    "HTTP {$response->status()}: " . mb_substr((string) $response->body(), 0, 200));
                return false;
            }
            $logger->finish($event, 'success', 'Сообщение доставлено');
            return true;
        } catch (\Throwable $e) {
            Log::warning('telegram: network error', ['error' => $e->getMessage()]);
            $logger->finish($event, 'error', $e->getMessage());
            return false;
        }
    }

    /** Публичный helper — «бот настроен и готов отправлять». */
    public function isConfigured(): bool
    {
        return $this->settings->get('telegram.bot.token') !== null
            && $this->settings->get('telegram.status.chat_id') !== null;
    }
}
