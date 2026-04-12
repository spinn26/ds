<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    /**
     * Отчёт начислений и выплат партнёра.
     * Комиссии за период + выплаты.
     */
    public function report(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['commissions' => [], 'payments' => [], 'summary' => null]);
        }

        $month = $request->input('month', now()->format('Y-m'));

        // Комиссии за период
        $commissions = DB::table('commission')
            ->where('consultant', $consultant->id)
            ->where('dateMonth', $month)
            ->whereNull('deletedAt')
            ->orderByDesc('date')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'date' => $c->date,
                'type' => $c->type,
                'amount' => round((float) ($c->amount ?? 0), 2),
                'amountRUB' => round((float) ($c->amountRUB ?? 0), 2),
                'amountUSD' => round((float) ($c->amountUSD ?? 0), 2),
                'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($c->groupVolume ?? 0), 2),
                'groupBonus' => round((float) ($c->groupBonus ?? 0), 2),
                'groupBonusRub' => round((float) ($c->groupBonusRub ?? 0), 2),
                'percent' => $c->percent,
            ]);

        // Итоги за период
        $summary = [
            'totalAmountRUB' => $commissions->sum('amountRUB'),
            'totalAmountUSD' => $commissions->sum('amountUSD'),
            'totalPersonalVolume' => $commissions->sum('personalVolume'),
            'totalGroupVolume' => $commissions->sum('groupVolume'),
            'totalGroupBonus' => $commissions->sum('groupBonusRub'),
            'commissionsCount' => $commissions->count(),
        ];

        // Выплаты
        $balanceId = DB::table('consultantBalance')
            ->where('consultant', $consultant->id)
            ->value('id');

        $payments = [];
        if ($balanceId) {
            $payments = DB::table('consultantPayment')
                ->where('consultantBalance', $balanceId)
                ->orderByDesc('paymentDate')
                ->limit(50)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => round((float) $p->amount, 2),
                    'paymentDate' => $p->paymentDate,
                    'status' => $p->status,
                    'comment' => $p->comment,
                ]);
        }

        // Баланс
        $balance = DB::table('consultantBalance')
            ->where('consultant', $consultant->id)
            ->first();

        return response()->json([
            'commissions' => $commissions,
            'payments' => $payments,
            'summary' => $summary,
            'balance' => $balance ? [
                'amount' => round((float) ($balance->amount ?? 0), 2),
                'amountRUB' => round((float) ($balance->amountRub ?? 0), 2),
            ] : null,
            'period' => $month,
        ]);
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

        $qLog = DB::table('qualificationLog')
            ->where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->orderByDesc('date')
            ->first();

        $currentLevel = null;
        if ($qLog) {
            $currentLevel = DB::table('status_levels')
                ->where('id', $qLog->calculationLevel ?? $qLog->nominalLevel)
                ->first();
        }

        $levels = DB::table('status_levels')
            ->orderBy('level')
            ->get()
            ->map(fn ($l) => [
                'level' => $l->level,
                'title' => $l->title,
                'percent' => $l->percent,
                'groupVolumeCumulative' => $l->groupVolumeCumulative ?? 0,
                'personalVolume' => $l->personalVolume ?? 0,
                'otrif' => $l->otrif ?? 0,
                'pool' => $l->pool ?? 0,
                'dsShare' => $l->dsShare ?? 0,
            ]);

        return response()->json([
            'currentVolumes' => [
                'personalVolume' => round((float) ($qLog->personalVolume ?? $consultant->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($qLog->groupVolume ?? $consultant->groupVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($qLog->groupVolumeCumulative ?? $consultant->groupVolumeCumulative ?? 0), 2),
            ],
            'currentLevel' => $currentLevel ? [
                'level' => $currentLevel->level,
                'title' => $currentLevel->title,
                'percent' => $currentLevel->percent,
            ] : null,
            'levels' => $levels,
        ]);
    }
}
