<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminMonitoringController extends Controller
{
    use PaginatesRequests;

    /**
     * Overall dashboard snapshot — KPIs, service health, uptime hints.
     */
    public function status(): JsonResponse
    {
        $cutoff = Carbon::now()->subDay();

        $dbPing = $this->timedCheck(function () {
            DB::select('SELECT 1');
        });

        $cachePing = $this->timedCheck(function () {
            $k = 'monitoring:ping:' . uniqid();
            Cache::put($k, 1, 5);
            Cache::forget($k);
        });

        $pendingJobs = $this->safeCount('jobs');
        $failedJobs = $this->safeCount('failed_jobs');
        $failed24h = $this->safeCountWhere('failed_jobs', fn ($q) => $q->where('failed_at', '>=', $cutoff));

        $mail24h = $this->safeCountWhere('mail_log', fn ($q) => $q->where('created_at', '>=', $cutoff));
        $mailFailed24h = $this->safeCountWhere('mail_log', fn ($q) => $q->where('status', 'failed')->where('created_at', '>=', $cutoff));

        $systemErrors24h = $this->safeCountWhere('SystemException', fn ($q) => $q->where('_dateCreated', '>=', $cutoff));
        $n8nErrors24h = $this->safeCountWhere('errorN8nlog', fn ($q) => $q->where('createdAt', '>=', $cutoff));

        $activeSessions = $this->safeCountWhere('personal_access_tokens', fn ($q) => $q->where('last_used_at', '>=', Carbon::now()->subMinutes(15)));

        // SMTP configured?
        $mailSettings = null;
        try {
            $mailSettings = Schema::hasTable('mail_settings') ? DB::table('mail_settings')->first() : null;
        } catch (\Throwable) {
        }
        $mailConfigured = $mailSettings && ($mailSettings->host ?? null) && ($mailSettings->from_address ?? null);

        // Storage: approximate table sizes on Postgres
        $dbSize = $this->databaseSize();

        return response()->json([
            'generatedAt' => now()->toIso8601String(),
            'services' => [
                'database' => [
                    'ok' => $dbPing['ok'],
                    'latencyMs' => $dbPing['ms'],
                    'error' => $dbPing['error'],
                ],
                'cache' => [
                    'ok' => $cachePing['ok'],
                    'latencyMs' => $cachePing['ms'],
                    'error' => $cachePing['error'],
                ],
                'queue' => [
                    'ok' => $failedJobs < 100,  // informational only
                    'pending' => $pendingJobs,
                    'failed' => $failedJobs,
                ],
                'mail' => [
                    'ok' => $mailConfigured,
                    'configured' => $mailConfigured,
                    'failed24h' => $mailFailed24h,
                    'sent24h' => max(0, $mail24h - $mailFailed24h),
                ],
            ],
            'errors24h' => [
                'total' => $failed24h + $mailFailed24h + $systemErrors24h + $n8nErrors24h,
                'failedJobs' => $failed24h,
                'mail' => $mailFailed24h,
                'system' => $systemErrors24h,
                'n8n' => $n8nErrors24h,
            ],
            'activeSessions' => $activeSessions,
            'dbSize' => $dbSize,
            'php' => [
                'version' => PHP_VERSION,
                'memoryUsageMb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'timezone' => date_default_timezone_get(),
            ],
            'laravel' => [
                'env' => app()->environment(),
                'debug' => config('app.debug'),
            ],
        ]);
    }

    /**
     * Unified error feed from multiple sources.
     * Query params:
     *   source: failed_jobs | mail | system | n8n | all (default all)
     *   limit: default 50, max 200
     */
    public function errors(Request $request): JsonResponse
    {
        $source = $request->input('source', 'all');
        $limit = max(1, min(200, (int) $request->input('limit', 50)));

        $items = collect();

        if ($source === 'all' || $source === 'failed_jobs') {
            try {
                if (Schema::hasTable('failed_jobs')) {
                    $rows = DB::table('failed_jobs')
                        ->orderByDesc('failed_at')->limit($limit)
                        ->get(['id', 'connection', 'queue', 'exception', 'failed_at', 'payload']);
                    foreach ($rows as $r) {
                        $items->push([
                            'source' => 'queue',
                            'id' => 'job-' . $r->id,
                            'raw_id' => $r->id,
                            'title' => $this->extractJobName($r->payload),
                            'message' => $this->firstLine($r->exception),
                            'detail' => mb_substr($r->exception ?? '', 0, 4000),
                            'at' => $r->failed_at,
                        ]);
                    }
                }
            } catch (\Throwable) {
            }
        }

        if ($source === 'all' || $source === 'mail') {
            try {
                if (Schema::hasTable('mail_log')) {
                    $rows = DB::table('mail_log')
                        ->where('status', 'failed')
                        ->orderByDesc('id')->limit($limit)
                        ->get(['id', 'recipient_email', 'subject', 'error', 'created_at']);
                    foreach ($rows as $r) {
                        $items->push([
                            'source' => 'mail',
                            'id' => 'mail-' . $r->id,
                            'raw_id' => $r->id,
                            'title' => "Email → {$r->recipient_email}",
                            'message' => $r->error ?: 'Неизвестная ошибка',
                            'detail' => "Тема: {$r->subject}\n\n" . ($r->error ?? ''),
                            'at' => $r->created_at,
                        ]);
                    }
                }
            } catch (\Throwable) {
            }
        }

        if ($source === 'all' || $source === 'system') {
            try {
                if (Schema::hasTable('SystemException')) {
                    $rows = DB::table('SystemException')
                        ->orderByDesc('_dateCreated')->limit($limit)
                        ->get(['id', 'msg', 'pilotSysName', 'stepID', 'scenarioName', '_dateCreated']);
                    foreach ($rows as $r) {
                        $items->push([
                            'source' => 'system',
                            'id' => 'sys-' . $r->id,
                            'raw_id' => $r->id,
                            'title' => $r->scenarioName ? "Сценарий: {$r->scenarioName}" : 'Системное исключение',
                            'message' => $this->firstLine($r->msg),
                            'detail' => $r->msg,
                            'at' => $r->_dateCreated,
                        ]);
                    }
                }
            } catch (\Throwable) {
            }
        }

        if ($source === 'all' || $source === 'n8n') {
            try {
                if (Schema::hasTable('errorN8nlog')) {
                    $rows = DB::table('errorN8nlog')
                        ->orderByDesc('createdAt')->limit($limit)
                        ->get();
                    foreach ($rows as $r) {
                        $items->push([
                            'source' => 'n8n',
                            'id' => 'n8n-' . $r->id,
                            'raw_id' => $r->id,
                            'title' => $r->workflowName ?? 'n8n ошибка',
                            'message' => $this->firstLine($r->error ?? ''),
                            'detail' => $r->error ?? '',
                            'at' => $r->createdAt,
                        ]);
                    }
                }
            } catch (\Throwable) {
            }
        }

        $items = $items->sortByDesc('at')->values()->take($limit);

        return response()->json(['items' => $items]);
    }

    /**
     * Try to retry a failed queue job via Artisan.
     */
    public function retryJob(int $id): JsonResponse
    {
        if (! Schema::hasTable('failed_jobs')) {
            return response()->json(['message' => 'Таблица failed_jobs недоступна'], 404);
        }
        $row = DB::table('failed_jobs')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Job not found'], 404);

        Artisan::call('queue:retry', ['id' => [(string) $row->uuid]]);

        return response()->json(['message' => 'Задача отправлена на повтор']);
    }

    /**
     * Delete a failed queue job.
     */
    public function forgetJob(int $id): JsonResponse
    {
        if (! Schema::hasTable('failed_jobs')) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $deleted = DB::table('failed_jobs')->where('id', $id)->delete();
        return $deleted
            ? response()->json(['message' => 'Удалено'])
            : response()->json(['message' => 'Not found'], 404);
    }

    /**
     * GET /admin/monitoring/log/errors
     *
     * Парсит storage/logs/laravel.log и отдаёт структурированные записи.
     * Берём только хвост файла (1 MB) — для админ-UI нужны свежие, не вся
     * история. На фильтр level пропускаем ERROR/WARNING/CRITICAL по умолчанию.
     *
     * Query:
     *   level: ERROR | WARNING | CRITICAL | ALL (default: ERROR+WARNING+CRITICAL)
     *   limit: 1..500, default 200
     */
    public function logErrors(Request $request): JsonResponse
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return response()->json(['items' => [], 'total' => 0, 'fileSize' => 0, 'mtime' => null]);
        }

        $level = strtoupper((string) $request->input('level', 'ALL_ERRORS'));
        $limit = max(1, min(500, (int) $request->input('limit', 200)));

        $size = filesize($path);
        $chunk = min($size, 1024 * 1024); // последний MB
        $fp = @fopen($path, 'rb');
        if (! $fp) {
            return response()->json(['items' => [], 'total' => 0, 'fileSize' => $size]);
        }
        if ($size > $chunk) fseek($fp, $size - $chunk);
        $tail = stream_get_contents($fp);
        fclose($fp);

        // Если читали с середины — обрезаем до начала первой строки,
        // иначе первый матч будет частичным.
        if ($size > $chunk) {
            $nl = strpos($tail, "\n");
            if ($nl !== false) $tail = substr($tail, $nl + 1);
        }

        $items = [];
        $pattern = '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s+([a-z_]+)\.(\w+):\s+(.+?)(?=^\[\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}\]|\z)/sm';
        if (preg_match_all($pattern, $tail, $matches, PREG_SET_ORDER)) {
            foreach (array_reverse($matches) as $m) {
                $entryLevel = strtoupper($m[3]);
                $ok = match ($level) {
                    'ALL' => true,
                    'ERROR' => $entryLevel === 'ERROR',
                    'WARNING' => $entryLevel === 'WARNING',
                    'CRITICAL' => $entryLevel === 'CRITICAL',
                    default => in_array($entryLevel, ['ERROR', 'WARNING', 'CRITICAL', 'EMERGENCY', 'ALERT'], true),
                };
                if (! $ok) continue;

                $body = trim($m[4]);
                $firstLine = trim(strtok($body, "\n"));
                $items[] = [
                    'timestamp' => $m[1],
                    'env' => $m[2],
                    'level' => $entryLevel,
                    'message' => mb_substr($firstLine, 0, 500),
                    'detail' => mb_substr($body, 0, 6000),
                ];
                if (count($items) >= $limit) break;
            }
        }

        return response()->json([
            'items' => $items,
            'total' => count($items),
            'fileSize' => $size,
            'fileSizeHuman' => $this->formatBytes($size),
            'mtime' => date('c', filemtime($path)),
            'truncated' => $size > $chunk,
        ]);
    }

    /**
     * GET /admin/monitoring/log/download
     *
     * Стримит storage/logs/laravel.log как .log-файл. На больших логах
     * (десятки MB) клиент скачивает напрямую без буферизации в память.
     */
    public function downloadLog(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            abort(404, 'Log file not found');
        }

        $filename = 'laravel-' . now()->format('Y-m-d_His') . '.log';
        return response()->streamDownload(function () use ($path) {
            $h = fopen($path, 'rb');
            if (! $h) return;
            while (! feof($h)) {
                echo fread($h, 8192);
                @ob_flush();
                flush();
            }
            fclose($h);
        }, $filename, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Length' => (string) filesize($path),
        ]);
    }

    /**
     * POST /admin/monitoring/log/clear
     *
     * Усекает laravel.log до 0 байт (атомарно через ftruncate). Удобно
     * после фикса инцидента — оператор обнуляет лог и наблюдает только
     * новые ошибки.
     */
    public function clearLog(): JsonResponse
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return response()->json(['message' => 'Log file not found'], 404);
        }
        $fp = @fopen($path, 'r+');
        if (! $fp) return response()->json(['message' => 'Cannot open log for truncate'], 500);
        ftruncate($fp, 0);
        fclose($fp);

        return response()->json(['message' => 'Лог очищен']);
    }

    /**
     * Clear all failed jobs.
     */
    public function flushJobs(): JsonResponse
    {
        if (! Schema::hasTable('failed_jobs')) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $n = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();
        return response()->json(['message' => "Очищено: {$n}"]);
    }

    // ============ helpers ============

    private function timedCheck(\Closure $op): array
    {
        $start = microtime(true);
        try {
            $op();
            return ['ok' => true, 'ms' => round((microtime(true) - $start) * 1000, 1), 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'ms' => round((microtime(true) - $start) * 1000, 1), 'error' => $e->getMessage()];
        }
    }

    private function safeCount(string $table): int
    {
        if (! Schema::hasTable($table)) return 0;
        try {
            return DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safeCountWhere(string $table, \Closure $apply): int
    {
        if (! Schema::hasTable($table)) return 0;
        try {
            return $apply(DB::table($table))->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function extractJobName(?string $payload): string
    {
        if (! $payload) return 'Job';
        $decoded = @json_decode($payload, true);
        return $decoded['displayName'] ?? ($decoded['data']['commandName'] ?? 'Job');
    }

    private function firstLine(?string $text): string
    {
        if (! $text) return '';
        $line = strtok($text, "\n");
        return mb_substr($line ?: $text, 0, 500);
    }

    private function databaseSize(): array
    {
        try {
            $row = DB::selectOne('SELECT pg_database_size(current_database()) AS bytes');
            $bytes = (int) ($row->bytes ?? 0);
            return [
                'bytes' => $bytes,
                'formatted' => $this->formatBytes($bytes),
            ];
        } catch (\Throwable) {
            return ['bytes' => 0, 'formatted' => '—'];
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) return round($bytes / 1024 ** 3, 1) . ' GB';
        if ($bytes >= 1024 ** 2) return round($bytes / 1024 ** 2, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
