<?php

namespace App\Services;

use App\Models\Consultant;
use Illuminate\Support\Facades\DB;

class FinanceReportService
{
    /**
     * Get full finance report data for a consultant and period.
     */
    public function getReportData(Consultant $consultant, string $month): array
    {
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

        // Commission level — «Единая квалификация» (spec ✅Квалификации.md §2):
        // one level per month. If Directual left divergent values, take the
        // higher one — it's what the partner actually earned by НГП.
        $commissionLevel = null;
        if ($qLogCurrent) {
            $nomId = $qLogCurrent->nominalLevel ?? null;
            $calcId = $qLogCurrent->calculationLevel ?? null;
            $levels = DB::table('status_levels')
                ->whereIn('id', array_filter([$nomId, $calcId]))
                ->get()->keyBy('id');
            $nomLvl = $nomId ? ($levels[$nomId]->level ?? 0) : 0;
            $calcLvl = $calcId ? ($levels[$calcId]->level ?? 0) : 0;
            $bestId = $nomLvl >= $calcLvl ? $nomId : $calcId;
            $commissionLevel = $bestId ? $levels[$bestId] : null;
        }

        // All commissions for the period
        $allCommissions = DB::table('commission')
            ->where('consultant', $consultant->id)
            ->where('dateMonth', $month)
            ->whereNull('deletedAt')
            ->orderByDesc('date')
            ->get();

        // Personal sales: chainOrder = 1 (direct sale by this consultant)
        $personalCommissions = $allCommissions->where('chainOrder', 1)->whereNotNull('transaction');
        // Group sales: chainOrder > 1 (sales by downstream partners)
        $groupCommissions = $allCommissions->where('chainOrder', '>', 1)->whereNotNull('transaction');
        // Other accruals: no transaction linked (manual bonuses/penalties)
        $otherAccruals = $allCommissions->whereNull('transaction');

        // Batch load transaction details for all commissions with transactions
        $allWithTx = $allCommissions->whereNotNull('transaction');
        $txIds = $allWithTx->pluck('transaction')->filter()->unique();
        $transactions = $txIds->isNotEmpty()
            ? DB::table('transaction')->whereIn('id', $txIds)->get()->keyBy('id')
            : collect();
        $contractIds = $transactions->pluck('contract')->filter()->unique();
        $contracts = $contractIds->isNotEmpty()
            ? DB::table('contract')->whereIn('id', $contractIds)->get()->keyBy('id')
            : collect();

        // Helper to get tx details from pre-loaded data
        $getTxData = function (?int $transactionId) use ($transactions, $contracts): array {
            if (! $transactionId) {
                return ['contractNumber' => null, 'clientName' => null, 'productName' => null, 'programName' => null, 'amount' => null];
            }
            $tx = $transactions[$transactionId] ?? null;
            if (! $tx || ! $tx->contract) {
                return ['contractNumber' => null, 'clientName' => null, 'productName' => null, 'programName' => null, 'amount' => $tx?->amount ?? null];
            }
            $contract = $contracts[$tx->contract] ?? null;
            return [
                'contractNumber' => $contract->number ?? null,
                'clientName' => $contract->clientName ?? null,
                'productName' => $contract->productName ?? null,
                'programName' => $contract->programName ?? null,
                'amount' => $tx->amount ?? null,
            ];
        };

        // Format personal sales with transaction details
        $personalSalesTable = $personalCommissions->map(function ($c) use ($getTxData) {
            $txData = $getTxData($c->transaction);
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

        // Batch load partner names for group commissions
        $partnerIds = $groupCommissions->pluck('commissionFromOtherConsultant')->filter()->unique();
        $partnerNames = $partnerIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $partnerIds)->pluck('personName', 'id')
            : collect();

        // Format group sales with partner name
        $groupSalesTable = $groupCommissions->map(function ($c) use ($getTxData, $partnerNames) {
            $txData = $getTxData($c->transaction);
            $partnerName = $c->commissionFromOtherConsultant
                ? ($partnerNames[$c->commissionFromOtherConsultant] ?? null)
                : null;
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

        // Currency rates for the period — latest rate per currency
        $currencyRates = DB::table('currencyRate')
            ->join('currency', 'currencyRate.currency', '=', 'currency.id')
            ->where('currencyRate.date', '>=', $periodStart)
            ->where('currencyRate.date', '<=', $periodEnd)
            ->select('currency.symbol', 'currency.nameRu', 'currencyRate.rate', 'currencyRate.date')
            ->orderByDesc('currencyRate.date')
            ->get()
            ->groupBy('symbol')
            ->map(fn ($rates) => [
                'currency' => $rates->first()->symbol ?? $rates->first()->nameRu,
                'rate' => round((float) $rates->first()->rate, 4),
            ])
            ->values();

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

        return [
            'summary' => [
                'qualificationPrev' => $qLogPrev ? [
                    'level' => $qLogPrev->nominalLevel ?? $qLogPrev->calculationLevel,
                    'title' => ($qLogPrev->nominalLevel ?? $qLogPrev->calculationLevel)
                        ? DB::table('status_levels')->where('id', $qLogPrev->nominalLevel ?? $qLogPrev->calculationLevel)->value('title')
                        : null,
                ] : null,
                'qualificationCurrent' => $qLogCurrent ? [
                    'level' => $qLogCurrent->nominalLevel ?? $qLogCurrent->calculationLevel,
                    'title' => ($qLogCurrent->nominalLevel ?? $qLogCurrent->calculationLevel)
                        ? DB::table('status_levels')->where('id', $qLogCurrent->nominalLevel ?? $qLogCurrent->calculationLevel)->value('title')
                        : null,
                ] : null,
                'commissionLevel' => $commissionLevel ? [
                    'level' => $commissionLevel->level,
                    'title' => $commissionLevel->title,
                    'percent' => $commissionLevel->percent,
                ] : null,
                // Для исторических периодов fallback на consultant.* (текущие
                // агрегаты) даёт неверную картину: нет qualificationLog за
                // март — покажется ЛП за СЕГОДНЯ. Допускаем такой fallback
                // только когда отчёт строится за текущий месяц.
                'volumes' => (function () use ($qLogCurrent, $consultant, $month) {
                    $isCurrentMonth = $month === now()->format('Y-m');
                    return [
                        'lp' => round((float) ($qLogCurrent->personalVolume
                            ?? ($isCurrentMonth ? $consultant->personalVolume : 0)), 2),
                        'gp' => round((float) ($qLogCurrent->groupVolume
                            ?? ($isCurrentMonth ? $consultant->groupVolume : 0)), 2),
                        'ngp' => round((float) ($qLogCurrent->groupVolumeCumulative
                            ?? ($isCurrentMonth ? $consultant->groupVolumeCumulative : 0)), 2),
                    ];
                })(),
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
                    'otherAccrualsPoints' => round($otherAccruals->sum(fn ($c) => (float) ($c->amount ?? 0)), 2),
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
        ];
    }

    /**
     * Get calculator data for a consultant.
     */
    public function getCalculatorData(Consultant $consultant): array
    {
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

        return [
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
        ];
    }
}
