<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
    ) {}

    /** GET /admin/pool/participants */
    public function participants(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $result = $this->runner->run((int) $data['year'], (int) $data['month'], applyWrite: false);
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

    /** POST /admin/pool/apply */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $result = $this->runner->run((int) $data['year'], (int) $data['month'], applyWrite: true);

        // Заморожен → 422, как и штрафы §5 в AdminFinalizeController::apply
        if ($result['frozen'] ?? false) {
            return response()->json($result, 422);
        }

        NotificationController::notifyStaff(
            'payment',
            sprintf('Пул рассчитан: %02d.%d', $data['month'], $data['year']),
            sprintf('Записано %d строк, выплачено %s ₽',
                $result['written'] ?? 0,
                number_format($result['totalPaid'] ?? 0, 0, '.', ' ')),
            sprintf('/manage/periods/%d-%02d', $data['year'], $data['month']),
        );

        return response()->json([
            'message' => "Пул записан: {$result['written']} строк",
            'result' => $result,
        ]);
    }
}
