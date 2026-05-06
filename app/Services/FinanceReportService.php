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

        // Batch load transaction details for all commissions with transactions.
        // Soft-deleted транзакции/контракты исключаем — иначе в отчёте партнёра
        // могут всплыть удалённые сделки с их (возможно стертыми) суммами.
        $allWithTx = $allCommissions->whereNotNull('transaction');
        $txIds = $allWithTx->pluck('transaction')->filter()->unique();
        $transactions = $txIds->isNotEmpty()
            ? DB::table('transaction')->whereIn('id', $txIds)
                ->whereNull('deletedAt')
                ->get()->keyBy('id')
            : collect();
        $contractIds = $transactions->pluck('contract')->filter()->unique();
        $contracts = $contractIds->isNotEmpty()
            ? DB::table('contract')->whereIn('id', $contractIds)
                ->whereNull('deletedAt')
                ->get()->keyBy('id')
            : collect();

        // Programs (для commissionCalcProperty → "Свойство") и
        // commissionCalcProperty.title — догружаем батчем, чтобы UI мог
        // показывать «Свойство», а не "%-ставку" в колонке параметр.
        $programIds = $contracts->pluck('program')->filter()->unique();
        $programs = $programIds->isNotEmpty()
            ? DB::table('program')->whereIn('id', $programIds)
                ->get(['id', 'commissionCalcProperty'])->keyBy('id')
            : collect();
        $propIds = $programs->pluck('commissionCalcProperty')->filter()->unique();
        $properties = $propIds->isNotEmpty()
            ? DB::table('commissionCalcProperty')->whereIn('id', $propIds)
                ->pluck('title', 'id')
            : collect();

        // Config-флаги продукта — UI скрывает «Свойство»/«Срок»/«Год КВ»
        // у тех продуктов, где они не релевантны (миграция
        // 2026_05_05_000090_add_product_field_flags).
        $productIds = $contracts->pluck('product')->filter()->unique();
        $productFlags = $productIds->isNotEmpty()
            ? DB::table('product')->whereIn('id', $productIds)
                ->get(['id', 'has_property', 'has_term', 'has_year_kv'])
                ->keyBy('id')
            : collect();

        // Helper to get tx details from pre-loaded data
        $getTxData = function (?int $transactionId) use ($transactions, $contracts, $programs, $properties, $productFlags): array {
            $empty = [
                'contractNumber' => null, 'clientName' => null, 'productName' => null,
                'programName' => null, 'amount' => null,
                'propertyTitle' => null, 'contractTerm' => null, 'yearKV' => null,
                'productHasProperty' => true, 'productHasTerm' => true, 'productHasYearKv' => true,
            ];
            if (! $transactionId) return $empty;

            $tx = $transactions[$transactionId] ?? null;
            if (! $tx) return $empty;
            $contract = $tx->contract ? ($contracts[$tx->contract] ?? null) : null;
            if (! $contract) {
                return array_merge($empty, ['amount' => $tx->amount ?? null]);
            }
            $flags = $contract->product ? ($productFlags[$contract->product] ?? null) : null;
            $hasProperty = $flags ? (bool) $flags->has_property : true;
            $hasTerm = $flags ? (bool) $flags->has_term : true;
            $hasYearKv = $flags ? (bool) $flags->has_year_kv : true;
            $program = $contract->program ? ($programs[$contract->program] ?? null) : null;
            $propId = $program?->commissionCalcProperty;

            return [
                'contractNumber' => $contract->number ?? null,
                'clientName' => $contract->clientName ?? null,
                'productName' => $contract->productName ?? null,
                'programName' => $contract->programName ?? null,
                'amount' => $tx->amount ?? null,
                // Гейтим по флагу продукта: если у продукта has_*=false —
                // отдаём null. Это позволяет UI скрывать ячейку в строках,
                // где параметр не релевантен этому конкретному продукту.
                'propertyTitle' => $hasProperty && $propId ? ($properties[$propId] ?? null) : null,
                'contractTerm' => $hasTerm ? ($contract->term ?? null) : null,
                'yearKV' => $hasYearKv ? ($tx->score ?? null) : null,
                'productHasProperty' => $hasProperty,
                'productHasTerm' => $hasTerm,
                'productHasYearKv' => $hasYearKv,
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
                'propertyTitle' => $txData['propertyTitle'],
                'contractTerm' => $txData['contractTerm'],
                'yearKV' => $txData['yearKV'],
                'productHasProperty' => $txData['productHasProperty'],
                'productHasTerm' => $txData['productHasTerm'],
                'productHasYearKv' => $txData['productHasYearKv'],
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
                'propertyTitle' => $txData['propertyTitle'],
                'contractTerm' => $txData['contractTerm'],
                'yearKV' => $txData['yearKV'],
                'productHasProperty' => $txData['productHasProperty'],
                'productHasTerm' => $txData['productHasTerm'],
                'productHasYearKv' => $txData['productHasYearKv'],
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

        // Breakaway info from qualificationLog. Раньше блок появлялся только
        // при $qLogCurrent->gap=true → партнёры без отрыва не видели окно
        // вовсе и думали, что данные не подгрузились. Теперь возвращаем
        // breakaway всегда (если есть qualificationLog), с фактическими
        // значениями. Если отрыва нет — gapPercentage=0, frontend сам
        // решает рендерить «Не зафиксирован» или скрыть.
        // Breakaway: показываем всегда самую крупную ветку с её % от моего ГП.
        // Пороги (Бизнес-логика «Отрыв»):
        //   70% → ветка не учитывается в ГП родителя (удержание),
        //   90% → пул лидеров не выплачивается родителю.
        // Если qualificationLog уже зафиксировал branchWithGap (gap=true)
        // — берём оттуда. Иначе самостоятельно ищем ветку с max ГП.
        $breakaway = null;
        if ($qLogCurrent) {
            $hasGap = (bool) ($qLogCurrent->gap ?? false);
            $branchId = $qLogCurrent->branchWithGap;
            $branchName = $branchId
                ? DB::table('consultant')->where('id', $branchId)->value('personName')
                : null;
            $branchGv = (float) ($qLogCurrent->branchWithGapGroupVolume ?? 0);
            $gapPct = (float) ($qLogCurrent->gapValuePercentage ?? 0);
            $gapVal = (float) ($qLogCurrent->gapValue ?? 0);

            // Имени ветки нет — пробуем найти.
            //
            // Раньше fallback стрелял только при $branchId=null, но в проде
            // встречаются ещё две ситуации:
            //   1. branchWithGap указывает на консультанта, у которого
            //      consultant.personName=NULL (orphan-импорт из Directual).
            //   2. branchWithGap NULL, но branchWithGapGroupVolume есть —
            //      ветка с отрывом находится глубже первой линии (например,
            //      сильный лидер 2-3 уровня вниз). Прямой потомок может
            //      вообще не иметь qualificationLog за этот месяц.
            //
            // Решение: триггер на empty($branchName). Сначала ищем по прямой
            // линии, затем рекурсивным CTE по всему поддереву. Берём
            // партнёра с максимальным groupVolumeCumulative за месяц.
            if (empty($branchName)) {
                $myGv = (float) ($qLogCurrent->groupVolumeCumulative ?? 0);
                $monthStart = $month . '-01';
                $monthEnd = date('Y-m-d', strtotime("$monthStart +1 month"));

                $top = DB::table('qualificationLog as ql')
                    ->join('consultant as c', 'c.id', '=', 'ql.consultant')
                    ->where('c.inviter', $consultant->id)
                    ->whereNull('c.dateDeleted')
                    ->whereNull('ql.dateDeleted')
                    ->where('ql.date', '>=', $monthStart)
                    ->where('ql.date', '<', $monthEnd)
                    ->orderByDesc('ql.groupVolumeCumulative')
                    ->select(['c.id', 'c.personName', 'ql.groupVolumeCumulative as gv'])
                    ->first();

                // Если в первой линии никого с qLog за период не нашли —
                // спускаемся ниже по дереву через рекурсивный CTE.
                if (! $top || (float) ($top->gv ?? 0) <= 0) {
                    $top = DB::selectOne('
                        WITH RECURSIVE descendants AS (
                            SELECT id FROM consultant
                            WHERE inviter = ? AND "dateDeleted" IS NULL
                            UNION ALL
                            SELECT c.id FROM consultant c
                            JOIN descendants d ON c.inviter = d.id
                            WHERE c."dateDeleted" IS NULL
                        )
                        SELECT c.id, c."personName", ql."groupVolumeCumulative" AS gv
                        FROM descendants d
                        JOIN consultant c ON c.id = d.id
                        JOIN "qualificationLog" ql ON ql.consultant = c.id
                        WHERE ql.date >= ? AND ql.date < ?
                          AND ql."dateDeleted" IS NULL
                        ORDER BY ql."groupVolumeCumulative" DESC NULLS LAST
                        LIMIT 1
                    ', [$consultant->id, $monthStart, $monthEnd]);
                }

                if ($top && (float) ($top->gv ?? 0) > 0) {
                    $branchId = $top->id;
                    $branchName = $top->personName ?: ('Партнёр #' . $top->id);
                    // Если qLog уже сохранил branchWithGapGroupVolume —
                    // оставляем его как достоверное значение и не пересчитываем
                    // gapPct/gapVal, чтобы цифры в карточке не «прыгали»
                    // относительно того, что зафиксировал ночной finalize.
                    if ($branchGv <= 0) {
                        $branchGv = (float) $top->gv;
                        $gapPct = $myGv > 0 ? round($branchGv / $myGv * 100, 2) : 0;
                        $gapVal = max(0, $branchGv - $myGv * 0.7);
                    }
                }
            }

            // Последний fallback: если имя так и не нашли, но есть branchId
            // — выводим хотя бы «Партнёр #ID», чтобы карточка не выглядела
            // битой. UI всё равно вынужден рендерить «—» если null.
            if (empty($branchName) && $branchId) {
                $branchName = 'Партнёр #' . $branchId;
            }

            $breakaway = [
                'hasGap' => $hasGap,
                'partnerName' => $branchName,
                'groupVolume' => round($branchGv, 2),
                'gapPercentage' => round($gapPct, 2),
                'gapValue' => round($gapVal, 2),
                // Пороги, чтобы UI рисовал шкалу с подписями
                'holdThresholdPercent' => 70,
                'poolThresholdPercent' => 90,
                // Удержание ГП родителя начиная с 70%
                'gpHeld' => $gapPct >= 70,
                // Пул блокируется начиная с 90%
                'poolBlocked' => $gapPct >= 90,
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
                    // «Прочие начисления» в commission (transaction IS NULL)
                    // выражены в РУБЛЯХ, не в баллах. Раньше отдавалось как
                    // otherAccrualsPoints — UI показывал «Прочие (баллы)»,
                    // что вводило в заблуждение.
                    'otherAccrualsRub' => round($otherAccruals->sum(fn ($c) => (float) ($c->amount ?? 0)), 2),
                    'otherAccrualsPoints' => round($otherAccruals->sum(fn ($c) => (float) ($c->groupBonus ?? 0)), 2),
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
