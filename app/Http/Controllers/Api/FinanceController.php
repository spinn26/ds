<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\FinanceReportService;
use App\Services\PeriodVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $financeReportService,
        private readonly PeriodVisibilityService $visibility,
    ) {}

    /**
     * Отчёт начислений и выплат партнёра.
     * Полная структура: карточки итогов, детальные таблицы, курсы валют.
     *
     * Per spec ✅Доступность отчётов §1: пока админ не открыл отчёт за
     * месяц, партнёр получает 423 Locked — UI показывает заглушку.
     * Сотрудники (staff) видят отчёт всегда.
     */
    public function report(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['summary' => null, 'tables' => null]);
        }

        $month = $request->input('month', now()->format('Y-m'));

        if (! $user->isStaff()) {
            [$year, $monthNum] = array_map('intval', explode('-', $month) + [0, 0]);
            if ($year && $monthNum && ! $this->visibility->isVisible($year, $monthNum)) {
                return response()->json([
                    'locked' => true,
                    'message' => 'Отчёт за этот период ещё не опубликован',
                    'period' => $month,
                ], 423);
            }
        }

        return response()->json($this->financeReportService->getReportData($consultant, $month));
    }

    /**
     * Калькулятор объёмов — данные для расчёта.
     * Текущие объёмы + таблица квалификаций для прогноза.
     */
    public function calculator(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        return response()->json($this->financeReportService->getCalculatorData($consultant));
    }
}
