<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Операционные разделы админки:
 *   - calendar:     календарь операций и SLA
 *   - bulkOps:      массовые действия (терминация, пересчёт, exclude)
 *   - triggers:     шаблоны и триггеры уведомлений
 *   - integrations: статус внешних сервисов
 *   - settings:     системные настройки (пороги, константы)
 */
class AdminOpsController extends Controller
{
    /** GET /admin/ops/calendar — дедлайны + SLA-сработки за текущий месяц. */
    public function calendar(): JsonResponse
    {
        $now = now();
        $month = $now->format('Y-m');
        $tasks = [
            [
                'day' => 1, 'title' => 'Импорт транзакций за прошлый месяц',
                'hint' => 'Загрузить выписки от вендоров',
            ],
            [
                'day' => 5, 'title' => 'Preview штрафов + пул',
                'hint' => 'Проверить расчёт перед закрытием',
            ],
            [
                'day' => 7, 'title' => 'Закрыть прошлый период',
                'hint' => 'После утверждения staff'
            ],
            [
                'day' => 10, 'title' => 'Выплаты по реестру',
                'hint' => 'До 10 числа партнёры получают деньги'
            ],
            [
                'day' => 15, 'title' => 'Отчёт для бухгалтерии',
                'hint' => 'Excel-выгрузка «Реестр выплат»'
            ],
        ];

        foreach ($tasks as &$t) {
            $deadline = $now->copy()->startOfMonth()->addDays($t['day'] - 1);
            $t['date'] = $deadline->toDateString();
            $t['overdue'] = $now->gt($deadline);
            $t['daysLeft'] = $now->diffInDays($deadline, false);
        }

        // SLA breaches
        $slaBreaches = [];

        // Акцепт > 3 дней в необработанном состоянии
        $staleAcceptance = Schema::hasTable('partnerAcceptance')
            ? (int) DB::scalar("SELECT COUNT(*) FROM \"partnerAcceptance\" WHERE (accepted = false OR accepted IS NULL) AND \"dateAccepted\" < now() - interval '3 days'")
            : 0;
        if ($staleAcceptance > 0) {
            $slaBreaches[] = ['severity' => 'warning', 'count' => $staleAcceptance,
                'label' => 'Акцепт не проверен более 3 дней',
                'to' => '/manage/acceptance'];
        }

        // Выплаты pending > 7 дней
        $slowPayments = (int) DB::scalar("SELECT COUNT(*) FROM \"consultantPayment\" WHERE status = 1 AND \"paymentDate\" < now() - interval '7 days'");
        if ($slowPayments > 0) {
            $slaBreaches[] = ['severity' => 'error', 'count' => $slowPayments,
                'label' => 'Выплаты в обработке более 7 дней',
                'to' => '/manage/payments'];
        }

        // Незакрытый прошлый период
        $prev = now()->subMonth();
        $unclosed = ! Schema::hasTable('period_closures')
            || ! DB::table('period_closures')
                ->where('year', $prev->year)->where('month', $prev->month)
                ->whereNotNull('closed_at')->whereNull('reopened_at')
                ->exists();
        if ($unclosed && $now->day > 7) {
            $slaBreaches[] = ['severity' => 'warning', 'count' => 1,
                'label' => sprintf('Не закрыт период %02d.%d', $prev->month, $prev->year),
                'to' => sprintf('/manage/periods/%d-%02d', $prev->year, $prev->month)];
        }

        return response()->json([
            'month' => $month,
            'tasks' => $tasks,
            'slaBreaches' => $slaBreaches,
        ]);
    }

    /** POST /admin/ops/bulk/{action} — выполнить массовую операцию. */
    public function bulkRun(Request $request, string $action): JsonResponse
    {
        switch ($action) {
            case 'terminate-expired':
                return $this->terminateExpired($request);
            case 'recalc-period':
                return $this->recalcPeriod($request);
            default:
                return response()->json(['message' => "Unknown action: {$action}"], 404);
        }
    }

    /** GET /admin/ops/bulk — список доступных операций + preview-counts. */
    public function bulkList(): JsonResponse
    {
        // Окно активации — настройка activation.window_days (с 13.08.2026 — 120
        // дней), а не зашитые 90: иначе счётчик просроченных расходится с тем,
        // по чему реально терминирует крон.
        $windowDays = \App\Enums\PartnerActivity::activationDays();
        $expired = (int) DB::scalar(
            "SELECT COUNT(*) FROM consultant
              WHERE \"dateDeleted\" IS NULL
                AND activity = 4
                AND (
                    (\"activationDeadline\" IS NOT NULL AND \"activationDeadline\" < now())
                    OR (\"activationDeadline\" IS NULL AND \"dateCreated\" + make_interval(days => {$windowDays}) < now())
                )"
        );

        return response()->json([
            'actions' => [
                [
                    'key' => 'terminate-expired',
                    'label' => 'Терминировать просроченных «Зарегистрирован»',
                    'hint' => 'Партнёры со статусом 4 (Зарегистрирован) и истёкшими 90 днями.',
                    'targets' => $expired,
                    'color' => 'warning',
                ],
                [
                    'key' => 'recalc-period',
                    'label' => 'Пересчитать комиссии периода',
                    'hint' => 'Прогоняет CommissionCalculator по всем транзакциям периода (dry-run доступен).',
                    'targets' => null,
                    'color' => 'info',
                    'needsPeriod' => true,
                ],
            ],
        ]);
    }

    /**
     * Массовая терминация просроченных «Зарегистрирован».
     *
     * ⚠ Раньше здесь стоял голый `UPDATE consultant SET activity=3`, мимо
     * PartnerStatusService. Мимо проходило всё, что делает штатная терминация:
     * terminationCount не рос (ломались лестница «терминация → исключение» и
     * лимит самовосстановлений), в chageConsultanStatusLog не попадало ничего,
     * а главное — контракты и клиенты НЕ уезжали на ближайшего активного
     * вышестоящего. Контракт оставался висеть на терминированном ФК, а
     * CommissionCalculator таким партнёрам комиссию не начисляет — доля молча
     * оставалась компании вместо перехода наставнику.
     *
     * Теперь на каждого партнёра зовётся PartnerStatusService::terminate(),
     * то есть ровно тот же путь, что у одиночной терминации из карточки.
     */
    private function terminateExpired(Request $request): JsonResponse
    {
        $dryRun = $request->boolean('dryRun', true);

        $ids = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->where('activity', 4)
            ->where(function ($q) {
                $q->whereRaw('"activationDeadline" < now()')
                  ->orWhere(function ($qq) {
                      $qq->whereNull('activationDeadline')
                         ->whereRaw('"dateCreated" + make_interval(days => ?) < now()',
                             [\App\Enums\PartnerActivity::activationDays()]);
                  });
            })
            ->pluck('id');

        if ($dryRun) {
            return response()->json(['dryRun' => true, 'count' => $ids->count()]);
        }

        $service = app(\App\Services\PartnerStatusService::class);
        $reason = 'Массовая терминация: не набрал ЛП='
            . \App\Enums\PartnerActivity::activationPoints() . ' за '
            . \App\Enums\PartnerActivity::activationDays() . ' дней';

        $done = 0;
        $failed = [];
        foreach ($ids as $id) {
            $consultant = \App\Models\Consultant::find($id);
            if (! $consultant) {
                continue;
            }
            try {
                $service->terminate($consultant, $reason);
                $done++;
            } catch (\Throwable $e) {
                // Один сбойный партнёр не должен ронять всю пачку — собираем
                // и отдаём оператору, остальных дорабатываем.
                $failed[] = ['id' => (int) $id, 'error' => $e->getMessage()];
                \Log::warning('Массовая терминация: партнёр не обработан', [
                    'consultant' => (int) $id, 'error' => $e->getMessage(),
                ]);
            }
        }

        NotificationController::notifyStaff(
            'status',
            sprintf('Массовая терминация: %d партнёров', $done),
            'Просроченная активация по окну ' . \App\Enums\PartnerActivity::activationDays() . ' дней',
            '/manage/partners/statuses',
        );

        return response()->json([
            'dryRun' => false,
            'count' => $done,
            'failed' => $failed,
            'message' => "Терминировано {$done} партнёров"
                . ($failed ? ', с ошибкой: ' . count($failed) : ''),
        ]);
    }

    private function recalcPeriod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
            'dryRun' => 'nullable|boolean',
        ]);
        $period = sprintf('%04d-%02d', $data['year'], $data['month']);

        // Safety net: actually running the recalc has big blast radius;
        // surface the command to run, don't execute from HTTP.
        return response()->json([
            'message' => 'Команда для запуска: php scripts/commissions-recalc.php ' . $period
                       . ($data['dryRun'] ?? true ? '' : ' --apply'),
            'period' => $period,
            'dryRun' => (bool) ($data['dryRun'] ?? true),
        ]);
    }

    /** GET /admin/ops/triggers — шаблоны и сработки. */
    public function triggers(): JsonResponse
    {
        // Система триггеров не реализована — возвращаем каталог предлагаемых.
        // Когда появится таблица notification_triggers, возьмём оттуда.
        $catalog = [
            ['event' => 'partner.activated', 'label' => 'Партнёр активирован (500 ЛП)',
             'channels' => ['email', 'tg'], 'enabled' => false],
            ['event' => 'partner.activation_deadline_7d', 'label' => 'До окончания активации 7 дней',
             'channels' => ['email'], 'enabled' => false],
            ['event' => 'qualification.new', 'label' => 'Новая квалификация',
             'channels' => ['email', 'tg', 'inApp'], 'enabled' => false],
            ['event' => 'payment.sent', 'label' => 'Выплата отправлена',
             'channels' => ['email'], 'enabled' => false],
            ['event' => 'contract.status_changed', 'label' => 'Смена статуса контракта',
             'channels' => ['inApp'], 'enabled' => false],
            ['event' => 'period.closed', 'label' => 'Период закрыт (staff-оповещение)',
             'channels' => ['email', 'inApp'], 'enabled' => false],
        ];

        return response()->json(['catalog' => $catalog]);
    }

    /** GET /admin/ops/integrations — статус внешних сервисов. */
    public function integrations(): JsonResponse
    {
        $services = [];

        // Socket.IO — try HTTP emit endpoint
        $socket = $this->pingHttp('http://127.0.0.1:3002/health');
        $services[] = ['key' => 'socket', 'label' => 'Socket.IO', 'host' => '127.0.0.1:3002',
            'status' => $socket['ok'] ? 'up' : 'down', 'details' => $socket['msg']];

        // Postgres
        try {
            $dbName = DB::connection()->getDatabaseName();
            $pgVer = (string) DB::scalar('SHOW server_version');
            $services[] = ['key' => 'postgres', 'label' => 'PostgreSQL', 'host' => $dbName,
                'status' => 'up', 'details' => "v{$pgVer}"];
        } catch (\Throwable $e) {
            $services[] = ['key' => 'postgres', 'label' => 'PostgreSQL',
                'status' => 'down', 'details' => $e->getMessage()];
        }

        // Redis (if configured)
        try {
            $val = Cache::store(config('cache.default'))->get('__ping_' . now()->timestamp, null);
            $services[] = ['key' => 'cache', 'label' => 'Cache (' . config('cache.default') . ')',
                'status' => 'up', 'details' => 'Cache store reachable'];
        } catch (\Throwable $e) {
            $services[] = ['key' => 'cache', 'label' => 'Cache',
                'status' => 'down', 'details' => $e->getMessage()];
        }

        // Google Sheets — check if API key present (DB settings → env fallback)
        $gsKey = app(\App\Services\ApiSettingsService::class)->get('google.sheets.api_key');
        $services[] = ['key' => 'gsheets', 'label' => 'Google Sheets API',
            'status' => $gsKey ? 'up' : 'disabled',
            'details' => $gsKey ? 'API-key задан в /admin/api-keys' : 'API-key не настроен'];

        // Telegram bot
        $tgNotifier = app(\App\Services\TelegramNotifier::class);
        $services[] = ['key' => 'telegram', 'label' => 'Telegram Bot', 'host' => 'api.telegram.org',
            'status' => $tgNotifier->isConfigured() ? 'up' : 'disabled',
            'details' => $tgNotifier->isConfigured()
                ? 'token + chat_id заданы'
                : 'token или chat_id не заданы — /admin/api-keys'];

        return response()->json(['services' => $services]);
    }

    private function pingHttp(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 1]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['ok' => $code >= 200 && $code < 500, 'msg' => $code ? "HTTP {$code}" : 'unreachable'];
    }

    /** GET /admin/ops/settings — текущие значения системных констант. */
    public function settingsShow(): JsonResponse
    {
        $settings = [
            [
                'key' => 'activation.minLp',
                'label' => 'Порог активации (ЛП)',
                'value' => 500, 'source' => 'App\\Enums\\PartnerActivity / hardcoded',
                'editable' => false,
            ],
            [
                'key' => 'activation.window_days',
                'label' => 'Окно активации (дни)',
                'value' => \App\Enums\PartnerActivity::activationDays(),
                'source' => 'system_settings (business)',
                'editable' => false,
            ],
            [
                'key' => 'detachment.threshold',
                'label' => 'Порог отрыва (6-10 ур.)',
                'value' => '70%', 'source' => 'MonthlyFinaliser::DETACHMENT_THRESHOLD',
                'editable' => false,
            ],
            [
                'key' => 'detachment.penalty',
                'label' => 'Штраф за отрыв',
                'value' => '×0.5', 'source' => 'MonthlyFinaliser',
                'editable' => false,
            ],
            [
                'key' => 'op.penalty',
                'label' => 'Штраф ОП-недобор',
                'value' => '×0.8', 'source' => 'MonthlyFinaliser::OP_PENALTY',
                'editable' => false,
            ],
            [
                'key' => 'pool.percent',
                'label' => 'Процент пула',
                'value' => '1%', 'source' => 'PoolCalculator::POOL_PERCENT',
                'editable' => false,
            ],
            [
                'key' => 'vat.currentRate',
                'label' => 'Текущий НДС',
                'value' => (string) (DB::scalar('SELECT value FROM vat WHERE "dateFrom" <= now() AND "dateTo" >= now() ORDER BY "dateFrom" DESC LIMIT 1') ?? 5) . '%',
                'source' => 'vat table',
                'editable' => false,
                'hint' => 'Редактируется через /admin/references или /admin/currencies',
            ],
        ];

        return response()->json(['settings' => $settings]);
    }

    /** POST /admin/ops/cache/clear — очистка кэшей приложения. */
    public function clearCache(Request $request): JsonResponse
    {
        $target = $request->input('target', 'app');
        $map = [
            'app' => 'cache:clear',
            'config' => 'config:clear',
            'route' => 'route:clear',
            'view' => 'view:clear',
        ];
        if (! isset($map[$target])) {
            return response()->json(['message' => 'Неизвестная цель'], 422);
        }

        \Illuminate\Support\Facades\Artisan::call($map[$target]);
        // Доп. сброс известных in-memory карт (на случай иного стора).
        if ($target === 'app') {
            Cache::forget('calculator:product-matrix:v4');
            Cache::forget('system_settings:map');
            Cache::forget('feature_flags:map');
        }

        return response()->json([
            'message' => 'Кэш очищен: ' . $map[$target],
            'output' => trim(\Illuminate\Support\Facades\Artisan::output()),
        ]);
    }

    /** GET /admin/ops/scheduled — список задач планировщика (read-only). */
    public function scheduledTasks(): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('schedule:list');
            $output = \Illuminate\Support\Facades\Artisan::output();
        } catch (\Throwable $e) {
            $output = 'Не удалось получить список: ' . $e->getMessage();
        }

        return response()->json(['output' => trim($output)]);
    }
}
