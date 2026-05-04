<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PeriodFreezeService;
use App\Services\PeriodVisibilityService;
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
        private readonly PeriodVisibilityService $visibility,
    ) {}

    /**
     * Линейный реестр последних 24 месяцев со статусами заморозки и
     * видимости (per spec ✅Доступность отчётов §1).
     */
    public function index(Request $request): JsonResponse
    {
        $closures = DB::table('period_closures')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(240)
            ->get()
            ->keyBy(fn ($c) => sprintf('%04d-%02d', $c->year, $c->month));

        $now = now();
        $rows = [];
        for ($i = 0; $i < 24; $i++) {
            $d = $now->copy()->subMonths($i);
            $year = (int) $d->format('Y');
            $month = (int) $d->format('n');
            $key = sprintf('%04d-%02d', $year, $month);
            $c = $closures[$key] ?? null;

            $rows[] = [
                'id' => $c->id ?? null,
                'year' => $year,
                'month' => $month,
                'closedAt' => $c->closed_at ?? null,
                'closedBy' => $c->closed_by ?? null,
                'reopenedAt' => $c->reopened_at ?? null,
                'reopenedBy' => $c->reopened_by ?? null,
                'note' => $c->note ?? null,
                'isFrozen' => $c ? $c->reopened_at === null : false,
                'isVisibleToPartners' => $this->visibility->isVisible($year, $month),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Toggle видимости отчёта за месяц для партнёров
     * (per spec ✅Доступность отчётов §1, кнопка «Сделать доступным/недоступным»).
     */
    public function setVisibility(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
            'visible' => 'required|boolean',
        ]);

        $this->visibility->setVisibility(
            (int) $data['year'],
            (int) $data['month'],
            (bool) $data['visible'],
            (int) $request->user()->id,
        );

        return response()->json([
            'message' => $data['visible']
                ? "Отчёты за {$data['month']}.{$data['year']} стали видны партнёрам"
                : "Отчёты за {$data['month']}.{$data['year']} скрыты от партнёров",
        ]);
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
