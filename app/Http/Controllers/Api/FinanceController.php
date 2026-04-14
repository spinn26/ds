<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function __construct(
        private readonly FinanceReportService $financeReportService,
    ) {}

    /**
     * Отчёт начислений и выплат партнёра.
     * Полная структура: карточки итогов, детальные таблицы, курсы валют.
     */
    public function report(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['summary' => null, 'tables' => null]);
        }

        $month = $request->input('month', now()->format('Y-m'));

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
