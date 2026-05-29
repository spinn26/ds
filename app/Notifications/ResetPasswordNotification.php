<?php

namespace App\Notifications;

use App\Services\MailTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Кастомное уведомление о сбросе пароля. Подставляет ссылку на
 * фронт-страницу /reset-password?token=…&email=…, а не на дефолтный
 * Laravel `/password/reset/{token}` (которого у SPA нет).
 *
 * Базовый URL берётся из config('app.frontend_url') → fallback
 * config('app.url'). Запись в mail_log делает RecordMailLog listener
 * на событиях MessageSending/MessageSent — здесь только проставляем
 * mail_type и user_id служебными X-DS-* заголовками.
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
        $userId = (int) $notifiable->getKey();
        $baseUrl = rtrim(config('app.frontend_url') ?: config('app.url') ?: 'http://localhost', '/');
        $url = "{$baseUrl}/reset-password?token={$this->token}&email=" . urlencode($recipient);
        $logoUrl = "{$baseUrl}/email/ds-logo.png";

        // Срок жизни токена — из config/auth.php (passwords.users.expire,
        // в минутах). Дефолт Laravel 60 мин.
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $subject = 'Восстановление пароля — DS Consulting';

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
            )
            ->withSymfonyMessage(function ($email) use ($userId) {
                app(MailTracker::class)->headers($email, [
                    'mail_type' => 'password_reset',
                    'user_id' => $userId,
                ]);
            });
    }
}
