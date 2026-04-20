<?php

namespace App\Console\Commands;

use App\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Проверка здоровья платформы. Запускается по расписанию (каждые 5 минут).
 *
 * Проверяет: PostgreSQL, Cache, Socket.IO. Любой компонент down → шлёт
 * алерт в Telegram. Состояние кэшируется, чтобы слать только edge-ы
 * (переход up→down и down→up), а не каждые 5 минут дублировать.
 *
 *   php artisan platform:health-check            # обычный запуск
 *   php artisan platform:health-check --force    # игнорировать кэш и послать алерт всегда
 *   php artisan platform:health-check -v         # показать детали каждой проверки
 */
class PlatformHealthCheck extends Command
{
    protected $signature = 'platform:health-check {--force}';

    protected $description = 'Проверяет БД/Cache/Socket и шлёт алерт в Telegram при сбоях';

    private const CACHE_KEY = 'platform:health:last-status';

    public function __construct(
        private readonly TelegramNotifier $telegram,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $checks = $this->runChecks();
        $allUp = collect($checks)->every(fn ($c) => $c['ok']);
        $status = $allUp ? 'up' : 'down';

        $lastStatus = Cache::get(self::CACHE_KEY);

        if ($this->getOutput()->isVerbose()) {
            foreach ($checks as $c) {
                $this->line(sprintf('  [%s] %-10s %s', $c['ok'] ? 'OK' : 'FAIL', $c['name'], $c['details']));
            }
            $this->info("Overall: {$status} (previous: " . ($lastStatus ?? 'unknown') . ")");
        }

        // Send alert only on state transition (or when forced)
        $shouldNotify = $this->option('force') || ($lastStatus !== null && $lastStatus !== $status);

        if ($shouldNotify) {
            if ($status === 'down') {
                $this->sendDownAlert($checks);
            } else {
                $this->sendUpAlert();
            }
        }

        Cache::put(self::CACHE_KEY, $status, now()->addDay());

        return $allUp ? self::SUCCESS : self::FAILURE;
    }

    private function runChecks(): array
    {
        return [
            $this->checkPostgres(),
            $this->checkCache(),
            $this->checkSocketIo(),
        ];
    }

    private function checkPostgres(): array
    {
        try {
            $start = microtime(true);
            $ok = (bool) DB::selectOne('SELECT 1 AS ok')->ok;
            $ms = round((microtime(true) - $start) * 1000);
            return ['name' => 'postgres', 'ok' => $ok, 'details' => "RTT {$ms}ms"];
        } catch (\Throwable $e) {
            return ['name' => 'postgres', 'ok' => false, 'details' => $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            Cache::put('__healthcheck', 'ok', 5);
            $v = Cache::get('__healthcheck');
            return ['name' => 'cache', 'ok' => $v === 'ok', 'details' => 'read/write ok'];
        } catch (\Throwable $e) {
            return ['name' => 'cache', 'ok' => false, 'details' => $e->getMessage()];
        }
    }

    private function checkSocketIo(): array
    {
        try {
            $resp = Http::timeout(3)->get('http://127.0.0.1:3002/health');
            return [
                'name' => 'socket.io',
                'ok' => $resp->ok(),
                'details' => $resp->ok() ? 'HTTP 200' : "HTTP {$resp->status()}",
            ];
        } catch (\Throwable $e) {
            return ['name' => 'socket.io', 'ok' => false, 'details' => 'unreachable'];
        }
    }

    private function sendDownAlert(array $checks): void
    {
        $failed = collect($checks)->reject(fn ($c) => $c['ok']);
        $lines = ["🔴 <b>Платформа недоступна</b>", ''];
        $lines[] = 'Время: ' . now()->format('d.m.Y H:i:s');
        $lines[] = '';
        $lines[] = '<b>Проблемы:</b>';
        foreach ($failed as $c) {
            $lines[] = sprintf('• <code>%s</code>: %s', $c['name'], $c['details']);
        }
        $this->telegram->send(implode("\n", $lines));
    }

    private function sendUpAlert(): void
    {
        $text = "🟢 <b>Платформа восстановлена</b>\n\nВремя: " . now()->format('d.m.Y H:i:s');
        $this->telegram->send($text);
    }
}
