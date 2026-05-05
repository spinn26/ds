<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiSettingsService;
use App\Services\InsmartIntegrationService;
use App\Services\IntegrationLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook-приёмник для Insmart (per spec ✅Инсмарт.md).
 *
 * Endpoint: POST /api/v1/webhooks/insmart/paid
 *
 * Авторизация:
 *   - X-Insmart-Signature: HMAC-SHA256 от raw тела с использованием
 *     `insmart.webhook_secret` (предпочтительно).
 *   - Fallback: X-Insmart-Secret — простой shared-secret для совместимости
 *     со старой схемой. Включается только если HMAC-заголовок не пришёл.
 *
 * Каждое событие пишется в integration_events: успешный — 'success',
 * неудачный auth — 'error' (для security-аудита), бизнес-ошибка обработки
 * — 'error' с текстом исключения.
 */
class InsmartWebhookController extends Controller
{
    public function paid(
        Request $request,
        InsmartIntegrationService $service,
        IntegrationLogger $logger,
        ApiSettingsService $settings,
    ): JsonResponse {
        $payload = $request->all();
        $externalId = (string) ($payload['externalOrderId'] ?? '');

        $event = $logger->begin(
            'insmart', 'inbound', 'paid_webhook',
            $request->ip(), null, $externalId ?: null, $payload,
        );

        $expected = $settings->get('insmart.webhook_secret') ?: config('services.insmart.webhook_secret');
        if ($expected) {
            $signature = (string) $request->header('X-Insmart-Signature', '');
            $sharedSecret = (string) $request->header('X-Insmart-Secret', '');

            $authOk = false;
            if ($signature !== '') {
                // HMAC SHA256 от raw body. Используем hash_equals для timing-attack safe сравнения.
                $expectedSig = hash_hmac('sha256', $request->getContent(), (string) $expected);
                $authOk = hash_equals($expectedSig, $signature);
            } elseif ($sharedSecret !== '') {
                $authOk = hash_equals((string) $expected, $sharedSecret);
            }

            if (! $authOk) {
                Log::warning('Insmart webhook: bad auth', ['ip' => $request->ip()]);
                $logger->finish($event, 'error', 'Unauthorized: bad signature/secret');
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        Log::info('Insmart paid webhook received', ['externalOrderId' => $externalId]);

        try {
            $result = $service->handlePaidWebhook($payload);
            $logger->finish($event, 'success',
                "Insmart paid: order {$externalId}", $result, $externalId ?: null);
            return response()->json($result);
        } catch (Throwable $e) {
            $logger->finish($event, 'error', $e->getMessage(), null, $externalId ?: null);
            throw $e;
        }
    }
}
