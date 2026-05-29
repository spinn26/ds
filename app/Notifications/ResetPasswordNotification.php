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

        // Срок жизни токена — из config/auth.php (passwords.users.expire,
        // в минутах). Дефолт Laravel 60 мин.
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $subject = 'Восстановление пароля — DS Consulting';

        // Логируем отправку в mail_log (та же таблица, что показывается
        // в /admin/mail → «Журнал»). Если SMTP-send упадёт после этого,
        // exception всплывёт вверх, и в логе останется запись status=sent —
        // что само по себе сигнал «ушло, но не дошло» при разборе кейса.
        // Идемпотентность не нужна (Password broker сам не дублирует
        // отправки в пределах throttle-окна).
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

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Здравствуйте!')
            ->line('Вы запросили сброс пароля для своего аккаунта на платформе DS Consulting.')
            ->action('Установить новый пароль', $url)
            ->line("Ссылка действительна {$expireMinutes} минут.")
            ->line('Если вы не запрашивали сброс — проигнорируйте это письмо, пароль останется прежним.')
            ->salutation('С уважением, команда DS Consulting');
    }
}
