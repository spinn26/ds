<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ⛔ АВТО-ПЕРЕСЧЁТЫ ОТКЛЮЧЕНЫ (2026-06-05, по требованию): все расчёты
// (статусы/квалификации, Отрыв/ОП, пул) выполняются ТОЛЬКО по кнопке
// руководителем расчётов — никаких ночных авто-прогонов. Раскомментировать
// можно только по явному запросу владельца.
//   - проверка статусов: `php artisan partners:check-statuses` (или кнопка);
//   - финализация Отрыв/ОП: `php artisan finalize:apply` (или кнопка периода).
// Schedule::command('partners:check-statuses')->dailyAt('02:00');
// Schedule::command('finalize:apply')->dailyAt('04:00')->withoutOverlapping(60)->runInBackground();

// Предупреждение партнёру за 30 дней до терминации (кабинет + Telegram).
// Под запрет авто-пересчётов НЕ попадает: команда ничего не считает и не
// меняет статусы, только рассылает. Терминирует по-прежнему
// `partners:check-statuses` по кнопке.
//
// Ежедневно, но каждому партнёру уходит ОДНО сообщение: отметка
// consultant.termination_warning_for хранит дедлайн, под который уже слали.
Schedule::command('partners:notify-termination-soon')->dailyAt('09:00');

// Поздравления с днём рождения — кабинет, почта, Telegram. Тоже рассылка,
// а не расчёт. В 10:00, а не в 09:00: утреннее письмо о скорой терминации и
// поздравление в одну минуту — плохое соседство, пусть расходятся по времени.
// Повтор в тот же день защищён проверкой уже созданных notifications.
Schedule::command('partners:notify-birthday')->dailyAt('10:00');

// Постоянная выгрузка платформы в Google-таблицу (Контракты/Клиенты/
// Консультанты) — инкремент по changedAt (upsert по ID). Это НЕ расчёт, а
// зеркало данных, поэтому под запрет авто-пересчётов не попадает. Запускается,
// только если заданы google.sheets.export_id + google.sa.credentials_path
// (иначе команда просто завершится с ошибкой в логе). withoutOverlapping —
// чтобы длинный первый full-прогон не наложился на следующий тик.
Schedule::command('sheets:export-platform')
    ->everyThirtyMinutes()
    ->withoutOverlapping(25)
    ->runInBackground();

// Монитор инварианта client.person СНЯТ с расписания (13.08.2026): контакты
// перенесены в карточки, приложение person не читает и не пишет — «чужая
// привязка» больше ни на что не влияет, и предупреждать не о чем. Команда
// оставлена: пригодится, если понадобится сверить архив.

// Авто-копирование курсов валют 1-го числа ОТКЛЮЧЕНО (2026-08-06, по решению
// заказчика). Курсы за месяц заводятся кнопкой «Добавить курсы» в
// /admin/currencies (POST /admin/currencies/rates).
//
// Задача и так не работала: currencyRate — legacy-таблица Directual БЕЗ
// сиквенса на id, а команда вставляла строку без него → 23502 not-null на
// каждом запуске. Вывод крона идёт в /dev/null, поэтому падение было не видно:
// на проде просто не появились июль и август 2026, а CurrencyRates::forDate()
// молча брал последний доступный курс за более ранний месяц.
//
// Сама команда currencies:copy-monthly-rates оставлена — её можно запустить
// руками, если нужно быстро продублировать прошлый месяц.

// Второй справочник курсов — для отчётов руководителей.
Schedule::command('currencies:copy-monthly-management-rates')
    ->monthlyOn(1, '00:05');

// Реквизиты на ручной верификации дольше 1 рабочего дня → уведомление
// финменеджеру (Богданова). Идемпотентно (overdue_notified_at), поэтому
// частый прогон безопасен; шлём только в рабочие часы будней.
Schedule::command('requisites:notify-overdue')
    ->weekdays()
    ->hourly()
    ->between('9:00', '19:00');

// Утренний дайджест задач финменеджера: курсы месяца, реквизиты и заявки на
// смену счёта старше 1 рабочего дня, забытые приостановки выплат.
// ⚠ Дополняет requisites:notify-overdue, а не дублирует его: тот напоминает
// про запись ОДИН раз (стампит overdue_notified_at), этот — каждое утро,
// пока работа висит. Если задач нет, уведомление не отправляется вовсе.
Schedule::command('alerts:pending-actions')
    ->weekdays()
    ->dailyAt('9:30');

// Health-check платформы (БД/Cache/Socket.IO) — каждые 5 минут.
// Алерт в Telegram шлётся только при переходе up↔down, чтобы не спамить.
Schedule::command('platform:health-check')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// === Cleanup-задачи (предотвращают рост служебных таблиц) ===

// Гигиена БД: снимки отчётов, старые бэкап-таблицы, ретеншн health_check.
// ⚠ Докблок команды обещал еженедельный запуск, но зарегистрирована она нигде
// не была — ретеншн health_check не работал вовсе. Идёт БЕЗ --apply: команда
// показывает, что бы удалила, а фактический дроп остаётся ручным решением
// (она сносит таблицы, автозапуск такого не заслуживает доверия по умолчанию).
Schedule::command('db:housekeep')
    ->weeklyOn(1, '03:40')
    ->withoutOverlapping(30)
    ->runInBackground();

// Истёкшие Sanctum-токены: с SANCTUM_TOKEN_EXPIRATION=43200 (30 дней)
// токены копятся в personal_access_tokens. Чистим раз в сутки.
$tokenHours = (int) \App\Models\SystemSetting::value('maintenance.sanctum_token_retention_hours', 24);
Schedule::command("sanctum:prune-expired --hours={$tokenHours}")->dailyAt('03:00');

// Failed jobs — срок хранения настраивается в админке (Обслуживание),
// фолбэк 30 дней. Читается при загрузке планировщика.
$failedHours = (int) \App\Models\SystemSetting::value('maintenance.failed_jobs_retention_days', 30) * 24;
Schedule::command("queue:prune-failed --hours={$failedHours}")->dailyAt('03:15');

// Job batches — срок хранения настраивается (Обслуживание), фолбэк 7 дней.
$batchHours = (int) \App\Models\SystemSetting::value('maintenance.job_batch_retention_days', 7) * 24;
Schedule::command("queue:prune-batches --hours={$batchHours} --unfinished=24")
    ->dailyAt('03:20');

// Журнал интеграций (integration_events): хранить 90 дней. Таблица растёт
// на ~1000 событий/день — за год это гигабайты, в основном бесполезные.
Schedule::call(function () {
    $days = (int) \App\Models\SystemSetting::value('maintenance.integration_events_retention_days', 90);
    \Illuminate\Support\Facades\DB::table('integration_events')
        ->where('created_at', '<', now()->subDays($days))
        ->delete();
})->dailyAt('03:30')->name('integration-events:prune');

// Mail log — хранить 1 год. Старые email-рассылки больше не нужны
// (для compliance достаточно chat_ticket_changes).
Schedule::call(function () {
    if (\Illuminate\Support\Facades\Schema::hasTable('mail_log')) {
        $days = (int) \App\Models\SystemSetting::value('maintenance.mail_log_retention_days', 365);
        \Illuminate\Support\Facades\DB::table('mail_log')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
})->monthlyOn(1, '03:45')->name('mail-log:prune');

// История health-check — хранить N дней (настройка «Обслуживание», фолбэк 14).
// health:check идёт каждую минуту (три команды ниже), поэтому таблица растёт
// на ~4 тыс. строк в сутки. Ретеншн формально был в db:housekeep, но та
// зарегистрирована без --apply, т.е. не чистила ничего. Здесь только DELETE
// данных — никаких дропов таблиц, автозапуску это доверить можно.
Schedule::call(function () {
    if (! \Illuminate\Support\Facades\Schema::hasTable('health_check_result_history_items')) {
        return;
    }
    $days = (int) \App\Models\SystemSetting::value('maintenance.health_history_retention_days', 14);
    \Illuminate\Support\Facades\DB::table('health_check_result_history_items')
        ->where('created_at', '<', now()->subDays(max(1, $days)))
        ->delete();
})->dailyAt('03:35')->name('health-history:prune');

// Прогноз начисления (contract.accrual_forecast) — НЕ финансовый расчёт,
// а производное поле: месяц активации + N (из продукта) либо факт по
// транзакции. Ночной пересчёт подхватывает появившиеся транзакции и статусы.
// Под бан авто-пересчётов (2026-06-05) не попадает — деньги не двигает.
Schedule::command('contracts:recompute-accrual-forecast')->dailyAt('03:50');

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

// Крон-задачи модуля «Задачи» (tasks:remind-deadlines, tasks:recurring)
// удалены вместе с самим модулем 2026-08-14.
