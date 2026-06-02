<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastEmail;
use App\Listeners\RecordMailLog;
use App\Services\MailSettingsService;
use App\Services\MailTemplateRenderer;
use App\Services\MailTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminMailController extends Controller
{
    use PaginatesRequests;

    public function __construct(
        private readonly MailSettingsService $mailSettings,
        private readonly MailTemplateRenderer $renderer,
    ) {}

    /**
     * Legacy single-mailbox endpoint — отдаёт default-ящик.
     * Сохранён ради backward-compat; новый UI ходит через /mailboxes.
     */
    public function settings(): JsonResponse
    {
        $s = $this->mailSettings->current();

        return response()->json($s ? $this->serializeMailbox($s) : [
            'host' => null, 'port' => 587, 'username' => null, 'hasPassword' => false,
            'encryption' => 'tls', 'from_address' => null, 'from_name' => null,
            'updated_at' => null,
        ]);
    }

    /**
     * Save SMTP settings. Empty password keeps the previous value.
     * Legacy: пишет в default-ящик (или создаёт первый, если ящиков нет).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $this->validateMailbox($request, requireName: false);
        $data['name'] = $data['name'] ?? 'Основной';

        $existing = $this->mailSettings->current();
        if (empty($data['password'])) {
            $data['password'] = $existing->password ?? null;
        }

        $this->mailSettings->save($existing?->id, $data);

        return response()->json(['message' => 'Настройки сохранены']);
    }

    // ===== MAILBOXES (multi-SMTP) =====

    /**
     * Список SMTP-ящиков. Default-ящик идёт первым.
     */
    public function mailboxes(): JsonResponse
    {
        $list = $this->mailSettings->list()->map(fn ($m) => $this->serializeMailbox($m));
        return response()->json(['data' => $list->values()]);
    }

    public function storeMailbox(Request $request): JsonResponse
    {
        $data = $this->validateMailbox($request, requireName: true);
        $row = $this->mailSettings->save(null, $data);

        return response()->json($this->serializeMailbox($row), 201);
    }

    public function updateMailbox(Request $request, int $id): JsonResponse
    {
        $existing = $this->mailSettings->find($id);
        if (! $existing) return response()->json(['message' => 'Ящик не найден'], 404);

        $data = $this->validateMailbox($request, requireName: true);
        // Пустой пароль = не менять (стандартный паттерн админ-форм).
        if (empty($data['password'])) {
            $data['password'] = $existing->password;
        }

        $row = $this->mailSettings->save($id, $data);
        return response()->json($this->serializeMailbox($row));
    }

    public function destroyMailbox(int $id): JsonResponse
    {
        return $this->mailSettings->delete($id)
            ? response()->json(['ok' => true])
            : response()->json(['message' => 'Ящик не найден'], 404);
    }

    public function setDefaultMailbox(int $id): JsonResponse
    {
        return $this->mailSettings->setDefault($id)
            ? response()->json(['ok' => true])
            : response()->json(['message' => 'Ящик не найден'], 404);
    }

    /**
     * Единая валидация для create/update/legacy-update.
     * $requireName=true для новых ящиков, false для legacy-settings.
     */
    private function validateMailbox(Request $request, bool $requireName): array
    {
        $rules = [
            'name' => [$requireName ? 'required' : 'nullable', 'string', 'max:120'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'string', 'in:tls,ssl,null'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ];
        $data = $request->validate($rules);

        if (($data['encryption'] ?? null) === 'null') {
            $data['encryption'] = null;
        }

        return $data;
    }

    private function serializeMailbox(object $s): array
    {
        return [
            'id' => $s->id ?? null,
            'name' => $s->name ?? null,
            'host' => $s->host,
            'port' => $s->port,
            'username' => $s->username,
            'hasPassword' => ! empty($s->password),
            'encryption' => $s->encryption,
            'from_address' => $s->from_address,
            'from_name' => $s->from_name,
            'is_default' => (bool) ($s->is_default ?? false),
            'updated_at' => $s->updated_at,
        ];
    }

    /**
     * Send a test email to a specified address using the stored SMTP config.
     */
    public function test(Request $request, MailTracker $tracker): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
            // mailbox_id — какой ящик использовать; null = default.
            'mailbox_id' => ['nullable', 'integer', 'exists:mail_settings,id'],
        ]);

        if (! $this->mailSettings->applyRuntimeConfig($data['mailbox_id'] ?? null)) {
            return response()->json([
                'message' => 'SMTP-настройки не заполнены (host / from_address)',
            ], 422);
        }

        $subject = 'DS Consulting — проверка SMTP';
        $tid = (string) Str::uuid();
        $senderId = (int) ($request->user()?->id ?? 0) ?: null;

        try {
            Mail::raw(
                "Тестовое сообщение от DS Consulting.\nОтправлено: " . now()->toIso8601String(),
                function ($msg) use ($data, $subject, $tid, $tracker, $senderId) {
                    $msg->to($data['to'])->subject($subject);
                    $tracker->headers($msg->getSymfonyMessage(), [
                        'tracking_id' => $tid,
                        'mail_type' => 'smtp_test',
                        'sender_id' => $senderId,
                    ]);
                }
            );

            return response()->json(['message' => 'Тестовое письмо отправлено']);
        } catch (\Throwable $e) {
            RecordMailLog::recordFailure(
                recipientEmail: $data['to'],
                trackingId: $tid,
                subject: $subject,
                userId: null,
                senderId: $senderId,
                broadcastId: null,
                mailType: 'smtp_test',
                error: $e->getMessage(),
            );
            Log::error('Mail test failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Ошибка отправки: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Broadcast email to a target audience. Dispatches one queued job per
     * recipient so the request returns fast even for thousands of emails.
     * Returns a broadcast_id the UI can poll via /admin/mail/broadcast/{id}/progress.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'string', 'in:all,active,ids'],
            'ids' => ['array'],
            'ids.*' => ['integer'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_html' => ['boolean'],
            // С какого ящика слать; null = default.
            'mailbox_id' => ['nullable', 'integer', 'exists:mail_settings,id'],
        ]);

        if (! $this->mailSettings->applyRuntimeConfig($data['mailbox_id'] ?? null)) {
            return response()->json([
                'message' => 'SMTP-настройки не заполнены (host / from_address)',
            ], 422);
        }

        $recipients = $this->buildAudience($data['audience'], $data['ids'] ?? []);
        if ($recipients->isEmpty()) {
            return response()->json(['message' => 'Нет получателей'], 422);
        }

        $broadcastId = (string) Str::uuid();
        $senderId = (int) $request->user()->id;
        $isHtml = (bool) ($data['is_html'] ?? true);
        $mailboxId = $data['mailbox_id'] ?? null;

        foreach ($recipients as $r) {
            SendBroadcastEmail::dispatch(
                $broadcastId,
                $senderId,
                (int) $r->id,
                $data['subject'],
                $data['body'],
                $isHtml,
                $mailboxId,
            );
        }

        return response()->json([
            'message' => 'Рассылка поставлена в очередь',
            'broadcast_id' => $broadcastId,
            'total' => $recipients->count(),
        ]);
    }

    /**
     * Poll progress for a broadcast dispatched above.
     */
    public function broadcastProgress(string $broadcastId): JsonResponse
    {
        // delivery_status — расширенный жизненный цикл (pending/sent/
        // delivered/failed/bounced). 'pending' = в очереди или ждёт SMTP
        // handshake; 'delivered' = sent + получатель открыл (зачитан
        // tracking pixel'ом). Для UI прогресса считаем delivered как
        // подвид sent.
        $counts = DB::table('mail_log')
            ->where('broadcast_id', $broadcastId)
            ->selectRaw('COALESCE(delivery_status, status) as st, COUNT(*) as cnt')
            ->groupBy('st')
            ->pluck('cnt', 'st')
            ->toArray();

        $sent = (int) ($counts['sent'] ?? 0) + (int) ($counts['delivered'] ?? 0);

        return response()->json([
            'broadcast_id' => $broadcastId,
            'sent' => $sent,
            'delivered' => (int) ($counts['delivered'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'bounced' => (int) ($counts['bounced'] ?? 0),
        ]);
    }

    // ===== TEMPLATES =====

    public function templates(Request $request): JsonResponse
    {
        $query = DB::table('mail_templates');
        if ($request->filled('search')) {
            $s = '%' . $request->input('search') . '%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $s)->orWhere('subject', 'ilike', $s));
        }

        return response()->json([
            'data' => $query->orderByDesc('id')->get(),
            'tokens' => MailTemplateRenderer::availableTokens(),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $this->validateTemplate($request);
        $data['created_at'] = now();
        $data['updated_at'] = now();
        $id = DB::table('mail_templates')->insertGetId($data);

        return response()->json(['id' => $id], 201);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $exists = DB::table('mail_templates')->where('id', $id)->exists();
        if (! $exists) return response()->json(['message' => 'Not found'], 404);

        $data = $this->validateTemplate($request);
        $data['updated_at'] = now();
        DB::table('mail_templates')->where('id', $id)->update($data);

        return response()->json(['id' => $id]);
    }

    public function destroyTemplate(int $id): JsonResponse
    {
        $deleted = DB::table('mail_templates')->where('id', $id)->delete();
        return $deleted
            ? response()->json(['ok' => true])
            : response()->json(['message' => 'Not found'], 404);
    }

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_html' => ['boolean'],
        ]);
    }

    /**
     * Paginated send log.
     */
    public function log(Request $request): JsonResponse
    {
        $query = DB::table('mail_log');

        // Старый фильтр `status` оставлен ради backward-compat с UI,
        // который ещё может слать sent/failed. Новый `delivery_status`
        // расширенный (pending/sent/delivered/failed/bounced).
        if ($request->filled('status')) {
            $st = $request->input('status');
            $query->where(function ($q) use ($st) {
                $q->where('delivery_status', $st)->orWhere('status', $st);
            });
        }
        if ($request->filled('delivery_status')) {
            $query->where('delivery_status', $request->input('delivery_status'));
        }
        if ($request->filled('mail_type')) {
            $query->where('mail_type', $request->input('mail_type'));
        }
        if ($request->filled('search')) {
            $s = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($s) {
                $q->where('recipient_email', 'ilike', $s)
                  ->orWhere('subject', 'ilike', $s)
                  ->orWhere('message_id', 'ilike', $s);
            });
        }

        // Сводка по статусам — для chip-фильтров в UI.
        $summary = DB::table('mail_log')
            ->selectRaw('COALESCE(delivery_status, status) as st, COUNT(*) as cnt')
            ->groupBy('st')
            ->pluck('cnt', 'st')
            ->toArray();

        $total = $query->count();
        $rows = $query->orderByDesc('id')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get([
                'id', 'tracking_id', 'recipient_email', 'recipient_user_id',
                'sender_id', 'from_address', 'mailer', 'subject', 'mail_type',
                'status', 'delivery_status', 'error', 'smtp_response',
                'message_id', 'attempts',
                'bounce_reason', 'bounce_code', 'bounced_at',
                'sent_at', 'opened_at', 'opens_count', 'clicked_at',
                'clicks_count', 'last_click_url',
                'broadcast_id', 'created_at',
            ]);

        $this->attachRecipientNames($rows);

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'summary' => $summary,
        ]);
    }

    /**
     * Audience preview — how many recipients will be picked for broadcast.
     */
    public function audiencePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'string', 'in:all,active,ids'],
            'ids' => ['array'],
            'ids.*' => ['integer'],
        ]);

        $count = $this->buildAudience($data['audience'], $data['ids'] ?? [])->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Обогащает строки журнала ФИО получателя из WebUser. По email-адресу
     * понять, кто запросил письмо (например сброс пароля), тяжело — поэтому
     * резолвим имя: сначала по recipient_user_id, для строк без него —
     * фоллбэком по самому email (lower-case match). Batch keyBy без N+1.
     */
    private function attachRecipientNames($rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $userIds = $rows->pluck('recipient_user_id')->filter()->unique()->values();
        $emails = $rows->filter(fn ($r) => empty($r->recipient_user_id) && ! empty($r->recipient_email))
            ->pluck('recipient_email')
            ->map(fn ($e) => mb_strtolower(trim($e)))
            ->filter()
            ->unique()
            ->values();

        $cols = ['id', 'firstName', 'lastName', 'patronymic', 'email'];

        $byId = $userIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $userIds->all())->get($cols)->keyBy('id')
            : collect();

        $byEmail = $emails->isNotEmpty()
            ? DB::table('WebUser')
                ->whereIn(DB::raw('lower(email)'), $emails->all())
                ->orderBy('id')
                ->get($cols)
                ->keyBy(fn ($u) => mb_strtolower(trim((string) $u->email)))
            : collect();

        $fio = function ($u): ?string {
            if (! $u) {
                return null;
            }
            $name = trim(implode(' ', array_filter([
                $u->lastName ?? null,
                $u->firstName ?? null,
                $u->patronymic ?? null,
            ])));

            return $name !== '' ? $name : null;
        };

        $rows->transform(function ($r) use ($byId, $byEmail, $fio) {
            $u = ! empty($r->recipient_user_id) ? ($byId[$r->recipient_user_id] ?? null) : null;
            if (! $u && ! empty($r->recipient_email)) {
                $u = $byEmail[mb_strtolower(trim((string) $r->recipient_email))] ?? null;
            }
            $r->recipient_name = $fio($u);
            $r->recipient_user_id = $r->recipient_user_id ?: ($u->id ?? null);

            return $r;
        });
    }

    private function buildAudience(string $audience, array $ids)
    {
        $q = DB::table('WebUser as u')
            ->whereNotNull('u.email')
            ->where('u.email', '!=', '')
            ->select(['u.id', 'u.email']);

        if ($audience === 'active') {
            $q->join('consultant as c', 'c.webUser', '=', 'u.id')
              ->where('c.activity', \App\Enums\PartnerActivity::Active->value)
              ->whereNull('c.dateDeleted');
        } elseif ($audience === 'ids') {
            if (empty($ids)) return collect();
            $q->whereIn('u.id', $ids);
        }

        return $q->get();
    }

}
