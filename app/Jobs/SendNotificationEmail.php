<?php

namespace App\Jobs;

use App\Listeners\RecordMailLog;
use App\Services\MailSettingsService;
use App\Services\MailTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Дублирование уведомления платформы на e-mail.
 *
 * Зачем: у партнёров Telegram привязан у единиц (3 из 112 на 27.08.2026), а
 * почта есть у всех. Уведомление, которое видно только в кабинете, доходит
 * лишь до тех, кто в кабинет заходит — а предупреждать надо как раз тех, кто
 * не заходит.
 *
 * ⚠ Через очередь, а не синхронно: `NotificationController::create()`
 * вызывается прямо в запросах (запись выплаты, смена реквизитов), и ждать там
 * SMTP нельзя. Очередь на проде живая, воркеров два.
 *
 * ⚠ SMTP-настройки лежат в `mail_settings`, а не в `.env` — без
 * `applyRuntimeConfig()` Mail::send уходит в дефолтный мейлер и падает.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * Плашка в шапке письма: [подпись, цвет текста, фон]. Чтобы «Терминация» и
     * «Пул зафиксирован» не выглядели одинаково — по цвету видно, срочное это
     * или информационное.
     *
     * @var array<string, array{0:string,1:string,2:string}>
     */
    private const STYLES = [
        'status'     => ['Статус',    '#C62828', '#FDECEA'],
        'payment'    => ['Выплаты',   '#2E7D32', '#E8F5E9'],
        'requisites' => ['Реквизиты', '#0277BD', '#E3F2FD'],
        'import'     => ['Импорт',    '#5A6B5C', '#EEF1EE'],
        'ticket'     => ['Обращение', '#0277BD', '#E3F2FD'],
        'mail'       => ['Почта',     '#5A6B5C', '#EEF1EE'],
        'system'     => ['Система',   '#ED6C02', '#FFF4E5'],
    ];

    public function __construct(
        public int $userId,
        public string $type,
        public string $title,
        public ?string $message = null,
        public ?string $link = null,
    ) {}

    public function handle(MailSettingsService $settings, MailTracker $tracker): void
    {
        if (! $settings->applyRuntimeConfig()) {
            Log::warning('Notification email skipped: SMTP не настроен', ['user' => $this->userId]);

            return;
        }

        $user = DB::table('WebUser')->where('id', $this->userId)
            ->first(['id', 'email', 'firstName', 'lastName', 'patronymic']);

        if (! $user || ! filter_var($user->email ?? '', FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $name = trim(implode(' ', array_filter([
            $user->lastName ?? null, $user->firstName ?? null, $user->patronymic ?? null,
        ])));
        $greeting = $name !== '' ? "Здравствуйте, {$name}!" : 'Здравствуйте!';

        $subject = 'DS Consulting: ' . $this->title;
        // frontend_url первым — как в ResetPasswordNotification: письмо должно
        // вести туда же, куда ведёт восстановление пароля.
        $base = rtrim(config('app.frontend_url') ?: config('app.url') ?: '', '/');
        $url = $this->link ? $base . '/' . ltrim($this->link, '/') : $base;

        [$typeLabel, $accent, $accentBg] = self::STYLES[$this->type] ?? self::STYLES['system'];

        $data = [
            'subject' => $subject,
            'title' => $this->title,
            // ⚠ НЕ 'message': Laravel подмешивает в данные письма свой
            // $message (Illuminate\Mail\Message) и затирает нашу переменную —
            // Blade получал объект вместо строки и падал на htmlspecialchars().
            'bodyText' => $this->message,
            'greeting' => $greeting,
            'url' => $url,
            'logoUrl' => $base . '/email/ds-logo.png',
            'typeLabel' => $typeLabel,
            'accent' => $accent,
            'accentBg' => $accentBg,
        ];

        $tid = (string) Str::uuid();

        try {
            // Двусоставное письмо (html + text): Gmail и Yandex прямо
            // рекомендуют multipart/alternative, оно снижает spam-score.
            Mail::send(
                ['emails.notification', 'emails.notification-text'],
                $data,
                function ($msg) use ($user, $subject, $tid, $tracker) {
                    $msg->to($user->email)->subject($subject);
                    $tracker->headers($msg->getSymfonyMessage(), [
                        'tracking_id' => $tid,
                        'mail_type' => 'notification:' . $this->type,
                        'user_id' => $this->userId,
                    ]);
                }
            );
        } catch (\Throwable $e) {
            Log::warning('Notification email failed', [
                'user' => $this->userId, 'type' => $this->type, 'error' => $e->getMessage(),
            ]);
            RecordMailLog::recordFailure(
                recipientEmail: (string) $user->email,
                trackingId: $tid,
                subject: $subject,
                userId: $this->userId,
                senderId: null,
                broadcastId: null,
                mailType: 'notification:' . $this->type,
                error: $e->getMessage(),
            );
            throw $e; // пусть очередь повторит
        }
    }
}
