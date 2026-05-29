<?php

namespace App\Services;

use Symfony\Component\Mime\Email;
use Illuminate\Support\Str;

/**
 * Помечает исходящие письма служебными X-DS-* заголовками, которые
 * RecordMailLogListener вытащит после фактической отправки и сложит
 * в mail_log.
 *
 * Также готовит HTML-тело для tracking pixel + click-wrapper:
 *  - вставляет 1×1 пиксель `<img>` перед `</body>` (или в конце документа);
 *  - оборачивает все http(s)-ссылки в редирект через /mt/c/{tid}?u=…
 *
 * Использовать в каждом месте, где формируется письмо:
 *   $tid = $tracker->headers($message, [
 *       'mail_type' => 'password_reset',
 *       'user_id'   => $user->id,
 *   ]);
 *   $html = $tracker->wrapHtml($html, $tid);
 *
 * Headers с префиксом X-DS- удаляются перед отправкой, чтобы внутренний
 * контекст не попал получателю.
 */
class MailTracker
{
    public const HEADER_TRACKING_ID = 'X-DS-Tracking-Id';
    public const HEADER_MAIL_TYPE = 'X-DS-Mail-Type';
    public const HEADER_USER_ID = 'X-DS-User-Id';
    public const HEADER_SENDER_ID = 'X-DS-Sender-Id';
    public const HEADER_BROADCAST_ID = 'X-DS-Broadcast-Id';

    /**
     * Проставить служебные заголовки на письмо. Возвращает сгенерированный
     * (или переданный) tracking_id.
     *
     * @param array{
     *   tracking_id?: string,
     *   mail_type?: string,
     *   user_id?: int|null,
     *   sender_id?: int|null,
     *   broadcast_id?: string|null,
     * } $ctx
     */
    public function headers(Email $email, array $ctx = []): string
    {
        $tid = $ctx['tracking_id'] ?? (string) Str::uuid();
        $h = $email->getHeaders();

        $h->addTextHeader(self::HEADER_TRACKING_ID, $tid);
        if (! empty($ctx['mail_type'])) {
            $h->addTextHeader(self::HEADER_MAIL_TYPE, (string) $ctx['mail_type']);
        }
        if (! empty($ctx['user_id'])) {
            $h->addTextHeader(self::HEADER_USER_ID, (string) $ctx['user_id']);
        }
        if (! empty($ctx['sender_id'])) {
            $h->addTextHeader(self::HEADER_SENDER_ID, (string) $ctx['sender_id']);
        }
        if (! empty($ctx['broadcast_id'])) {
            $h->addTextHeader(self::HEADER_BROADCAST_ID, (string) $ctx['broadcast_id']);
        }

        // Заранее проставляем Message-ID с правильным доменом из From.
        // По умолчанию Symfony Mime генерит «xxx@example.com» (если
        // hostname не сконфигурен) — Gmail/Mail.ru за такой mismatch
        // между Message-ID domain и From domain снижают spam score.
        // Делаем это до отправки, до листенера — Symfony уважает уже
        // установленный пользовательский Message-ID и не перегенерит его.
        $this->ensureMessageId($email);

        return $tid;
    }

    public function ensureMessageId(Email $email): void
    {
        $h = $email->getHeaders();
        $current = $h->has('Message-ID') ? $h->get('Message-ID')->getBodyAsString() : '';
        if ($current !== '' && ! str_contains($current, '@example.com')) {
            return;
        }

        $domain = $this->fromDomain($email);
        if (! $domain) return;

        $newMid = bin2hex(random_bytes(16)) . '@' . $domain;
        if ($h->has('Message-ID')) {
            $h->remove('Message-ID');
        }
        $h->addIdHeader('Message-ID', $newMid);
    }

    private function fromDomain(Email $email): ?string
    {
        foreach ($email->getFrom() as $addr) {
            $a = $addr->getAddress();
            $pos = strrpos($a, '@');
            if ($pos !== false) return substr($a, $pos + 1);
        }
        // Fallback на config('mail.from.address') если From пуст
        // (бывает у Mail::raw до того как mailer.send добавит default-from).
        $fromCfg = (string) config('mail.from.address', '');
        $pos = strrpos($fromCfg, '@');
        return $pos !== false ? substr($fromCfg, $pos + 1) : null;
    }

    /**
     * Удалить служебные X-DS-* заголовки — чтобы внутренний контекст не
     * утёк получателю. Вызывается в MessageSent listener'е ПОСЛЕ того,
     * как метаданные уже собраны.
     */
    public function stripInternalHeaders(Email $email): void
    {
        $h = $email->getHeaders();
        foreach ([
            self::HEADER_TRACKING_ID,
            self::HEADER_MAIL_TYPE,
            self::HEADER_USER_ID,
            self::HEADER_SENDER_ID,
            self::HEADER_BROADCAST_ID,
        ] as $name) {
            $h->remove($name);
        }
    }

    /**
     * Обернуть HTML-тело письма tracking-пикселем и click-wrapper'ами на
     * всех `<a href="http...">`. base() — публичный URL сайта.
     */
    public function wrapHtml(string $html, string $trackingId, ?string $base = null): string
    {
        $base = rtrim($base ?: (string) config('app.url'), '/');
        if ($base === '' || $trackingId === '') {
            return $html;
        }

        // 1) Все ссылки → редирект через /mt/c/{tid}?u=base64url.
        //    Не трогаем mailto:, tel:, anchor (#…) и уже-tracked URL.
        $wrap = function (array $m) use ($base, $trackingId) {
            $orig = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! preg_match('~^https?://~i', $orig)) return $m[0];
            if (str_starts_with($orig, "{$base}/mt/")) return $m[0];

            $encoded = rtrim(strtr(base64_encode($orig), '+/', '-_'), '=');
            $wrapped = "{$base}/mt/c/{$trackingId}?u={$encoded}";
            return $m[1] . htmlspecialchars($wrapped, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $m[3];
        };
        $html = preg_replace_callback(
            '~(<a\b[^>]*?\shref\s*=\s*")([^"]+)(")~i',
            $wrap,
            $html
        );

        // 2) Tracking pixel. Вставляем прямо перед </body>; если </body>
        //    нет — в конец документа.
        $pixel = '<img src="' . $base . '/mt/o/' . $trackingId . '.gif"'
            . ' width="1" height="1" alt=""'
            . ' style="display:block;width:1px;height:1px;border:0;outline:none;" />';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('~</body>~i', "{$pixel}</body>", $html, 1);
        }
        return "{$html}{$pixel}";
    }
}
