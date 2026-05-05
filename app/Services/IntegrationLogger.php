<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Запись событий интеграций в единый журнал `integration_events`.
 *
 * Использование:
 *   $event = $logger->begin('insmart', 'inbound', 'paid_webhook', request()->ip());
 *   try {
 *       $result = $service->handle($payload);
 *       $logger->finish($event, status: 'success', summary: '...', response: $result);
 *   } catch (\Throwable $e) {
 *       $logger->finish($event, status: 'error', summary: $e->getMessage());
 *       throw $e;
 *   }
 *
 * Никогда не бросает наружу — если БД недоступна, ошибка логируется
 * через Log::warning, но обёрнутая бизнес-операция не страдает.
 *
 * Ограничение: payload-поля обрезаются до 256 КБ каждое — чтобы один
 * раздутый ответ не положил всю таблицу.
 */
class IntegrationLogger
{
    private const MAX_PAYLOAD_BYTES = 256 * 1024;

    /**
     * Открыть событие. Возвращает массив с id и start-time для finish().
     *
     * @return array{id:int|null,started_at:float}|null
     */
    public function begin(
        string $service,
        string $direction,
        string $action,
        ?string $ip = null,
        ?int $actorId = null,
        ?string $externalId = null,
        ?array $request = null,
    ): ?array {
        $started = microtime(true);
        try {
            $id = DB::table('integration_events')->insertGetId([
                'service' => $service,
                'direction' => $direction,
                'action' => $action,
                'status' => 'pending',
                'request' => $request !== null ? $this->jsonOrNull($request) : null,
                'external_id' => $externalId,
                'actor_id' => $actorId,
                'ip' => $ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return ['id' => $id, 'started_at' => $started];
        } catch (Throwable $e) {
            Log::warning('IntegrationLogger.begin failed: ' . $e->getMessage());
            return ['id' => null, 'started_at' => $started];
        }
    }

    /**
     * Закрыть событие. Безопасно для null-handle (если begin провалился).
     */
    public function finish(
        ?array $event,
        string $status,
        ?string $summary = null,
        ?array $response = null,
        ?string $externalId = null,
    ): void {
        if (! $event) return;
        $duration = (int) round((microtime(true) - $event['started_at']) * 1000);
        if (! $event['id']) {
            // Запись не создалась в begin — попробуем сделать единым insert'ом.
            $this->logOneShot($status, $summary, $response, $duration);
            return;
        }
        try {
            DB::table('integration_events')
                ->where('id', $event['id'])
                ->update([
                    'status' => $status,
                    'summary' => $summary !== null ? mb_substr($summary, 0, 4000) : null,
                    'response' => $response !== null ? $this->jsonOrNull($response) : null,
                    'duration_ms' => $duration,
                    'external_id' => $externalId ?: DB::raw('external_id'),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('IntegrationLogger.finish failed: ' . $e->getMessage());
        }
    }

    /**
     * Короткий путь для синхронных операций «вызвал → сразу логнуть результат».
     */
    public function record(
        string $service,
        string $direction,
        string $action,
        string $status,
        ?string $summary = null,
        ?array $request = null,
        ?array $response = null,
        ?int $durationMs = null,
        ?string $externalId = null,
        ?int $actorId = null,
        ?string $ip = null,
    ): void {
        try {
            DB::table('integration_events')->insert([
                'service' => $service,
                'direction' => $direction,
                'action' => $action,
                'status' => $status,
                'summary' => $summary !== null ? mb_substr($summary, 0, 4000) : null,
                'request' => $request !== null ? $this->jsonOrNull($request) : null,
                'response' => $response !== null ? $this->jsonOrNull($response) : null,
                'duration_ms' => $durationMs,
                'external_id' => $externalId,
                'actor_id' => $actorId,
                'ip' => $ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('IntegrationLogger.record failed: ' . $e->getMessage());
        }
    }

    private function logOneShot(string $status, ?string $summary, ?array $response, int $duration): void
    {
        try {
            DB::table('integration_events')->insert([
                'service' => 'unknown',
                'direction' => 'outbound',
                'action' => 'fallback',
                'status' => $status,
                'summary' => $summary,
                'response' => $response !== null ? $this->jsonOrNull($response) : null,
                'duration_ms' => $duration,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('IntegrationLogger.logOneShot failed: ' . $e->getMessage());
        }
    }

    private function jsonOrNull(array $payload): ?string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) return null;
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            // Обрезаем большой payload, оставляя признак.
            return mb_substr($json, 0, self::MAX_PAYLOAD_BYTES - 50) . '..."__truncated":true}';
        }
        return $json;
    }
}
