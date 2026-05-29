<?php

namespace App\Providers;

use App\Auth\LegacyUserProvider;
use App\Listeners\RecordMailLog;
use App\Services\MailSettingsService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Auth::provider('legacy', function ($app, array $config) {
            return new LegacyUserProvider($app['hash'], $config['model']);
        });

        // Mail deliverability logging: каждое уходящее письмо проходит
        // через MessageSending (вставляем pending-запись в mail_log) и
        // MessageSent (обновляем sent_at + message_id + smtp_response).
        // Failure ловится в catch-блоках вызывающего кода и пишется через
        // RecordMailLog::recordFailure().
        Event::listen(MessageSending::class, [RecordMailLog::class, 'handleSending']);
        Event::listen(MessageSent::class, [RecordMailLog::class, 'handleSent']);

        // SMTP-настройки в БД (mail_settings → is_default=true) — единый
        // источник правды для всех исходящих писем, включая Notifications
        // (sendResetLink и т.п.). Без этого вызова config('mail.from')
        // оставался дефолтным `hello@example.com` → Symfony генерил
        // Message-ID с @example.com → spam-фильтры срезали.
        // Skip в console-контексте только для миграций/db:seed, где БД
        // ещё может быть пустой; для queue/scheduler-задач — применяем.
        if (! app()->runningInConsole() || $this->isQueueOrScheduleContext()) {
            try {
                app(MailSettingsService::class)->applyRuntimeConfig();
            } catch (\Throwable $e) {
                // Не блокируем загрузку приложения, если БД недоступна
                // (миграции на свежей установке, например).
            }
        }
    }

    /**
     * true — если мы в `php artisan queue:work` / scheduled task /
     * tinker и реально можем отправлять письма; false — для миграций
     * и команд установки.
     */
    private function isQueueOrScheduleContext(): bool
    {
        if (! app()->runningInConsole()) return true;
        $argv = $_SERVER['argv'] ?? [];
        $cmd = $argv[1] ?? '';
        return in_array($cmd, ['queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'tinker'], true);

        // PostgreSQL statement_timeout: тяжёлый запрос будет прерван через
        // 30с вместо того чтобы держать соединение до nginx-таймаута.
        // CLI-контекст (artisan, queue worker) — без лимита: миграции,
        // pool:recalc, импорт могут идти минутами.
        if (config('database.default') === 'pgsql' && ! app()->runningInConsole()) {
            $timeout = (int) env('DB_STATEMENT_TIMEOUT_MS', 30_000);
            try {
                DB::statement("SET statement_timeout = {$timeout}");
            } catch (\Throwable $e) {
                // Не блокируем загрузку приложения если БД недоступна.
            }
        }
    }
}
