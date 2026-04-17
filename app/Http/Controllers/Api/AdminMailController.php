<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Services\MailSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminMailController extends Controller
{
    use PaginatesRequests;

    public function __construct(
        private readonly MailSettingsService $mailSettings,
    ) {}

    /**
     * Load current SMTP settings (password masked on the way out).
     */
    public function settings(): JsonResponse
    {
        $s = $this->mailSettings->current();

        return response()->json($s ? [
            'host' => $s->host,
            'port' => $s->port,
            'username' => $s->username,
            'hasPassword' => ! empty($s->password),
            'encryption' => $s->encryption,
            'from_address' => $s->from_address,
            'from_name' => $s->from_name,
            'updated_at' => $s->updated_at,
        ] : [
            'host' => null, 'port' => 587, 'username' => null, 'hasPassword' => false,
            'encryption' => 'tls', 'from_address' => null, 'from_name' => null,
            'updated_at' => null,
        ]);
    }

    /**
     * Save SMTP settings. Empty password keeps the previous value.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'string', 'in:tls,ssl,null'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);

        if (($data['encryption'] ?? null) === 'null') {
            $data['encryption'] = null;
        }

        // Empty password: keep the existing one
        if (empty($data['password'])) {
            $existing = $this->mailSettings->current();
            $data['password'] = $existing->password ?? null;
        }

        $this->mailSettings->save($data);

        return response()->json(['message' => 'Настройки сохранены']);
    }

    /**
     * Send a test email to a specified address using the stored SMTP config.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
        ]);

        if (! $this->mailSettings->applyRuntimeConfig()) {
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
     * Broadcast email to a target audience of WebUsers.
     * Audience rules:
     *   - all: all users with a non-empty email
     *   - active: linked consultants with activity = Active
     *   - ids: explicit list of WebUser IDs
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
        ]);

        if (! $this->mailSettings->applyRuntimeConfig()) {
            return response()->json([
                'message' => 'SMTP-настройки не заполнены (host / from_address)',
            ], 422);
        }

        $recipients = $this->buildAudience($data['audience'], $data['ids'] ?? []);
        if ($recipients->isEmpty()) {
            return response()->json(['message' => 'Нет получателей'], 422);
        }

        $sent = 0;
        $failed = 0;
        $isHtml = (bool) ($data['is_html'] ?? true);

        foreach ($recipients as $r) {
            if (! filter_var($r->email, FILTER_VALIDATE_EMAIL)) {
                $this->logEntry($request, $r->email ?? '—', $r->id, $data['subject'], $data['body'], 'failed', 'Invalid email');
                $failed++;
                continue;
            }

            try {
                Mail::send([], [], function ($msg) use ($r, $data, $isHtml) {
                    $msg->to($r->email)->subject($data['subject']);
                    if ($isHtml) {
                        $msg->html($data['body']);
                    } else {
                        $msg->text($data['body']);
                    }
                });
                $this->logEntry($request, $r->email, $r->id, $data['subject'], $data['body'], 'sent', null);
                $sent++;
            } catch (\Throwable $e) {
                $this->logEntry($request, $r->email, $r->id, $data['subject'], $data['body'], 'failed', $e->getMessage());
                $failed++;
            }
        }

        return response()->json([
            'message' => "Отправлено: {$sent}, не доставлено: {$failed}",
            'sent' => $sent,
            'failed' => $failed,
            'total' => $sent + $failed,
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
