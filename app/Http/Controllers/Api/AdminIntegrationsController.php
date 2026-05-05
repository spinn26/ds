<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiSettingsService;
use App\Services\IntegrationLogger;
use App\Services\InsmartIntegrationService;
use App\Services\TelegramNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Полноценная панель «Интеграции».
 *
 * Объединяет:
 *   - список сервисов с health-метриками за 24ч (success-rate, latency,
 *     last event)
 *   - журнал событий с фильтрами и пагинацией
 *   - кнопка «Тест соединения» для каждого сервиса (real call)
 *   - replay входящего webhook'а (по сохранённому payload)
 *   - чтение/запись credentials через ApiSettingsService (безопасное
 *     хранилище с шифрованием)
 *
 * Все «тесты» сами пишут в integration_events — оператор видит итог
 * прямо в журнале. Никаких mock-ответов.
 */
class AdminIntegrationsController extends Controller
{
    /** Каталог сервисов. label/icon/настройки — для UI. */
    private const SERVICES = [
        'insmart' => [
            'label' => 'Insmart',
            'icon' => 'mdi-cash-fast',
            'category' => 'incoming',
            'settings' => ['insmart.api_base_url', 'insmart.api_key', 'insmart.webhook_secret'],
        ],
        'google_sheets' => [
            'label' => 'Google Sheets',
            'icon' => 'mdi-google-spreadsheet',
            'category' => 'data',
            'settings' => ['google.sheets.api_key', 'google.sheets.contracts_id',
                           'google.sheets.transactions_id', 'google.sheets.reference_id'],
        ],
        'telegram' => [
            'label' => 'Telegram Bot',
            'icon' => 'mdi-send',
            'category' => 'notify',
            'settings' => ['telegram.bot.token', 'telegram.status.chat_id', 'telegram.staff.chat_id'],
        ],
        'smtp' => [
            'label' => 'SMTP / Email',
            'icon' => 'mdi-email-outline',
            'category' => 'notify',
            'settings' => [], // SMTP лежит в отдельной таблице mail_settings
        ],
        'socket_io' => [
            'label' => 'Socket.IO',
            'icon' => 'mdi-broadcast',
            'category' => 'realtime',
            'settings' => [], // адрес в .env (SOCKET_HOST/SOCKET_API_PORT)
        ],
        'zammad' => [
            'label' => 'Zammad Helpdesk',
            'icon' => 'mdi-headset',
            'category' => 'incoming',
            'settings' => ['zammad.base_url', 'zammad.token', 'zammad.webhook_secret'],
        ],
    ];

    public function __construct(
        private readonly IntegrationLogger $logger,
        private readonly ApiSettingsService $settings,
    ) {}

    /**
     * GET /admin/integrations
     *
     * Сервисы + метрики 24h: success/error count, success-rate, p95 latency,
     * last event с timestamp.
     */
    public function index(): JsonResponse
    {
        $since = CarbonImmutable::now()->subDay();

        $stats = DB::table('integration_events')
            ->where('created_at', '>=', $since)
            ->selectRaw("
                service,
                COUNT(*) FILTER (WHERE status = 'success') AS success_cnt,
                COUNT(*) FILTER (WHERE status = 'error')   AS error_cnt,
                COUNT(*) FILTER (WHERE status = 'pending') AS pending_cnt,
                COUNT(*)                                    AS total,
                PERCENTILE_DISC(0.95) WITHIN GROUP (ORDER BY duration_ms)
                    AS p95_ms,
                AVG(duration_ms)::int                       AS avg_ms,
                MAX(created_at)                             AS last_at
            ")
            ->groupBy('service')
            ->get()->keyBy('service');

        $services = [];
        foreach (self::SERVICES as $key => $meta) {
            $row = $stats[$key] ?? null;
            $total = (int) ($row->total ?? 0);
            $success = (int) ($row->success_cnt ?? 0);
            $rate = $total > 0 ? round($success / $total * 100, 1) : null;
            $services[] = [
                'key' => $key,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'category' => $meta['category'],
                'configured' => $this->isConfigured($key),
                'metrics_24h' => [
                    'total' => $total,
                    'success' => $success,
                    'errors' => (int) ($row->error_cnt ?? 0),
                    'pending' => (int) ($row->pending_cnt ?? 0),
                    'success_rate' => $rate,
                    'avg_ms' => $row->avg_ms ?? null,
                    'p95_ms' => $row->p95_ms ?? null,
                    'last_at' => $row->last_at ?? null,
                ],
            ];
        }

        return response()->json(['services' => $services]);
    }

    /**
     * GET /admin/integrations/events
     *
     * Журнал событий с фильтрами: service, direction, status, q (по summary
     * или external_id), date_from/to. Пагинация.
     */
    public function events(Request $request): JsonResponse
    {
        $q = DB::table('integration_events');

        if ($s = $request->input('service')) $q->where('service', $s);
        if ($d = $request->input('direction')) $q->where('direction', $d);
        if ($st = $request->input('status')) $q->where('status', $st);
        if ($search = trim((string) $request->input('q', ''))) {
            $q->where(function ($w) use ($search) {
                $w->where('summary', 'ilike', "%{$search}%")
                  ->orWhere('external_id', 'ilike', "%{$search}%")
                  ->orWhere('action', 'ilike', "%{$search}%");
            });
        }
        if ($from = $request->input('date_from')) $q->where('created_at', '>=', $from);
        if ($to = $request->input('date_to')) $q->where('created_at', '<=', $to);

        $page = max(1, (int) $request->input('page', 1));
        $per = min(100, max(10, (int) $request->input('per', 25)));

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('created_at')->forPage($page, $per)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'service' => $r->service,
                'direction' => $r->direction,
                'action' => $r->action,
                'status' => $r->status,
                'summary' => $r->summary,
                'external_id' => $r->external_id,
                'duration_ms' => $r->duration_ms,
                'ip' => $r->ip,
                'created_at' => $r->created_at,
            ]),
            'total' => $total,
        ]);
    }

    /** GET /admin/integrations/events/{id} — payload запроса/ответа. */
    public function eventShow(int $id): JsonResponse
    {
        $row = DB::table('integration_events')->where('id', $id)->first();
        if (! $row) abort(404);

        return response()->json([
            'id' => $row->id,
            'service' => $row->service,
            'direction' => $row->direction,
            'action' => $row->action,
            'status' => $row->status,
            'summary' => $row->summary,
            'external_id' => $row->external_id,
            'duration_ms' => $row->duration_ms,
            'ip' => $row->ip,
            'request' => $this->parseJson($row->request),
            'response' => $this->parseJson($row->response),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ]);
    }

    /**
     * POST /admin/integrations/events/{id}/replay
     *
     * Повторно выполнить inbound-обработчик с сохранённым request payload.
     * Сейчас поддержан Insmart paid-webhook (extensible per service).
     */
    public function replay(int $id, InsmartIntegrationService $insmart): JsonResponse
    {
        $row = DB::table('integration_events')->where('id', $id)->first();
        if (! $row) abort(404);
        if ($row->direction !== 'inbound') {
            return response()->json(['message' => 'Replay поддержан только для inbound-событий'], 422);
        }
        $payload = $this->parseJson($row->request);
        if (! $payload) {
            return response()->json(['message' => 'Нет сохранённого payload для replay'], 422);
        }

        $event = $this->logger->begin($row->service, 'inbound', "{$row->action}_replay",
            request()->ip(), request()->user()?->id, $row->external_id, $payload);

        try {
            $result = match ($row->service) {
                'insmart' => $insmart->handlePaidWebhook($payload),
                default => throw new \RuntimeException('Replay для этого сервиса не реализован'),
            };
            $this->logger->finish($event, 'success', 'Replayed by admin', $result, $row->external_id);
            return response()->json(['message' => 'Replay выполнен', 'result' => $result]);
        } catch (Throwable $e) {
            $this->logger->finish($event, 'error', 'Replay failed: ' . $e->getMessage(), null, $row->external_id);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/integrations/{service}/test
     *
     * Реальная проверка конкретного сервиса. Каждое тестовое действие
     * пишется в журнал — оператор увидит результат во вкладке «Журнал».
     */
    public function test(Request $request, string $service): JsonResponse
    {
        if (! isset(self::SERVICES[$service])) abort(404);

        $event = $this->logger->begin($service, 'outbound', 'test_connection',
            $request->ip(), $request->user()?->id);

        try {
            [$ok, $summary, $details] = match ($service) {
                'insmart' => $this->testInsmart(),
                'google_sheets' => $this->testGoogleSheets(),
                'telegram' => $this->testTelegram(),
                'smtp' => $this->testSmtp($request),
                'socket_io' => $this->testSocket(),
                default => [false, 'Тест не реализован', []],
            };
            $this->logger->finish($event, $ok ? 'success' : 'error', $summary, $details);
            return response()->json(['ok' => $ok, 'summary' => $summary, 'details' => $details]);
        } catch (Throwable $e) {
            $this->logger->finish($event, 'error', $e->getMessage());
            return response()->json(['ok' => false, 'summary' => $e->getMessage()], 500);
        }
    }

    /** GET /admin/integrations/{service}/config — текущие значения настроек. */
    public function config(string $service): JsonResponse
    {
        $meta = self::SERVICES[$service] ?? abort(404);
        $values = [];
        foreach ($meta['settings'] as $key) {
            $val = $this->settings->get($key);
            $values[$key] = [
                'value' => $this->maskSecret($key, $val),
                'has_value' => $val !== null && $val !== '',
            ];
        }
        return response()->json(['service' => $service, 'settings' => $values]);
    }

    /** PUT /admin/integrations/{service}/config — сохранить настройки. */
    public function saveConfig(Request $request, string $service): JsonResponse
    {
        $meta = self::SERVICES[$service] ?? abort(404);
        $data = $request->validate([
            'settings' => ['required', 'array'],
        ])['settings'];

        foreach ($data as $key => $value) {
            if (! in_array($key, $meta['settings'], true)) continue;
            $this->settings->set($key, $value === '' ? null : (string) $value);
        }

        $this->logger->record($service, 'outbound', 'update_config', 'success',
            'Настройки обновлены', null, ['updated_keys' => array_keys($data)],
            actorId: $request->user()?->id);

        return response()->json(['message' => 'Сохранено']);
    }

    // ──────────── private: тесты ────────────

    private function testInsmart(): array
    {
        $base = $this->settings->get('insmart.api_base_url');
        $key = $this->settings->get('insmart.api_key');
        $secret = $this->settings->get('insmart.webhook_secret');
        $missing = [];
        if (! $base) $missing[] = 'api_base_url';
        if (! $key) $missing[] = 'api_key';
        if (! $secret) $missing[] = 'webhook_secret';
        if ($missing) {
            return [false, 'Не заданы: ' . implode(', ', $missing), ['missing' => $missing]];
        }
        try {
            // Простой ping — есть ли сервер по base_url, без авторизации.
            $res = Http::timeout(5)->withToken($key)->get(rtrim((string) $base, '/') . '/health');
            return [$res->successful(), "HTTP {$res->status()}", ['status' => $res->status()]];
        } catch (Throwable $e) {
            return [false, $e->getMessage(), []];
        }
    }

    private function testGoogleSheets(): array
    {
        $apiKey = $this->settings->get('google.sheets.api_key');
        $sheetId = $this->settings->get('google.sheets.contracts_id') ?: $this->settings->get('google.sheets.transactions_id');
        if (! $apiKey || ! $sheetId) {
            return [false, 'API key или Sheet ID не заданы', []];
        }
        try {
            $url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}?key={$apiKey}";
            $res = Http::timeout(5)->get($url);
            $title = $res->json('properties.title') ?? '—';
            return [$res->successful(), "HTTP {$res->status()}, title=«{$title}»",
                ['status' => $res->status(), 'title' => $title]];
        } catch (Throwable $e) {
            return [false, $e->getMessage(), []];
        }
    }

    private function testTelegram(): array
    {
        $tg = app(TelegramNotifier::class);
        if (! $tg->isConfigured()) {
            return [false, 'Bot token или chat_id не заданы', []];
        }
        $ok = $tg->send('🟢 Тест из админ-панели в ' . now()->toDateTimeString());
        return [$ok, $ok ? 'Сообщение отправлено' : 'Ошибка отправки', []];
    }

    private function testSmtp(Request $request): array
    {
        $email = $request->input('email') ?: $request->user()?->email;
        if (! $email) return [false, 'Не указан адрес для теста', []];
        try {
            Mail::raw('Тест SMTP из платформы — ' . now(), function ($m) use ($email) {
                $m->to($email)->subject('SMTP Test');
            });
            return [true, "Письмо отправлено на {$email}", ['email' => $email]];
        } catch (Throwable $e) {
            return [false, $e->getMessage(), []];
        }
    }

    private function testSocket(): array
    {
        $host = env('SOCKET_HOST', '127.0.0.1');
        $port = env('SOCKET_API_PORT', 3002);
        try {
            $res = Http::timeout(2)->get("http://{$host}:{$port}/health");
            $data = $res->json() ?? [];
            return [$res->successful(), "HTTP {$res->status()}", $data];
        } catch (Throwable $e) {
            return [false, $e->getMessage(), []];
        }
    }

    // ──────────── private: helpers ────────────

    private function isConfigured(string $key): bool
    {
        return match ($key) {
            'insmart' => (bool) $this->settings->get('insmart.api_key')
                && (bool) $this->settings->get('insmart.webhook_secret'),
            'google_sheets' => (bool) $this->settings->get('google.sheets.api_key'),
            'telegram' => app(TelegramNotifier::class)->isConfigured(),
            'smtp' => (bool) DB::table('mail_settings')->first(),
            'socket_io' => true, // всегда включён, валидация — через test
            default => false,
        };
    }

    private function maskSecret(string $key, ?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        // Маскируем явно секретные поля в ответе UI.
        $isSecret = str_contains($key, 'secret') || str_contains($key, 'token')
            || str_contains($key, 'api_key') || str_contains($key, 'password');
        if (! $isSecret) return $value;
        $len = mb_strlen($value);
        return $len <= 8 ? str_repeat('•', $len) : (mb_substr($value, 0, 4) . str_repeat('•', $len - 8) . mb_substr($value, -4));
    }

    private function parseJson(?string $raw): ?array
    {
        if ($raw === null || $raw === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
