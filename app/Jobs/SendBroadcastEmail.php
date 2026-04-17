<?php

namespace App\Jobs;

use App\Services\MailSettingsService;
use App\Services\MailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one email as part of a broadcast. Runs per recipient so a failure
 * for one person doesn't abort the whole batch. The broadcast id groups
 * all per-recipient jobs so the admin UI can poll progress.
 */
class SendBroadcastEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $backoff = 15;

    public function __construct(
        public string $broadcastId,
        public int $senderId,
        public int $recipientUserId,
        public string $subject,
        public string $body,
        public bool $isHtml,
    ) {}

    public function handle(MailSettingsService $settings, MailTemplateRenderer $renderer): void
    {
        if (! $settings->applyRuntimeConfig()) {
            $this->logFail(null, 'SMTP-настройки не заполнены');
            return;
        }

        $user = DB::table('WebUser')->where('id', $this->recipientUserId)
            ->first(['id', 'firstName', 'lastName', 'patronymic', 'email', 'phone']);

        if (! $user || ! filter_var($user->email ?? '', FILTER_VALIDATE_EMAIL)) {
            $this->logFail($user->email ?? '—', 'Нет валидного email');
            return;
        }

        // Load consultant + qualification for variable substitution
        $ctx = $renderer->batchContext([$this->recipientUserId])[$this->recipientUserId] ?? null;
        $subject = $renderer->render($this->subject, $user, $ctx['consultant'] ?? null, $ctx['qualification'] ?? null);
        $body = $renderer->render($this->body, $user, $ctx['consultant'] ?? null, $ctx['qualification'] ?? null);

        try {
            Mail::send([], [], function ($msg) use ($user, $subject, $body) {
                $msg->to($user->email)->subject($subject);
                if ($this->isHtml) {
                    $msg->html($body);
                } else {
                    $msg->text($body);
                }
            });
            $this->logEntry($user->email, 'sent', null, $subject, $body);
        } catch (\Throwable $e) {
            Log::warning('Broadcast email failed', [
                'broadcast' => $this->broadcastId,
                'user' => $this->recipientUserId,
                'error' => $e->getMessage(),
            ]);
            $this->logEntry($user->email, 'failed', $e->getMessage(), $subject, $body);
            throw $e; // let the queue retry up to $tries
        }
    }

    private function logEntry(string $email, string $status, ?string $error, string $subject, string $body): void
    {
        DB::table('mail_log')->insert([
            'broadcast_id' => $this->broadcastId,
            'sender_id' => $this->senderId,
            'recipient_email' => $email,
            'recipient_user_id' => $this->recipientUserId,
            'subject' => $subject,
            'body' => mb_substr($body, 0, 65000),
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
            'sent_at' => $status === 'sent' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function logFail(?string $email, string $reason): void
    {
        DB::table('mail_log')->insert([
            'broadcast_id' => $this->broadcastId,
            'sender_id' => $this->senderId,
            'recipient_email' => $email ?: '—',
            'recipient_user_id' => $this->recipientUserId,
            'subject' => $this->subject,
            'body' => mb_substr($this->body, 0, 65000),
            'status' => 'failed',
            'error' => $reason,
            'sent_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
