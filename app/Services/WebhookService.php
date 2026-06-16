<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Доставка исходящих вебхуков. Best-effort: ошибки логируются, никогда не
 * пробрасываются в вызывающий код (не ломаем бизнес-операцию). POST с HMAC-
 * подписью тела в заголовке X-DS-Signature.
 */
class WebhookService
{
    /** Каталог поддерживаемых событий (для UI + валидации). */
    public const EVENTS = [
        'announcement.created' => 'Создано объявление',
        'webhook.test' => 'Тестовое событие',
    ];

    /** Разослать событие всем активным подписанным вебхукам. */
    public static function dispatch(string $event, array $payload): void
    {
        if (! Schema::hasTable('webhooks')) {
            return;
        }

        $hooks = Webhook::query()->where('active', true)->get()
            ->filter(function ($h) use ($event) {
                $events = $h->events ?? [];
                return empty($events) || in_array($event, $events, true);
            });

        foreach ($hooks as $hook) {
            self::send($hook, $event, $payload);
        }
    }

    public static function send(Webhook $hook, string $event, array $payload): WebhookDelivery
    {
        $body = ['event' => $event, 'data' => $payload, 'sentAt' => now()->toIso8601String()];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'X-DS-Event' => $event];
        if ($hook->secret) {
            $headers['X-DS-Signature'] = hash_hmac('sha256', $json, $hook->secret);
        }

        $status = null;
        $success = false;
        $response = null;
        try {
            $res = Http::timeout(5)->withHeaders($headers)->withBody($json, 'application/json')->post($hook->url);
            $status = $res->status();
            $success = $res->successful();
            $response = mb_substr((string) $res->body(), 0, 1000);
        } catch (\Throwable $e) {
            $response = mb_substr($e->getMessage(), 0, 1000);
            Log::warning('webhook delivery failed', ['hook' => $hook->id, 'error' => $e->getMessage()]);
        }

        return WebhookDelivery::create([
            'webhook_id' => $hook->id, 'event' => $event,
            'status_code' => $status, 'success' => $success, 'response' => $response,
            'created_at' => now(),
        ]);
    }
}
