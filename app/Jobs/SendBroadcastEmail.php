<?php

namespace App\Jobs;

use App\Listeners\RecordMailLog;
use App\Services\MailSettingsService;
use App\Services\MailTemplateRenderer;
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
 * Sends one email as part of a broadcast. Runs per recipient so a failure
 * for one person doesn't abort the whole batch. The broadcast id groups
 * all per-recipient jobs so the admin UI can poll progress.
 *
 * Запись в mail_log делает RecordMailLog listener на MessageSending/Sent
 * (sent / pending / delivery_status). Здесь только проставляем X-DS-*
 * заголовки и ловим transport-exceptions через recordFailure().
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
        public ?int $mailboxId = null,
    ) {}

    public function handle(MailSettingsService $settings, MailTemplateRenderer $renderer, MailTracker $tracker): void
    {
        if (! $settings->applyRuntimeConfig($this->mailboxId)) {
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

        $tid = (string) Str::uuid();

        try {
            Mail::send([], [], function ($msg) use ($user, $subject, $body, $tid, $tracker) {
                $msg->to($user->email)->subject($subject);
                if ($this->isHtml) {
                    $msg->html($body);
                } else {
                    $msg->text($body);
                }
                $tracker->headers($msg->getSymfonyMessage(), [
                    'tracking_id' => $tid,
                    'mail_type' => 'broadcast',
                    'user_id' => $this->recipientUserId,
                    'sender_id' => $this->senderId,
                    'broadcast_id' => $this->broadcastId,
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('Broadcast email failed', [
                'broadcast' => $this->broadcastId,
                'user' => $this->recipientUserId,
                'error' => $e->getMessage(),
            ]);
            RecordMailLog::recordFailure(
                recipientEmail: $user->email,
                trackingId: $tid,
                subject: $subject,
                userId: $this->recipientUserId,
                senderId: $this->senderId,
                broadcastId: $this->broadcastId,
                mailType: 'broadcast',
                error: $e->getMessage(),
            );
            throw $e; // let the queue retry up to $tries
        }
    }

    /**
     * SMTP-настройки не заполнены / нет email — Mail::send даже не
     * вызовется, listener не сработает, поэтому пишем failure вручную.
     */
    private function logFail(?string $email, string $reason): void
    {
        RecordMailLog::recordFailure(
            recipientEmail: $email ?: '—',
            trackingId: null,
            subject: $this->subject,
            userId: $this->recipientUserId,
            senderId: $this->senderId,
            broadcastId: $this->broadcastId,
            mailType: 'broadcast',
            error: $reason,
        );
    }
}
