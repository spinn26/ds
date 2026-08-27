<?php

namespace App\Console\Commands;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Api\NotificationController;
use App\Listeners\RecordMailLog;
use App\Services\MailSettingsService;
use App\Services\MailTracker;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Предупреждение партнёру за N дней до терминации — в кабинет и в Telegram.
 *
 * ⚠ Команда НИЧЕГО НЕ СЧИТАЕТ и не меняет статусы: только рассылает. Поэтому
 * она стоит в расписании, хотя авто-пересчёты на платформе отключены
 * (см. routes/console.php) — запрет касается расчётов, а не уведомлений.
 * Терминирует по-прежнему `partners:check-statuses` по кнопке.
 *
 * Почему не переиспользована готовая рассылка в `partners:check-statuses`
 * (`sendRegistrationDeadlineWarnings`): она пишет в `platformCommunication`,
 * а не в `notifications`, поэтому В TELEGRAM НЕ УХОДИТ ВООБЩЕ. И условие там
 * «дедлайн в пределах месяца» без отметки об отправке — при ежедневном запуске
 * это тридцать одинаковых сообщений подряд каждому.
 *
 * Терминация наступает по двум разным срокам, оба ведут к расторжению договора:
 *   • «Зарегистрирован» — не набрал ЛП за окно активации (`activationDeadline`);
 *   • «Активен» — не набрал ЛП за годовой период (`yearPeriodEnd`).
 * Предупреждаем по обоим.
 */
class NotifyTerminationSoon extends Command
{
    protected $signature = 'partners:notify-termination-soon
        {--days=30 : за сколько дней до срока предупреждать}
        {--no-email : не слать письмо, только кабинет и Telegram}
        {--dry-run : показать кому уйдёт, ничего не отправляя}';

    protected $description = 'Предупредить партнёров о скорой терминации (кабинет + Telegram)';

    public function handle(MailSettingsService $mailSettings, MailTracker $tracker): int
    {
        $days = max(1, (int) $this->option('days'));
        $withEmail = ! $this->option('no-email');
        $dry = (bool) $this->option('dry-run');
        $points = PartnerActivity::activationPoints();

        $today = Carbon::today();
        $until = $today->copy()->addDays($days);

        $rows = DB::table('consultant as c')
            ->join('WebUser as wu', 'wu.id', '=', 'c.webUser')
            ->whereNull('c.dateDeleted')
            ->where(function ($q) {
                $q->where('c.personalVolume', '<', PartnerActivity::activationPoints())
                  ->orWhereNull('c.personalVolume');
            })
            ->where(function ($q) {
                // Оба срока, ведущих к терминации.
                $q->where(function ($r) {
                    $r->where('c.activity', PartnerActivity::Registered->value)
                      ->whereNotNull('c.activationDeadline');
                })->orWhere(function ($r) {
                    $r->where('c.activity', PartnerActivity::Active->value)
                      ->whereNotNull('c.yearPeriodEnd');
                });
            })
            ->selectRaw('c.id, c."personName", c.activity, c."webUser", c."personalVolume",
                c.termination_warning_for,
                CASE WHEN c.activity = ? THEN c."activationDeadline"::date
                     ELSE c."yearPeriodEnd"::date END AS deadline,
                wu.telegram_chat_id, wu.email', [PartnerActivity::Registered->value])
            ->get()
            ->filter(fn ($r) => $r->deadline !== null
                && $r->deadline >= $today->toDateString()
                && $r->deadline <= $until->toDateString())
            // Уже предупреждали ПОД ЭТОТ дедлайн — молчим.
            ->filter(fn ($r) => (string) $r->termination_warning_for !== (string) $r->deadline)
            ->values();

        if ($rows->isEmpty()) {
            $this->info("Некого предупреждать: нет партнёров с терминацией в ближайшие {$days} дн.");

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'ФИО', 'статус', 'срок', 'дней', 'ЛП', 'Telegram', 'Почта'],
            $rows->map(fn ($r) => [
                $r->id,
                mb_substr($r->personName ?? '—', 0, 30),
                (int) $r->activity === PartnerActivity::Registered->value ? 'Зарегистрирован' : 'Активен',
                $r->deadline,
                (int) $today->diffInDays(Carbon::parse($r->deadline)),
                round((float) ($r->personalVolume ?? 0), 2),
                $r->telegram_chat_id ? 'да' : '—',
                filter_var($r->email ?? '', FILTER_VALIDATE_EMAIL) ? 'да' : '—',
            ])->all()
        );

        if ($dry) {
            $this->warn('DRY-RUN — ничего не отправлено.');

            return self::SUCCESS;
        }

        // SMTP-настройки лежат в mail_settings, а не в .env — без этого вызова
        // Mail::send уходит в дефолтный мейлер и падает.
        $smtpReady = $withEmail && $mailSettings->applyRuntimeConfig();
        if ($withEmail && ! $smtpReady) {
            $this->warn('SMTP не настроен — письма отправлены не будут, уведомления уйдут только в кабинет.');
        }

        $sent = 0;
        $emailed = 0;
        foreach ($rows as $r) {
            // ⚠ Направление важно: $deadline->diffInDays($today) в Carbon 3
            // знаковый и даёт ОТРИЦАТЕЛЬНОЕ число для будущей даты — в текст
            // уходило бы «До терминации -34 дней».
            $left = (int) $today->diffInDays(Carbon::parse($r->deadline));
            $lp = round((float) ($r->personalVolume ?? 0), 2);

            $title = "До терминации {$left} " . $this->plural($left, 'день', 'дня', 'дней');
            $message = (int) $r->activity === PartnerActivity::Registered->value
                ? "Срок активации истекает {$r->deadline}. Нужно набрать {$points} ЛП, сейчас у вас {$lp}. "
                    . 'Если срок истечёт, агентский договор будет расторгнут, а баллы обнулены.'
                : "Годовой период заканчивается {$r->deadline}. Нужно набрать {$points} ЛП, сейчас у вас {$lp}. "
                    . 'Если срок истечёт, агентский договор будет расторгнут, а баллы обнулены.';

            try {
                NotificationController::create((int) $r->webUser, 'status', $title, $message, '/dashboard');
                DB::table('consultant')->where('id', $r->id)
                    ->update(['termination_warning_for' => $r->deadline]);
                $sent++;
            } catch (\Throwable $e) {
                // Один упавший партнёр не должен ронять всю рассылку.
                Log::warning('Termination warning failed', ['consultant' => $r->id, 'error' => $e->getMessage()]);
                $this->error("ФК {$r->id}: {$e->getMessage()}");
                continue;
            }

            // Письмо — отдельно от отметки: SMTP может лежать, и из-за этого
            // партнёр не должен терять уведомление в кабинете (оно уже ушло).
            if ($withEmail && $smtpReady) {
                $emailed += $this->sendEmail($r, $title, $message, $tracker) ? 1 : 0;
            }
        }

        $withTg = $rows->filter(fn ($r) => $r->telegram_chat_id !== null)->count();
        $this->info("Отправлено уведомлений: {$sent} (в кабинет всем).");
        $this->info("Telegram: {$withTg} — у остальных бот не привязан.");
        if ($withEmail) {
            $this->info($smtpReady
                ? "Писем отправлено: {$emailed}."
                : 'Письма НЕ отправлены: SMTP не настроен (mail_settings).');
        }

        return self::SUCCESS;
    }

    /**
     * Письмо партнёру. Возвращает true, если ушло.
     *
     * ⚠ Шлём синхронно, а не через очередь: рассылка идёт раз в сутки и
     * десятками писем, а не тысячами. Ошибка одного адресата пишется в
     * mail_log и не роняет остальных.
     */
    private function sendEmail(object $r, string $title, string $message, MailTracker $tracker): bool
    {
        $email = (string) ($r->email ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $subject = "DS Consulting: {$title}";
        $name = trim((string) ($r->personName ?? ''));
        $greeting = $name !== '' ? "Здравствуйте, {$name}!" : 'Здравствуйте!';

        $html = '<p>' . e($greeting) . '</p>'
            . '<p><strong>' . e($title) . '</strong></p>'
            . '<p>' . e($message) . '</p>'
            . '<p>Проверить прогресс можно в личном кабинете: '
            . '<a href="https://dev.dsconsult.ru/dashboard">Дашборд</a>.</p>'
            . '<p style="color:#888;font-size:12px">Это автоматическое уведомление платформы DS Consulting.</p>';

        $tid = (string) Str::uuid();

        try {
            Mail::send([], [], function ($msg) use ($email, $subject, $html, $tid, $tracker, $r) {
                $msg->to($email)->subject($subject)->html($html);
                $tracker->headers($msg->getSymfonyMessage(), [
                    'tracking_id' => $tid,
                    'mail_type' => 'termination_warning',
                    'user_id' => (int) $r->webUser,
                ]);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Termination warning email failed', [
                'consultant' => $r->id, 'email' => $email, 'error' => $e->getMessage(),
            ]);
            RecordMailLog::recordFailure(
                recipientEmail: $email,
                trackingId: $tid,
                subject: $subject,
                userId: (int) $r->webUser,
                senderId: null,
                broadcastId: null,
                mailType: 'termination_warning',
                error: $e->getMessage(),
            );
            $this->error("Письмо ФК {$r->id} ({$email}): {$e->getMessage()}");

            return false;
        }
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        if ($n % 10 === 1 && $n % 100 !== 11) return $one;
        if (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true)) return $few;

        return $many;
    }
}
