<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly \App\Services\ConsultantService $consultantService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $month = $request->input('month', now()->format('Y-m'));

        return response()->json($this->dashboardService->getDashboardData($consultant, $month));
    }

    /**
     * GET /dashboard/dynamics — динамика личных продаж партнёра.
     *
     * scope=year&period=2026    → 12 точек, по месяцам года
     * scope=month&period=2026-09 → точки по дням месяца
     *
     * Считаем по транзакциям СВОИХ контрактов (contract.consultant = партнёр),
     * а не по снимку qualificationLog: снимок обновляется кнопкой пересчёта и
     * существует помесячно, поэтому для «зависимости от времени» и для разреза
     * по дням он не годится. Баллы (personalVolume) проставляются при заведении
     * транзакции — это чтение готовых данных, ничего не пересчитывается.
     *
     * Пустые периоды возвращаем нулями: без них график молча склеивает
     * месяцы без продаж, и провал выглядит как отсутствие данных.
     */
    public function dynamics(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $scope = $request->input('scope') === 'month' ? 'month' : 'year';

        // Какая карточка дашборда открыла график — от этого зависит, по чьим
        // контрактам считать. Метрика обязана совпадать с подписью карточки,
        // иначе под иконкой окажутся чужие цифры.
        $metric = $request->input('metric');
        $metric = in_array($metric, ['lp', 'first_line', 'team'], true) ? $metric : 'lp';

        $ids = match ($metric) {
            // Личные продажи — только свои контракты.
            'lp' => [$consultant->id],
            // Объём первой линии — контракты прямых приглашённых, без себя.
            'first_line' => DB::table('consultant')
                ->where('inviter', $consultant->id)
                ->whereNull('dateDeleted')
                ->pluck('id')->all(),
            // НГП растёт из продаж всей команды, включая собственные.
            'team' => array_merge(
                $this->consultantService->getAllDescendants($consultant->id),
                [$consultant->id],
            ),
        };

        if ($ids === []) {
            // Нет первой линии — отдаём пустой ряд, а не молчание: фронт
            // покажет «продаж не было», а не бесконечную загрузку.
            $ids = [0];
        }

        $base = fn () => DB::table('transaction as t')
            ->join('contract as c', 'c.id', '=', 't.contract')
            ->whereIn('c.consultant', $ids)
            ->whereNull('t.deletedAt')
            ->whereNull('c.deletedAt');

        $sums = 'SUM(t."amountRUB") AS amount_rub, SUM(t."personalVolume") AS points, COUNT(*) AS deals';

        if ($scope === 'month') {
            $period = (string) $request->input('period', now()->format('Y-m'));
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
                $period = now()->format('Y-m');
            }

            $rows = $base()
                ->where('t.dateMonth', $period)
                ->selectRaw('to_char(t."date", \'YYYY-MM-DD\') AS label, ' . $sums)
                ->groupBy('label')
                ->orderBy('label')
                ->get()
                ->keyBy('label');

            $start = \Carbon\Carbon::createFromFormat('Y-m-d', $period . '-01')->startOfMonth();
            $labels = [];
            for ($d = $start->copy(); $d->month === $start->month; $d->addDay()) {
                $labels[] = $d->format('Y-m-d');
            }
        } else {
            $year = (string) $request->input('period', now()->format('Y'));
            if (! preg_match('/^\d{4}$/', $year)) {
                $year = now()->format('Y');
            }

            $rows = $base()
                ->where('t.dateMonth', 'like', $year . '-%')
                ->selectRaw('t."dateMonth" AS label, ' . $sums)
                ->groupBy('label')
                ->orderBy('label')
                ->get()
                ->keyBy('label');

            $labels = [];
            for ($m = 1; $m <= 12; $m++) {
                $labels[] = sprintf('%s-%02d', $year, $m);
            }
            $period = $year;
        }

        $series = array_map(fn (string $label) => [
            'label' => $label,
            'amountRub' => round((float) ($rows[$label]->amount_rub ?? 0), 2),
            'points' => round((float) ($rows[$label]->points ?? 0), 2),
            'deals' => (int) ($rows[$label]->deals ?? 0),
        ], $labels);

        return response()->json([
            'scope' => $scope,
            'metric' => $metric,
            'period' => $period,
            'series' => $series,
            'totals' => [
                'amountRub' => round(array_sum(array_column($series, 'amountRub')), 2),
                'points' => round(array_sum(array_column($series, 'points')), 2),
                'deals' => array_sum(array_column($series, 'deals')),
            ],
        ]);
    }

    public function statusLevels(): JsonResponse
    {
        $levels = DB::table('status_levels')
            ->orderBy('level')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'level' => $l->level,
                'title' => $l->title,
                'percent' => $l->percent,
                'personalVolume' => $l->personalVolume ?? 0,
                'groupVolume' => $l->groupVolume ?? 0,
                'mandatoryGP' => $l->mandatoryGP ?? 0,
                'groupVolumeCumulative' => $l->groupVolumeCumulative ?? 0,
                'otrif' => $l->otrif ?? 0,
                'pool' => $l->pool ?? 0,
                'dsShare' => $l->dsShare ?? 0,
            ]);

        return response()->json($levels);
    }
}
