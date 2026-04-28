<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InsmartIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook-приёмник для Insmart (per spec ✅Инсмарт.md).
 *
 * Endpoint: POST /api/v1/webhooks/insmart/paid
 * Авторизация: HMAC-подпись по X-Insmart-Signature (TODO: подключить
 * после получения секрета от Insmart). На время разработки —
 * простой shared-secret через config/services.php.
 */
class InsmartWebhookController extends Controller
{
    public function paid(Request $request, InsmartIntegrationService $service): JsonResponse
    {
        // Простая проверка shared secret. В проде — HMAC SHA256 от тела.
        $expected = config('services.insmart.webhook_secret');
        $provided = $request->header('X-Insmart-Secret');
        if ($expected && $provided !== $expected) {
            Log::warning('Insmart webhook: bad secret', ['ip' => $request->ip()]);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        Log::info('Insmart paid webhook received', ['externalOrderId' => $payload['externalOrderId'] ?? null]);

        $result = $service->handlePaidWebhook($payload);
        return response()->json($result);
    }
}
