<?php

namespace App\Listeners;

use App\Services\MailTracker;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Слушает Laravel Mail events и пишет в mail_log РЕАЛЬНЫЙ статус
 * отправки SMTP, а не предположение.
 *
 * Логика:
 *  - MessageSending — вставляем строку delivery_status='pending' с
 *    tracking_id (генерим если нет), читаем X-DS-* служебные заголовки.
 *  - MessageSent — обновляем delivery_status='sent', sent_at, message_id.
 *    Перед уходом письма удаляем X-DS-* заголовки.
 *  - Failure (поднятый exception в try/catch отправителя) обрабатывается
 *    отдельно вызывающим кодом — старые try/catch в SendBroadcastEmail /
 *    AdminMailController::test остаются на местах, они обновляют запись
 *    по tracking_id (или вставляют новую, если её не было).
 *
 * MessageSendingFailed event'а в Laravel 11 нет — Symfony Mailer
 * бросает TransportExceptionInterface, который ловится вызывающим
 * кодом. Listener успешные/sending — этого хватает для 95% случаев.
 */
class RecordMailLog
{
    public function __construct(private readonly MailTracker $tracker) {}

    public function handleSending(MessageSending $event): void
    {
        try {
            $email = $event->message;
            $meta = $this->extractMeta($email);

            // Если tracking_id не выставлен заранее — генерим его прямо
            // сейчас и проставляем заголовок, чтобы MessageSent смог
            // подцепить запись.
            if (empty($meta['tracking_id'])) {
                $tid = (string) Str::uuid();
                $email->getHeaders()->addTextHeader(MailTracker::HEADER_TRACKING_ID, $tid);
                $meta['tracking_id'] = $tid;
            }

            // Перебиваем Message-ID на правильный домен (=domain отправителя).
            // По умолчанию Symfony Mime генерит «xxx@example.com» когда
            // hostname не задан — Gmail/Mail.ru за это занижают spam-score.
            // Корректный Message-ID должен содержать domain из From.
            $fromDomain = $this->extractFromDomain($email);
            if ($fromDomain) {
                $h = $email->getHeaders();
                $current = $h->has('Message-ID') ? $h->get('Message-ID')->getBodyAsString() : '';
                if ($current === '' || str_contains($current, '@example.com')) {
                    $newMid = bin2hex(random_bytes(16)) . '@' . $fromDomain;
                    if ($h->has('Message-ID')) {
                        $h->remove('Message-ID');
                    }
                    $h->addIdHeader('Message-ID', $newMid);
                }
            }

            $to = $this->primaryRecipient($email);
            if ($to === null) {
                // Письмо без получателей — Symfony отвалится сам, но мы
                // даже строку логировать не будем (нечего).
                return;
            }

            // Идемпотентность: если запись с этим tracking_id уже есть
            // (например AdminMailController сам её создал ДО Mail::send) —
            // не дублируем, только дополним недостающие поля.
            $existing = DB::table('mail_log')
                ->where('tracking_id', $meta['tracking_id'])
                ->value('id');

            $row = [
                'tracking_id' => $meta['tracking_id'],
                'mailer' => $this->detectMailer($email),
                'from_address' => $this->fromAddress($email),
                'recipient_email' => $to,
                'recipient_user_id' => $meta['user_id'],
                'sender_id' => $meta['sender_id'],
                'broadcast_id' => $meta['broadcast_id'],
                'subject' => mb_substr((string) $email->getSubject(), 0, 250),
                'mail_type' => $meta['mail_type'],
                'body' => mb_substr($this->bodyExcerpt($email), 0, 65000),
                'status' => 'sent', // legacy-колонка, обновится в MessageSent
                'delivery_status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($existing) {
                // Не перетираем то что уже могло быть проставлено вручную.
                DB::table('mail_log')->where('id', $existing)->update([
                    'mailer' => $row['mailer'],
                    'from_address' => $row['from_address'],
                    'mail_type' => $row['mail_type'] ?: DB::raw('mail_type'),
                    'delivery_status' => 'pending',
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('mail_log')->insert($row);
            }

            // Уровень 3 — tracking pixel + click-wrapper.
            // Делаем централизованно в listener'е, чтобы не загромождать
            // каждый шаблон. Plain-text копия остаётся как есть — пиксель
            // в текст вставлять смысла нет, ссылки в тексте люди и так
            // редко кликают мышью.
            $html = $email->getHtmlBody();
            if ($html !== null && $html !== '') {
                $wrapped = $this->tracker->wrapHtml((string) $html, $meta['tracking_id']);
                $email->html($wrapped);
            }
        } catch (\Throwable $e) {
            // Лог-листенер сам не должен ронять отправку письма.
            Log::warning('RecordMailLog::handleSending failed: ' . $e->getMessage());
        }
    }

    public function handleSent(MessageSent $event): void
    {
        try {
            $email = $event->message;
            $tid = $this->trackingIdFromHeaders($email);
            if ($tid === null) return;

            $messageId = $this->messageIdFromSent($event->sent ?? null, $email);
            $debug = $this->debugFromSent($event->sent ?? null);

            DB::table('mail_log')
                ->where('tracking_id', $tid)
                ->update([
                    'status' => 'sent',
                    'delivery_status' => 'sent',
                    'sent_at' => now(),
                    'message_id' => $messageId,
                    'smtp_response' => $debug ? mb_substr($debug, -2000) : null,
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            // Снимаем служебные X-DS-* заголовки. Это уже не повлияет на
            // отправленное письмо (оно ушло), но если transport кэширует
            // объект (mailable preview / queue retry) — лучше очистить.
            $this->tracker->stripInternalHeaders($email);
        } catch (\Throwable $e) {
            Log::warning('RecordMailLog::handleSent failed: ' . $e->getMessage());
        }
    }

    /**
     * Внешний хук — вызывать из catch-блока отправляющего кода
     * (SendBroadcastEmail, AdminMailController::test, etc.), когда
     * Symfony Mailer бросил TransportException. Помечает запись
     * статусом 'failed' и пишет текст ошибки.
     *
     * Если tracking_id неизвестен (например письмо упало ДО события
     * MessageSending) — вставляет новую failure-строку.
     */
    public static function recordFailure(
        string $recipientEmail,
        ?string $trackingId,
        ?string $subject,
        ?int $userId,
        ?int $senderId,
        ?string $broadcastId,
        ?string $mailType,
        string $error,
    ): void {
        try {
            if ($trackingId) {
                $updated = DB::table('mail_log')->where('tracking_id', $trackingId)->update([
                    'status' => 'failed',
                    'delivery_status' => 'failed',
                    'error' => mb_substr($error, 0, 2000),
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);
                if ($updated) return;
            }

            // Нет записи (или нет tid) — создаём failure-строку с нуля.
            DB::table('mail_log')->insert([
                'tracking_id' => $trackingId ?: (string) Str::uuid(),
                'recipient_email' => $recipientEmail ?: '—',
                'recipient_user_id' => $userId,
                'sender_id' => $senderId,
                'broadcast_id' => $broadcastId,
                'subject' => mb_substr((string) $subject, 0, 250),
                'mail_type' => $mailType,
                'status' => 'failed',
                'delivery_status' => 'failed',
                'error' => mb_substr($error, 0, 2000),
                'attempts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('RecordMailLog::recordFailure failed: ' . $e->getMessage());
        }
    }

    // ===== helpers =====

    private function extractMeta(Email $email): array
    {
        $h = $email->getHeaders();
        $get = fn (string $name) => $h->has($name) ? $h->get($name)->getBodyAsString() : null;

        return [
            'tracking_id' => $get(MailTracker::HEADER_TRACKING_ID),
            'mail_type' => $get(MailTracker::HEADER_MAIL_TYPE),
            'user_id' => $this->intOrNull($get(MailTracker::HEADER_USER_ID)),
            'sender_id' => $this->intOrNull($get(MailTracker::HEADER_SENDER_ID)),
            'broadcast_id' => $get(MailTracker::HEADER_BROADCAST_ID),
        ];
    }

    private function intOrNull(?string $v): ?int
    {
        return ($v !== null && $v !== '' && ctype_digit(trim($v))) ? (int) trim($v) : null;
    }

    private function trackingIdFromHeaders(Email $email): ?string
    {
        $h = $email->getHeaders();
        return $h->has(MailTracker::HEADER_TRACKING_ID)
            ? $h->get(MailTracker::HEADER_TRACKING_ID)->getBodyAsString()
            : null;
    }

    private function primaryRecipient(Email $email): ?string
    {
        foreach ($email->getTo() as $addr) {
            return $addr->getAddress();
        }
        return null;
    }

    private function fromAddress(Email $email): ?string
    {
        foreach ($email->getFrom() as $addr) {
            return $addr->getAddress();
        }
        return null;
    }

    private function extractFromDomain(Email $email): ?string
    {
        $addr = $this->fromAddress($email);
        if (! $addr) return null;
        $pos = strrpos($addr, '@');
        return $pos !== false ? substr($addr, $pos + 1) : null;
    }

    private function detectMailer(Email $email): string
    {
        // Symfony Mailer не привязан к Laravel mailer-имени напрямую.
        // Берём то, что сейчас в config('mail.default').
        return (string) config('mail.default', 'smtp');
    }

    /**
     * Excerpt тела: предпочитаем text/plain (короче, без HTML-мусора),
     * fallback на html — обрезанные ~64KB пишутся в mail_log.body.
     */
    private function bodyExcerpt(Email $email): string
    {
        $text = (string) $email->getTextBody();
        if ($text !== '') return $text;

        $html = (string) $email->getHtmlBody();
        // Strip HTML tags для preview — оригинал HTML всё равно ушёл получателю.
        return $html !== '' ? strip_tags($html) : '';
    }

    private function messageIdFromSent(?SentMessage $sent, Email $email): ?string
    {
        // ПРИОРИТЕТ — headers email: это тот Message-ID, который реально
        // ушёл получателю и который видит spam-фильтр. Symfony's
        // SentMessage::getMessageId() возвращает transport-level token
        // (например Yandex SMTP отвечает «250 Ok: queued as ABC» — и
        // Symfony положит туда «queued» или «ABC»). Это не то что
        // получатель видит в Message-ID заголовке письма.
        $h = $email->getHeaders();
        if ($h->has('Message-ID')) {
            $mid = trim($h->get('Message-ID')->getBodyAsString(), " \t<>");
            if ($mid !== '') return mb_substr($mid, 0, 250);
        }
        // Fallback на SentMessage. Illuminate\Mail\SentMessage проксирует
        // getMessageId() через __call() к Symfony — method_exists() здесь
        // возвращает false, проверку делать нельзя. Прямой вызов.
        if ($sent !== null) {
            try {
                $mid = (string) $sent->getMessageId();
                if ($mid !== '' && $mid !== 'queued') return mb_substr($mid, 0, 250);
            } catch (\Throwable) {
                // ignore
            }
        }
        return null;
    }

    private function debugFromSent(?SentMessage $sent): ?string
    {
        if ($sent === null) return null;
        try {
            $d = (string) $sent->getDebug();
            return $d !== '' ? $d : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
