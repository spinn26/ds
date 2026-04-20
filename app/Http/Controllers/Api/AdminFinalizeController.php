<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MonthlyPenaltyRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $data = $this->validatedPeriod($request);
        $result = $this->runner->run($data['year'], $data['month'], applyWrite: false);
        return response()->json($result);
    }

    /** POST /admin/finalize/apply — write. */
    public function apply(Request $request): JsonResponse
    {
        $data = $this->validatedPeriod($request);
        $result = $this->runner->run($data['year'], $data['month'], applyWrite: true);
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
}
