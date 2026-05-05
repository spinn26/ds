<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PeriodFreezeService;
use App\Services\PoolRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Пул: preview + moderation toggles + apply.
 *
 * Flow (spec ✅Пул.md):
 *   1. GET  /admin/pool/participants?year=&month=   список кандидатов
 *      с чекбоксами «Участвует».
 *   2. PUT  /admin/pool/participants               переключить чекбокс.
 *   3. POST /admin/pool/preview                    посчитать без записи.
 *   4. POST /admin/pool/apply                      пересохранить poolLog.
 */
class AdminPoolController extends Controller
{
    public function __construct(
        private readonly PoolRunner $runner,
        private readonly PeriodFreezeService $freeze,
    ) {}

    /** GET /admin/pool/participants */
    public function participants(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $result = $this->runner->run($year, $month, applyWrite: false);

        // Метаданные о заморозке — для UI: показываем плашку «Зафиксировано»
        // и прячем кнопку фиксации, если период уже закрыт.
        $closure = DB::table('period_closures')
            ->where('year', $year)->where('month', $month)
            ->whereNull('reopened_at')
            ->select('closed_at', 'closed_by', 'note')
            ->first();
        $result['frozen'] = (bool) $closure;
        if ($closure) {
            $closedByName = $closure->closed_by
                ? DB::table('WebUser')->where('id', $closure->closed_by)->value('lastName')
                : null;
            $result['closure'] = [
                'closed_at' => $closure->closed_at,
                'closed_by' => $closure->closed_by,
                'closed_by_name' => $closedByName,
                'note' => $closure->note,
            ];
        }
        return response()->json($result);
    }

    /**
     * PUT /admin/pool/participants
     * Переключить чекбокс «Участвует» для (consultant, year, month).
     */
    public function toggleParticipant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
            'consultant' => 'required|integer|exists:consultant,id',
            'participates' => 'required|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        DB::table('pool_moderation')->updateOrInsert(
            [
                'year' => $data['year'],
                'month' => $data['month'],
                'consultant' => $data['consultant'],
            ],
            [
                'participates' => $data['participates'],
                'reason' => $data['reason'] ?? null,
                'toggled_by' => $request->user()->id,
                'toggled_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json(['message' => 'Обновлено']);
    }

    /** POST /admin/pool/preview */
    public function preview(Request $request): JsonResponse
    {
        return $this->participants($request);
    }

    /**
     * POST /admin/pool/apply — одноразовая фиксация пула за месяц.
     *
     * Поведение:
     *   - Если период УЖЕ закрыт (period_closures) → 422.
     *     Перезапись зафиксированного периода невозможна; для редких
     *     корректировок есть отдельный flow «Разморозка периода» (reopen).
     *   - Иначе: пересчитываем пул на лету по текущему qualificationLog,
     *     пишем в poolLog (DELETE-then-INSERT в транзакции) и СРАЗУ
     *     закрываем период через PeriodFreezeService::close.
     *     После этого poolLog/transaction/commission/qualificationLog за
     *     период становятся read-only через PeriodFreezeService::guard.
     */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        if ($this->freeze->isFrozen($year, $month)) {
            return response()->json([
                'frozen' => true,
                'message' => sprintf(
                    'Период %02d.%d уже зафиксирован. Перезапись запрещена. '.
                    'Для пересчёта обратитесь к админу — нужна разморозка периода.',
                    $month, $year
                ),
            ], 422);
        }

        // Async через queue: фронт получает batch_id и поллит /admin/pool/progress.
        // Раньше синхронный run() мог занять минуты на 500K commission-строк
        // и упирался в php-fpm/nginx таймауты.
        $batchId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\Cache::put("pool-apply:{$batchId}", [
            'status' => 'queued', 'percent' => 0,
            'message' => 'Задача поставлена в очередь',
            'updated_at' => now()->toIso8601String(),
        ], now()->addMinutes(30));

        \App\Jobs\ApplyPoolJob::dispatch($batchId, $year, $month, $request->user()?->id);

        return response()->json([
            'message' => 'Расчёт пула запущен в фоне',
            'batch_id' => $batchId,
            'progress_url' => "/api/v1/admin/pool/progress?batch_id={$batchId}",
        ], 202);
    }

    /**
     * GET /admin/pool/progress?batch_id=… — статус async-расчёта.
     */
    public function progress(Request $request): JsonResponse
    {
        $batchId = (string) $request->input('batch_id');
        if ($batchId === '') return response()->json(['message' => 'batch_id required'], 422);

        $progress = \Illuminate\Support\Facades\Cache::get("pool-apply:{$batchId}");
        if (! $progress) {
            return response()->json([
                'status' => 'unknown',
                'message' => 'Задача не найдена или истекла (TTL 30 мин)',
            ], 404);
        }
        return response()->json($progress);
    }

    /**
     * POST /admin/pool/reopen — разморозка зафиксированного периода.
     *
     * Только для роли `admin`. PeriodFreezeService::reopen ставит
     * reopened_at, после чего isFrozen возвращает false и UI снова
     * показывает кнопку «Зафиксировать пул». История закрытия
     * сохраняется в той же строке (не удаляется).
     *
     * После разморозки оператор может пересчитать пул и зафиксировать
     * заново — DELETE-then-INSERT в poolLog идемпотентен. Старые
     * выплаты партнёрам остаются в poolLog до момента повторной
     * фиксации (с DELETE WHERE date BETWEEN...).
     */
    public function reopen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $user = $request->user();
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        if (! in_array('admin', $roles, true)) {
            return response()->json([
                'message' => 'Разморозка периода доступна только администратору',
            ], 403);
        }

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        if (! $this->freeze->isFrozen($year, $month)) {
            return response()->json([
                'message' => sprintf('Период %02d.%d не заморожен', $month, $year),
            ], 422);
        }

        $this->freeze->reopen($year, $month, $user->id);

        \Illuminate\Support\Facades\Log::warning('pool period reopened', [
            'year' => $year, 'month' => $month, 'admin_id' => $user->id,
        ]);

        NotificationController::notifyStaff(
            'payment',
            sprintf('Период %02d.%d разморожен', $month, $year),
            sprintf('Админ %s разморозил период. Пул можно пересчитать.',
                trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? ''))),
            sprintf('/admin/pool?month=%d-%02d', $year, $month),
        );

        return response()->json([
            'message' => sprintf('Период %02d.%d разморожен', $month, $year),
        ]);
    }
}
