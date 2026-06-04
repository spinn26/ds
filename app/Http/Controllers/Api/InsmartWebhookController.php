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
        $externalId = (string) ($payload['externalOrderId'] ?? $payload['orderId'] ?? '');

        // Полный контекст КАЖДОГО входящего запроса — пишется ДО авторизации,
        // поэтому в журнал попадает даже отклонённый/анонимный вызов. Нужно,
        // чтобы при первом боевом вебхуке от InSmart видеть точно, что и как
        // он шлёт (заголовки/query/raw body), и не гадать при отладке.
        // Секреты в заголовках и query маскируются до отпечатка (len + хвост).
        $logContext = [
            'method' => $request->method(),
            // url БЕЗ query-строки — она может нести ?secret=...; кладём её
            // отдельно в 'query' уже замаскированной (fullUrl() утёк бы секрет).
            'url' => $request->url(),
            'query' => $this->maskSecrets($request->query()),
            'headers' => $this->maskHeaders($request->headers->all()),
            // body маскируем: $request->all() подмешивает в тело query-параметры
            // (в т.ч. ?secret=...), иначе секрет утёк бы в лог открытым.
            'body' => $this->maskSecrets($payload),
            // raw — на случай, если body не распарсился (битый JSON / иной
            // content-type): тогда $payload пуст, а сырое тело видно тут.
            'raw' => mb_substr((string) $request->getContent(), 0, 8000),
        ];

        $event = $logger->begin(
            'insmart', 'inbound', 'paid_webhook',
            $request->ip(), null, $externalId ?: null, $logContext,
        );

        $expected = $settings->get('insmart.webhook_secret') ?: config('services.insmart.webhook_secret');

        // Observe-режим (bring-up): пока секрет не настроен — НЕ обрабатываем
        // (контракты/клиентов не создаём: авторизация и резолв партнёра ещё не
        // финализированы), но запрос уже полностью залогирован выше. Возвращаем
        // 200, чтобы InSmart принял URL и продолжил слать боевые payload'ы —
        // по ним мы увидим реальный формат (заголовки, appClientId) и зафиксируем
        // авторизацию. Накопленные события потом догоняются через admin replay.
        if (! $expected) {
            Log::warning('Insmart webhook: secret not configured — observe mode (logged, not processed)', ['ip' => $request->ip()]);
            $logger->finish($event, 'observed', 'Observed: secret not configured — logged, not processed', null, $externalId ?: null);
            return response()->json(['status' => 'observed'], 200);
        }

        {
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

    /**
     * Заголовки запроса в плоскую map name=>value. Чувствительные значения
     * (shared-secret / Authorization / Cookie) маскируются до отпечатка —
     * чтобы по логу можно было проверить «прислали ли заголовок и какой
     * длины», но не хранить валидный секрет в открытом виде.
     */
    private function maskHeaders(array $headers): array
    {
        $sensitive = ['x-insmart-secret', 'authorization', 'cookie', 'x-api-key', 'proxy-authorization'];
        $out = [];
        foreach ($headers as $name => $values) {
            $value = is_array($values) ? implode(', ', $values) : (string) $values;
            if (in_array(strtolower($name), $sensitive, true)) {
                $value = $this->fingerprint($value);
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /** Маскирует значения query-ключей, похожих на секрет. */
    private function maskSecrets(array $query): array
    {
        $sensitive = ['secret', 'token', 'key', 'password', 'pass'];
        $out = [];
        foreach ($query as $name => $value) {
            if (is_string($value) && in_array(strtolower((string) $name), $sensitive, true)) {
                $out[$name] = $this->fingerprint($value);
            } else {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /** Отпечаток секрета: длина + последние 4 символа, без раскрытия. */
    private function fingerprint(string $value): string
    {
        if ($value === '') return '';
        $len = mb_strlen($value);
        $tail = $len > 4 ? mb_substr($value, -4) : '';
        return "***(len={$len}" . ($tail !== '' ? ",…{$tail}" : '') . ')';
    }
}
