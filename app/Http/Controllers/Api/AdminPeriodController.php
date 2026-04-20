<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PeriodFreezeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Управление заморозкой отчётных месяцев.
 *
 * Закрытый месяц становится «серым» на странице транзакций
 * (spec: ✅Комиссии .md Part 2 §1). Никакие транзакции / комиссии
 * / пересчёты за этот месяц больше не идут; правки — только через
 * «Прочие начисления».
 */
class AdminPeriodController extends Controller
{
    public function __construct(
        private readonly PeriodFreezeService $periodFreeze,
    ) {}

    /** Список закрытий с пагинацией. */
    public function index(Request $request): JsonResponse
    {
        $closures = DB::table('period_closures')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(120)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'year' => (int) $c->year,
                'month' => (int) $c->month,
                'closedAt' => $c->closed_at,
                'closedBy' => $c->closed_by,
                'reopenedAt' => $c->reopened_at,
                'reopenedBy' => $c->reopened_by,
                'note' => $c->note,
                'isFrozen' => $c->reopened_at === null,
            ]);

        return response()->json(['data' => $closures]);
    }

    /** Закрыть месяц. */
    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
            'note' => 'nullable|string|max:500',
        ]);

        $this->periodFreeze->close(
            (int) $data['year'],
            (int) $data['month'],
            (int) $request->user()->id,
            $data['note'] ?? null,
        );

        NotificationController::notifyStaff(
            'system',
            sprintf('Период %02d.%d закрыт', $data['month'], $data['year']),
            $data['note'] ?: 'Правка комиссий и пула в этом месяце заблокирована',
            sprintf('/manage/periods/%d-%02d', $data['year'], $data['month']),
        );

        return response()->json([
            'message' => "Период {$data['month']}.{$data['year']} закрыт",
        ]);
    }

    /** Переоткрыть месяц. */
    public function reopen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $this->periodFreeze->reopen(
            (int) $data['year'],
            (int) $data['month'],
            (int) $request->user()->id,
        );

        NotificationController::notifyStaff(
            'system',
            sprintf('Период %02d.%d переоткрыт', $data['month'], $data['year']),
            'Закрытие месяца отменено — будьте внимательны к последующим правкам',
            sprintf('/manage/periods/%d-%02d', $data['year'], $data['month']),
        );

        return response()->json([
            'message' => "Период {$data['month']}.{$data['year']} открыт",
        ]);
    }

    /** Проверить: закрыт ли конкретный месяц (для UI-индикатора). */
    public function check(int $year, int $month): JsonResponse
    {
        return response()->json([
            'year' => $year,
            'month' => $month,
            'frozen' => $this->periodFreeze->isFrozen($year, $month),
        ]);
    }
}
