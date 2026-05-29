<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        $baseUrl = rtrim(config('app.frontend_url') ?: config('app.url') ?: 'http://localhost', '/');
        $email = urlencode($notifiable->getEmailForPasswordReset());
        $url = "{$baseUrl}/reset-password?token={$this->token}&email={$email}";

        // Срок жизни токена — из config/auth.php (passwords.users.expire,
        // в минутах). Дефолт Laravel 60 мин.
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Восстановление пароля — DS Consulting')
            ->greeting('Здравствуйте!')
            ->line('Вы запросили сброс пароля для своего аккаунта на платформе DS Consulting.')
            ->action('Установить новый пароль', $url)
            ->line("Ссылка действительна {$expireMinutes} минут.")
            ->line('Если вы не запрашивали сброс — просто проигнорируйте это письмо, ваш пароль останется прежним.')
            ->salutation('С уважением, команда DS Consulting');
    }
}
