<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiSettingsService;
use App\Services\IntegrationLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook-приёмник от Zammad.
 *
 * В Zammad настраивается через Settings → System → Trigger / Webhook
 * с URL `https://<host>/api/v1/webhooks/zammad`.
 *
 * Подпись: Zammad шлёт `X-Hub-Signature: sha1=<HMAC-SHA1>` от raw body
 * с использованием webhook secret. Проверяем через timing-safe
 * `hash_equals`.
 *
 * Сейчас обработчик только пишет событие в integration_events — этого
 * достаточно, чтобы видеть факт прихода и payload в UI «Журнал событий».
 * Реальная sync-логика (создать локальный тикет, уведомить партнёра, и
 * т.п.) пишется отдельно — когда продумана модель связки локальных
 * `chat_tickets` и Zammad-тикетов.
 */
class ZammadWebhookController extends Controller
{
    public function handle(
        Request $request,
        IntegrationLogger $logger,
        ApiSettingsService $settings,
    ): JsonResponse {
        $payload = $request->all();
        $ticketId = (string) data_get($payload, 'ticket.id', '');

        $event = $logger->begin(
            'zammad', 'inbound', 'webhook',
            $request->ip(), null, $ticketId ?: null, $payload,
        );

        $secret = $settings->get('zammad.webhook_secret');
        if ($secret) {
            $signature = (string) $request->header('X-Hub-Signature', '');
            $expected = 'sha1=' . hash_hmac('sha1', $request->getContent(), (string) $secret);
            if (! hash_equals($expected, $signature)) {
                Log::warning('Zammad webhook: bad signature', ['ip' => $request->ip()]);
                $logger->finish($event, 'error', 'Unauthorized: bad HMAC signature');
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        $title = (string) data_get($payload, 'ticket.title', '—');
        $logger->finish($event, 'success',
            "Zammad event: ticket #{$ticketId} «{$title}»", null, $ticketId ?: null);

        return response()->json(['ok' => true]);
    }
}
