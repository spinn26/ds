<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastEmail;
use App\Services\MailSettingsService;
use App\Services\MailTemplateRenderer;
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
    public function test(Request $request): JsonResponse
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

        try {
            Mail::raw("Тестовое сообщение от DS Consulting.\nОтправлено: " . now()->toIso8601String(),
                fn ($msg) => $msg->to($data['to'])->subject('DS Consulting — проверка SMTP')
            );
            $this->logEntry($request, $data['to'], null, 'DS Consulting — проверка SMTP', 'Test', 'sent', null);

            return response()->json(['message' => 'Тестовое письмо отправлено']);
        } catch (\Throwable $e) {
            $this->logEntry($request, $data['to'], null, 'DS Consulting — проверка SMTP', 'Test', 'failed', $e->getMessage());
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
        $counts = DB::table('mail_log')
            ->where('broadcast_id', $broadcastId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        return response()->json([
            'broadcast_id' => $broadcastId,
            'sent' => (int) ($counts['sent'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
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

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $s = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($s) {
                $q->where('recipient_email', 'ilike', $s)
                  ->orWhere('subject', 'ilike', $s);
            });
        }

        $total = $query->count();
        $rows = $query->orderByDesc('id')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        return response()->json(['data' => $rows, 'total' => $total]);
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

    private function logEntry(Request $request, string $email, ?int $userId, string $subject, string $body, string $status, ?string $error): void
    {
        DB::table('mail_log')->insert([
            'sender_id' => $request->user()?->id,
            'recipient_email' => $email,
            'recipient_user_id' => $userId,
            'subject' => $subject,
            'body' => mb_substr($body, 0, 65000),
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
            'sent_at' => $status === 'sent' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
