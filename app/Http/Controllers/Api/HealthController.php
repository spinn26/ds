<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /api/v1/health — реальные проверки зависимостей.
 *
 * /up (Laravel default) отдаёт 200 даже если БД лежит. Этот эндпоинт
 * выдаёт 200 только когда всё реально живо; иначе 503 с детализацией.
 * Подходит для monitoring/uptime-robot и status-badge.
 *
 * Публичный (без auth), чтобы не зависеть от Sanctum при проблемах с БД.
 */
class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'socket' => $this->checkSocket(),
            'migrations' => $this->checkMigrations(),
        ];

        $allOk = collect($checks)->every(fn ($c) => $c['ok'] ?? false);

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
            'version' => config('app.version', 'dev'),
        ], $allOk ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            $result = DB::select('SELECT 1 AS ok');
            $ms = (int) round((microtime(true) - $start) * 1000);
            return ['ok' => true, 'latency_ms' => $ms, 'driver' => DB::connection()->getDriverName()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health:ping:' . random_int(1, 999999);
            Cache::put($key, '1', 5);
            $value = Cache::get($key);
            Cache::forget($key);
            return ['ok' => $value === '1', 'driver' => config('cache.default')];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        try {
            $path = storage_path('app');
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            if (! $free || ! $total) {
                return ['ok' => false, 'error' => 'Cannot read disk space'];
            }
            $usedPct = (int) round((1 - $free / $total) * 100);
            // Деградация если осталось менее 10% свободного места.
            $ok = $usedPct < 90;
            return [
                'ok' => $ok,
                'free_gb' => round($free / 1024 / 1024 / 1024, 2),
                'used_percent' => $usedPct,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkSocket(): array
    {
        // socket-server слушает 3001 (WS) и 3002 (HTTP emit). Проверяем HTTP-эмит:
        // если порт открыт + отдаёт хоть какой-то ответ — сервер жив.
        $host = config('services.socket.host', '127.0.0.1');
        $port = (int) config('services.socket.port', 3002);
        try {
            $fp = @fsockopen($host, $port, $errno, $errstr, 1.5);
            if (! $fp) {
                return ['ok' => false, 'error' => "tcp://{$host}:{$port}: $errstr ($errno)"];
            }
            fclose($fp);
            return ['ok' => true, 'host' => $host, 'port' => $port];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkMigrations(): array
    {
        try {
            $pending = collect(app('migrator')->paths())
                ->flatMap(fn ($p) => glob(rtrim($p, '/') . '/*.php'))
                ->map(fn ($f) => pathinfo($f, PATHINFO_FILENAME))
                ->count();
            $applied = DB::table('migrations')->count();
            return [
                'ok' => true,
                'applied' => $applied,
                'discovered' => $pending,
                'pending' => max(0, $pending - $applied),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
