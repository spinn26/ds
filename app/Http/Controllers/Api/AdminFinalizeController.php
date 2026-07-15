<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MonthlyPenaltyRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\NotificationController;

/**
 * Финализация месяца — штрафы по спеке §5 (detachment, OP, combo).
 */
class AdminFinalizeController extends Controller
{
    public function __construct(
        private readonly MonthlyPenaltyRunner $runner,
    ) {}

    /** POST /admin/finalize/preview — dry-run. */
    public function preview(Request $request): JsonResponse
    {
        if ($denied = $this->denyIfNotFinalizer($request)) {
            return $denied;
        }
        $data = $this->validatedPeriod($request);
        $result = $this->runner->run($data['year'], $data['month'], applyWrite: false);

        // Уже удержанное за месяц (reduction=true). affected показывает только
        // НОВЫЕ удержания — при повторном запуске он 0 (идемпотентность), и без
        // этой цифры «0 комиссий» читается как «нечего удерживать», хотя удержания
        // уже применены. Отдаём сумму/количество уже удержанного для UI.
        $dm = sprintf('%04d-%02d', $data['year'], $data['month']);
        $already = DB::table('commission')
            ->where('dateMonth', $dm)
            ->whereNull('deletedAt')
            ->where('reduction', true)
            ->selectRaw('COUNT(*) cnt,
                COALESCE(SUM("withheldForGap"), 0) + COALESCE(SUM("withheldForCommission"), 0) AS sum')
            ->first();
        $result['alreadyWithheldCount'] = (int) ($already->cnt ?? 0);
        $result['alreadyWithheldSum'] = (float) ($already->sum ?? 0);

        return response()->json($result);
    }

    /** POST /admin/finalize/apply — write. */
    public function apply(Request $request): JsonResponse
    {
        if ($denied = $this->denyIfNotFinalizer($request)) {
            return $denied;
        }
        $data = $this->validatedPeriod($request);

        // Блокировка против гонки: ночной cron (`finalize:apply` в 04:00) и
        // UI-кнопка (Транзакции/Пул/КарточкаПериода) могут попасть в один
        // период одновременно. Runner идемпотентный, но при одновременном
        // запуске оба перепишут withheld* по одной строке → race на
        // groupBonusRubBeforeGapReduction. Lock-key включает год-месяц,
        // разные периоды друг друга не блокируют.
        $lockKey = sprintf('finalize:apply:%d-%02d', $data['year'], $data['month']);
        $lock = Cache::lock($lockKey, 300); // 5 минут на расчёт

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Перерасчёт за этот месяц уже выполняется. Подождите минуту.',
            ], 423); // Locked
        }

        try {
            $result = $this->runner->run($data['year'], $data['month'], applyWrite: true);
        } finally {
            $lock->release();
        }

        if ($result['frozen'] ?? false) {
            return response()->json($result, 422);
        }

        NotificationController::notifyStaff(
            'system',
            sprintf('Штрафы применены: %02d.%d', $data['month'], $data['year']),
            sprintf('Затронуто %d комиссий у %d партнёров', $result['affected'] ?? 0, $result['processed'] ?? 0),
            sprintf('/manage/periods/%d-%02d', $data['year'], $data['month']),
        );

        return response()->json([
            'message' => "Финализация выполнена: затронуто {$result['affected']} комиссий у {$result['processed']} партнёров",
            'result' => $result,
        ]);
    }

    private function validatedPeriod(Request $request): array
    {
        return $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);
    }

    /**
     * Финализация месяца — только admin + calculations (Руководитель
     * по расчётам). Соответствует scope reports-access в cabinetPermissions.
     */
    private function denyIfNotFinalizer(Request $request): ?JsonResponse
    {
        $user = $request->user();
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        if (! array_intersect($roles, ['admin', 'calculations'])) {
            return response()->json([
                'message' => 'Перерасчёт штрафов доступен только администратору и руководителю по расчётам',
            ], 403);
        }
        return null;
    }
}
