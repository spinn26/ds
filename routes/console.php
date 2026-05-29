<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Ежедневная проверка статусов партнёров
Schedule::command('partners:check-statuses')->dailyAt('02:00');

// Ежедневное применение Отрыва/ОП за текущий месяц (04:00).
// MonthlyPenaltyRunner идемпотентный: откатывает прошлые withheld* и
// пересчитывает заново от brutto, так что ночной прогон безопасен
// для уже финализированных дней внутри месяца.
//   - закрытый период (PeriodFreezeService) автоматически пропускается;
//   - можно вручную: `php artisan finalize:apply` или с явными аргументами.
Schedule::command('finalize:apply')
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->runInBackground();

// 1-го числа каждого месяца в 00:00 — копирование курсов валют с прошлого
// месяца как заглушка, чтобы расчёты платформы не падали (per spec
// ✅Справочники для расчёта транзакций §2.1 шаг 1).
Schedule::command('currencies:copy-monthly-rates')
    ->monthlyOn(1, '00:00');

// Health-check платформы (БД/Cache/Socket.IO) — каждые 5 минут.
// Алерт в Telegram шлётся только при переходе up↔down, чтобы не спамить.
Schedule::command('platform:health-check')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// === Cleanup-задачи (предотвращают рост служебных таблиц) ===

// Истёкшие Sanctum-токены: с SANCTUM_TOKEN_EXPIRATION=43200 (30 дней)
// токены копятся в personal_access_tokens. Чистим раз в сутки.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:00');

// Failed jobs старше 30 дней — иначе таблица растёт.
Schedule::command('queue:prune-failed --hours=720')->dailyAt('03:15');

// Job batches старше 7 дней (unfinished — старше суток).
Schedule::command('queue:prune-batches --hours=168 --unfinished=24')
    ->dailyAt('03:20');

// Журнал интеграций (integration_events): хранить 90 дней. Таблица растёт
// на ~1000 событий/день — за год это гигабайты, в основном бесполезные.
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('integration_events')
        ->where('created_at', '<', now()->subDays(90))
        ->delete();
})->dailyAt('03:30')->name('integration-events:prune');

// Mail log — хранить 1 год. Старые email-рассылки больше не нужны
// (для compliance достаточно chat_ticket_changes).
Schedule::call(function () {
    if (\Illuminate\Support\Facades\Schema::hasTable('mail_log')) {
        \Illuminate\Support\Facades\DB::table('mail_log')
            ->where('created_at', '<', now()->subYear())
            ->delete();
    }
})->monthlyOn(1, '03:45')->name('mail-log:prune');

// === Spatie Health checks ===
// Каждую минуту запускаем `health:check` чтобы все проверки записали
// результат в history-таблицу. На основе этого работают:
//   - GET /admin/health (HTML-дашборд) — берёт последний снимок;
//   - QueueCheck: пишет ping-job в queue, fail если worker не дёргает;
//   - ScheduleCheck: подтверждает что scheduler сам жив (heartbeat).
Schedule::command('health:check')->everyMinute();
// Health::queue heartbeat — отдельная команда, ставит ping job в очередь.
Schedule::command('health:queue-check-heartbeat')->everyMinute();
// Health::schedule heartbeat — фиксирует «scheduler сейчас работает».
Schedule::command('health:schedule-check-heartbeat')->everyMinute();
