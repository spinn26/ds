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

        // Qualification for current and previous period
        $periodStart = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $qLogCurrent = DB::table('qualificationLog')
            ->where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->where('date', '>=', $periodStart)
            ->where('date', '<=', $periodEnd)
            ->orderByDesc('date')
            ->first();

        $prevPeriodStart = $periodStart->copy()->subMonth()->startOfMonth();
        $prevPeriodEnd = $prevPeriodStart->copy()->endOfMonth();
        $qLogPrev = DB::table('qualificationLog')
            ->where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->where('date', '>=', $prevPeriodStart)
            ->where('date', '<=', $prevPeriodEnd)
            ->orderByDesc('date')
            ->first();

        // Commission level
        $commissionLevel = null;
        if ($qLogCurrent && ($qLogCurrent->calculationLevel ?? $qLogCurrent->nominalLevel)) {
            $commissionLevel = DB::table('status_levels')
                ->where('id', $qLogCurrent->calculationLevel ?? $qLogCurrent->nominalLevel)
                ->first();
        }

        // All commissions for the period
        $allCommissions = DB::table('commission')
            ->where('consultant', $consultant->id)
            ->where('dateMonth', $month)
            ->whereNull('deletedAt')
            ->orderByDesc('date')
            ->get();

        // Personal sales: chainOrder = 0 (own sales)
        $personalCommissions = $allCommissions->where('chainOrder', 0);
        // Group sales: chainOrder > 0
        $groupCommissions = $allCommissions->where('chainOrder', '>', 0);
        // Other accruals: no transaction linked
        $otherAccruals = $allCommissions->whereNull('transaction');

        // Format personal sales with transaction details
        $personalSalesTable = $personalCommissions->map(function ($c) {
            $txData = $this->getTransactionDetails($c->transaction);
            return [
                'id' => $c->id,
                'date' => $c->date,
                'contractNumber' => $txData['contractNumber'],
                'clientName' => $txData['clientName'],
                'productName' => $txData['productName'],
                'programName' => $txData['programName'],
                'paymentAmount' => round((float) ($txData['amount'] ?? 0), 2),
                'parameter' => $c->percent,
                'amountNoVat' => round((float) ($c->amount ?? 0), 2),
                'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                'bonus' => round((float) ($c->groupBonus ?? 0), 2),
                'bonusRub' => round((float) ($c->groupBonusRub ?? 0), 2),
                'comment' => $c->comment,
            ];
        })->values();

        // Format group sales with partner name
        $groupSalesTable = $groupCommissions->map(function ($c) {
            $txData = $this->getTransactionDetails($c->transaction);
            // Get partner name from the commission's consultant chain
            $partnerName = null;
            if ($c->commissionFromOtherConsultant) {
                $partnerName = DB::table('consultant')
                    ->where('id', $c->commissionFromOtherConsultant)
                    ->value('personName');
            }
            return [
                'id' => $c->id,
                'date' => $c->date,
                'contractNumber' => $txData['contractNumber'],
                'clientName' => $txData['clientName'],
                'productName' => $txData['productName'],
                'programName' => $txData['programName'],
                'paymentAmount' => round((float) ($txData['amount'] ?? 0), 2),
                'parameter' => $c->percent,
                'amountNoVat' => round((float) ($c->amount ?? 0), 2),
                'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                'bonus' => round((float) ($c->groupBonus ?? 0), 2),
                'bonusRub' => round((float) ($c->groupBonusRub ?? 0), 2),
                'partnerName' => $partnerName,
                'comment' => $c->comment,
            ];
        })->values();

        // Other accruals table
        $otherAccrualsTable = $otherAccruals->map(fn ($c) => [
            'id' => $c->id,
            'date' => $c->date,
            'amount' => round((float) ($c->amount ?? 0), 2),
            'amountRUB' => round((float) ($c->amountRUB ?? 0), 2),
            'comment' => $c->comment,
        ])->values();

        // Breakaway info from qualificationLog
        $breakaway = null;
        if ($qLogCurrent && $qLogCurrent->gap) {
            $branchName = $qLogCurrent->branchWithGap
                ? DB::table('consultant')->where('id', $qLogCurrent->branchWithGap)->value('personName')
                : null;
            $breakaway = [
                'partnerName' => $branchName,
                'groupVolume' => round((float) ($qLogCurrent->branchWithGapGroupVolume ?? 0), 2),
                'gapPercentage' => round((float) ($qLogCurrent->gapValuePercentage ?? 0), 2),
                'gapValue' => round((float) ($qLogCurrent->gapValue ?? 0), 2),
            ];
        }

        // Balance for the period
        $balance = DB::table('consultantBalance')
            ->where('consultant', $consultant->id)
            ->where('dateMonth', $month)
            ->first();

        // If no balance for this specific month, get latest
        if (! $balance) {
            $balance = DB::table('consultantBalance')
                ->where('consultant', $consultant->id)
                ->orderByDesc('id')
                ->first();
        }

        // Payments for the period
        $payments = [];
        if ($balance) {
            $payments = DB::table('consultantPayment')
                ->where('consultantBalance', $balance->id)
                ->orderByDesc('paymentDate')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'date' => $p->paymentDate,
                    'amount' => round((float) $p->amount, 2),
                    'comment' => $p->comment,
                ])
                ->values();
        }

        // Currency rates for the period
        $currencyRates = DB::table('currencyRate')
            ->where('date', '>=', $periodStart)
            ->where('date', '<=', $periodEnd)
            ->orderByDesc('date')
            ->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'rate' => round((float) $r->rate, 4),
                'date' => $r->date,
            ]);

        // Summary cards
        $personalSalesPoints = $personalCommissions->sum(fn ($c) => (float) ($c->personalVolume ?? 0));
        $personalSalesBonus = $personalCommissions->sum(fn ($c) => (float) ($c->groupBonus ?? 0));
        $personalSalesBonusRub = $personalCommissions->sum(fn ($c) => (float) ($c->groupBonusRub ?? 0));
        $personalSalesClientPayments = $personalCommissions->sum(fn ($c) => (float) ($c->amount ?? 0));

        $groupSalesPoints = $groupCommissions->sum(fn ($c) => (float) ($c->personalVolume ?? 0));
        $groupSalesBonus = $groupCommissions->sum(fn ($c) => (float) ($c->groupBonus ?? 0));
        $groupSalesBonusRub = $groupCommissions->sum(fn ($c) => (float) ($c->groupBonusRub ?? 0));
        $groupSalesClientPayments = $groupCommissions->sum(fn ($c) => (float) ($c->amount ?? 0));

        $totalBonus = $personalSalesBonus + $groupSalesBonus;
        $totalBonusRub = $personalSalesBonusRub + $groupSalesBonusRub;

        return response()->json([
            'summary' => [
                'qualificationPrev' => $qLogPrev ? [
                    'level' => $qLogPrev->calculationLevel ?? $qLogPrev->nominalLevel,
                    'personalVolume' => round((float) ($qLogPrev->personalVolume ?? 0), 2),
                    'groupVolume' => round((float) ($qLogPrev->groupVolume ?? 0), 2),
                    'groupVolumeCumulative' => round((float) ($qLogPrev->groupVolumeCumulative ?? 0), 2),
                ] : null,
                'qualificationCurrent' => $qLogCurrent ? [
                    'level' => $qLogCurrent->calculationLevel ?? $qLogCurrent->nominalLevel,
                    'personalVolume' => round((float) ($qLogCurrent->personalVolume ?? 0), 2),
                    'groupVolume' => round((float) ($qLogCurrent->groupVolume ?? 0), 2),
                    'groupVolumeCumulative' => round((float) ($qLogCurrent->groupVolumeCumulative ?? 0), 2),
                ] : null,
                'commissionLevel' => $commissionLevel ? [
                    'level' => $commissionLevel->level,
                    'title' => $commissionLevel->title,
                    'percent' => $commissionLevel->percent,
                ] : null,
                'volumes' => [
                    'personalVolume' => round((float) ($qLogCurrent->personalVolume ?? $consultant->personalVolume ?? 0), 2),
                    'groupVolume' => round((float) ($qLogCurrent->groupVolume ?? $consultant->groupVolume ?? 0), 2),
                    'groupVolumeCumulative' => round((float) ($qLogCurrent->groupVolumeCumulative ?? $consultant->groupVolumeCumulative ?? 0), 2),
                ],
                'personalSales' => [
                    'points' => round($personalSalesPoints, 2),
                    'bonus' => round($personalSalesBonus, 2),
                    'bonusRub' => round($personalSalesBonusRub, 2),
                    'clientPaymentsRub' => round($personalSalesClientPayments, 2),
                ],
                'groupSales' => [
                    'points' => round($groupSalesPoints, 2),
                    'bonus' => round($groupSalesBonus, 2),
                    'bonusRub' => round($groupSalesBonusRub, 2),
                    'clientPaymentsRub' => round($groupSalesClientPayments, 2),
                ],
                'totalSales' => [
                    'bonus' => round($totalBonus, 2),
                    'bonusRub' => round($totalBonusRub, 2),
                    'poolRub' => round((float) ($balance->accruedPool ?? 0), 2),
                    'totalRub' => round($totalBonusRub + (float) ($balance->accruedPool ?? 0), 2),
                ],
                'breakaway' => $breakaway,
                'monthEnd' => $balance ? [
                    'balanceStart' => round((float) ($balance->balance ?? 0), 2),
                    'otherAccruals' => round((float) ($balance->accruedNonTransactional ?? 0), 2),
                    'totalAccrued' => round((float) ($balance->accruedTotal ?? 0), 2),
                    'totalPayable' => round((float) ($balance->totalPayable ?? 0), 2),
                    'payed' => round((float) ($balance->payed ?? 0), 2),
                    'remaining' => round((float) ($balance->remaining ?? 0), 2),
                ] : null,
            ],
            'tables' => [
                'personalSales' => $personalSalesTable,
                'groupSales' => $groupSalesTable,
                'otherAccruals' => $otherAccrualsTable,
                'breakaway' => $breakaway ? [[
                    'partnerName' => $breakaway['partnerName'],
                    'groupVolume' => $breakaway['groupVolume'],
                    'gapPercentage' => $breakaway['gapPercentage'],
                ]] : [],
                'payments' => $payments,
            ],
            'currencyRates' => $currencyRates,
            'period' => $month,
        ]);
    }

    /**
     * Get transaction + contract details for a commission row.
     */
    private function getTransactionDetails(?int $transactionId): array
    {
        if (! $transactionId) {
            return ['contractNumber' => null, 'clientName' => null, 'productName' => null, 'programName' => null, 'amount' => null];
        }

        $tx = DB::table('transaction')->where('id', $transactionId)->first();
        if (! $tx || ! $tx->contract) {
            return ['contractNumber' => null, 'clientName' => null, 'productName' => null, 'programName' => null, 'amount' => $tx?->amount ?? null];
        }

        $contract = DB::table('contract')->where('id', $tx->contract)->first();

        return [
            'contractNumber' => $contract->number ?? null,
            'clientName' => $contract->clientName ?? null,
            'productName' => $contract->productName ?? null,
            'programName' => $contract->programName ?? null,
            'amount' => $tx->amount ?? null,
        ];
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
