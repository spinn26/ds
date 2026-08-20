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
 * Проверяет: PostgreSQL, Cache, Socket.IO, success-rate интеграций и свежесть
 * ночного бэкапа БД. Любой компонент down → шлёт
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
            $this->checkIntegrationSuccessRate(),
            $this->checkDatabaseBackup(),
        ];
    }

    /**
     * Свежесть ночного бэкапа БД (scripts/newds-db-backup.sh, cron 02:30).
     *
     * Тихо сломавшийся бэкап опаснее отсутствующего: все считают, что копия
     * есть, а её нет. Сам скрипт при сбое возвращает ненулевой код и пишет в
     * /var/log/newds-backup.log — но этого никто не видит, поэтому свежесть
     * файла проверяем отсюда.
     *
     * Порога 36 часов хватает, чтобы одна пропущенная ночь не будила, а две
     * подряд — разбудили. Каталога нет: на проде это авария (бэкапы снесли),
     * вне прода — норма, там их и не бывает.
     */
    private function checkDatabaseBackup(): array
    {
        try {
            $dir = (string) config('services.db_backup.dir');
            $maxAge = (int) config('services.db_backup.max_age_hours', 36);

            if (! is_dir($dir)) {
                return app()->environment('production')
                    ? ['name' => 'db-backup', 'ok' => false, 'details' => "каталог {$dir} отсутствует"]
                    : ['name' => 'db-backup', 'ok' => true, 'details' => 'skip (не production)'];
            }

            $files = glob(rtrim($dir, '/').'/*.dump') ?: [];
            if (! $files) {
                return ['name' => 'db-backup', 'ok' => false, 'details' => 'дампов нет вовсе'];
            }

            usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $newest = $files[0];
            $ageHours = (int) round((time() - filemtime($newest)) / 3600);
            $sizeMb = (int) round(filesize($newest) / 1048576);

            return [
                'name' => 'db-backup',
                'ok' => $ageHours <= $maxAge,
                'details' => $ageHours <= $maxAge
                    ? "последний {$ageHours} ч назад, {$sizeMb} МБ"
                    : "последний {$ageHours} ч назад (порог {$maxAge} ч) — бэкап не делается",
            ];
        } catch (\Throwable $e) {
            return ['name' => 'db-backup', 'ok' => false, 'details' => $e->getMessage()];
        }
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
            $host = config('services.socket.host', '127.0.0.1');
            $port = config('services.socket.api_port', 3002);
            $resp = Http::timeout(3)->get("http://{$host}:{$port}/health");
            $data = $resp->json() ?? [];
            $connections = (int) ($data['connections'] ?? 0);
            $online = (int) ($data['onlineUsers'] ?? 0);
            return [
                'name' => 'socket.io',
                'ok' => $resp->ok(),
                'details' => $resp->ok()
                    ? "online={$online} conn={$connections}"
                    : "HTTP {$resp->status()}",
            ];
        } catch (\Throwable $e) {
            return ['name' => 'socket.io', 'ok' => false, 'details' => 'unreachable'];
        }
    }

    /**
     * Просадка success-rate в integration_events за последний час.
     * Алерт если у любого внешнего сервиса (insmart/google_sheets/telegram/
     * smtp/zammad) success-rate < 80% при минимум 5 событиях.
     */
    private function checkIntegrationSuccessRate(): array
    {
        try {
            // Если таблицы нет (миграция не накачена) — не падаем, просто skip.
            if (! \Illuminate\Support\Facades\Schema::hasTable('integration_events')) {
                return ['name' => 'integrations', 'ok' => true, 'details' => 'skip (no table)'];
            }

            $rows = DB::table('integration_events')
                ->where('created_at', '>=', now()->subHour())
                ->whereIn('service', ['insmart', 'google_sheets', 'telegram', 'smtp', 'zammad'])
                ->selectRaw("
                    service,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'success') AS ok_cnt
                ")
                ->groupBy('service')
                ->get();

            $degraded = [];
            foreach ($rows as $r) {
                if ((int) $r->total < 5) continue; // мало данных — не алертим
                $rate = $r->ok_cnt / max(1, $r->total) * 100;
                if ($rate < 80.0) {
                    $degraded[] = sprintf('%s %d/%d (%.0f%%)',
                        $r->service, $r->ok_cnt, $r->total, $rate);
                }
            }

            if (! empty($degraded)) {
                return [
                    'name' => 'integrations',
                    'ok' => false,
                    'details' => 'degraded: ' . implode(', ', $degraded),
                ];
            }
            return ['name' => 'integrations', 'ok' => true,
                'details' => $rows->count() . ' service(s) ok'];
        } catch (\Throwable $e) {
            return ['name' => 'integrations', 'ok' => false, 'details' => $e->getMessage()];
        }
    }

    /** Компоненты, без которых платформа реально лежит. */
    private const CRITICAL = ['postgres', 'cache', 'socket.io'];

    private function sendDownAlert(array $checks): void
    {
        $failed = collect($checks)->reject(fn ($c) => $c['ok']);

        // «Недоступна» — только когда лёг критичный компонент. Просевшая
        // интеграция или несделанный бэкап требуют внимания, но платформа при
        // этом работает, и кричать «недоступна» — врать дежурному.
        $isOutage = $failed->contains(fn ($c) => in_array($c['name'], self::CRITICAL, true));

        $lines = [$isOutage ? '🔴 <b>Платформа недоступна</b>' : '⚠️ <b>Платформа требует внимания</b>', ''];
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
        $text = "🟢 <b>Всё в норме</b>\n\nВремя: " . now()->format('d.m.Y H:i:s');
        $this->telegram->send($text);
    }
}
