<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Кастомное уведомление о сбросе пароля. Подставляет ссылку на
 * фронт-страницу /reset-password?token=…&email=…, а не на дефолтный
 * Laravel `/password/reset/{token}` (которого у SPA нет).
 *
 * Базовый URL берётся из config('app.frontend_url') → fallback
 * config('app.url'). Email-шаблон — стандартный MailMessage (не
 * нужен отдельный blade).
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $recipient = $notifiable->getEmailForPasswordReset();
        $baseUrl = rtrim(config('app.frontend_url') ?: config('app.url') ?: 'http://localhost', '/');
        $url = "{$baseUrl}/reset-password?token={$this->token}&email=" . urlencode($recipient);
        $logoUrl = "{$baseUrl}/email/ds-logo.png";

        // Срок жизни токена — из config/auth.php (passwords.users.expire,
        // в минутах). Дефолт Laravel 60 мин.
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $subject = 'Восстановление пароля — DS Consulting';

        // Логируем отправку в mail_log (та же таблица, что показывается
        // в /admin/mail → «Журнал»). Идемпотентность не нужна — Password
        // broker сам тротлит повторные отправки.
        try {
            DB::table('mail_log')->insert([
                'recipient_email' => $recipient,
                'recipient_user_id' => $notifiable->getKey(),
                'subject' => $subject,
                'body' => "Reset link: {$url}",
                'status' => 'sent',
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('mail_log insert failed (password reset)', [
                'recipient' => $recipient, 'error' => $e->getMessage(),
            ]);
        }

        // Кастомный HTML-шаблон resources/views/emails/reset-password.blade.php
        // (бренд DS: тёмно-зелёный header, логотип, кнопка primary). Plain
        // text-версия отдельно — двусоставное письмо снижает spam-score
        // (Gmail/Yandex прямо рекомендуют multipart/alternative).
        return (new MailMessage)
            ->subject($subject)
            ->view(
                ['emails.reset-password', 'emails.reset-password-text'],
                [
                    'subject' => $subject,
                    'url' => $url,
                    'logoUrl' => $logoUrl,
                    'expireMinutes' => $expireMinutes,
                ]
            );
    }
}
