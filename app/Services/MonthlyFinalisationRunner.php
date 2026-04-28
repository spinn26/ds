<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator: применяет штрафы Отрыв/ОП к commission-строкам за месяц,
 * используя pure-math сервис {@see MonthlyFinaliser}.
 *
 * Per spec ✅Бизнес-логика расчётов §5:
 *   §5.1 Отрыв (от TOP FC, otrif=70): ветка >70% от ГП → её commission RUB ×0.5
 *   §5.2 ОП (от Expert): GPфакт < mandatoryGP → Σ ГП-комиссий ×0.8
 *   §5.3 Combo: сначала Отрыв per-branch, потом ОП к итогу
 *
 * Идемпотентно: повторный запуск откатывает прошлые withheld* и считает
 * заново от brutto (хранится в groupBonusRubBeforeGapReduction).
 *
 * Заморозка: на закрытом периоде падает с ошибкой, ничего не меняет.
 */
class MonthlyFinalisationRunner
{
    public function __construct(
        private readonly MonthlyFinaliser $finaliser,
        private readonly PeriodFreezeService $periodFreeze,
    ) {}

    /**
     * @return array{total:int, otrifApplied:int, opApplied:int, errors:array}
     */
    public function applyForMonth(int $year, int $month): array
    {
        if ($this->periodFreeze->isFrozen($year, $month)) {
            return [
                'error' => "Период {$month}.{$year} закрыт — финализация невозможна",
                'total' => 0, 'otrifApplied' => 0, 'opApplied' => 0, 'errors' => [],
            ];
        }

        // commission.dateMonth хранится в формате 'Y-m' (см. TransactionImportController:284),
        // dateYear — отдельно как 'Y'.
        $monthStr = sprintf('%04d-%02d', $year, $month);
        $yearStr = (string) $year;

        $consultantIds = DB::table('commission')
            ->where('dateMonth', $monthStr)
            ->where('dateYear', $yearStr)
            ->where('type', 'transaction')
            ->whereNull('deletedAt')
            ->where('chainOrder', '>=', 2)
            ->distinct()
            ->pluck('consultant')
            ->filter()
            ->all();

        $stats = ['total' => count($consultantIds), 'otrifApplied' => 0, 'opApplied' => 0, 'errors' => []];

        foreach ($consultantIds as $consultantId) {
            try {
                $r = $this->applyForConsultant((int) $consultantId, $year, $month);
                if ($r['otrifApplied']) $stats['otrifApplied']++;
                if ($r['opApplied']) $stats['opApplied']++;
            } catch (\Throwable $e) {
                Log::warning('MonthlyFinalisationRunner failed', [
                    'consultant' => $consultantId, 'year' => $year, 'month' => $month,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors'][] = ['consultant' => $consultantId, 'error' => $e->getMessage()];
            }
        }

        return $stats;
    }

    private function applyForConsultant(int $consultantId, int $year, int $month): array
    {
        // commission.dateMonth хранится в формате 'Y-m' (см. TransactionImportController:284),
        // dateYear — отдельно как 'Y'.
        $monthStr = sprintf('%04d-%02d', $year, $month);
        $yearStr = (string) $year;

        $level = $this->getLevelAtMonth($consultantId, $year, $month);
        $otrif = (float) ($level->otrif ?? 0);
        $mandatoryGP = (float) ($level->mandatoryGP ?? 0);

        // Если уровень <= Pro (нет ни ОП, ни Отрыва) — просто откатываем прошлые штрафы.
        if ($otrif <= 0 && $mandatoryGP <= 0) {
            $this->resetWithholdings($consultantId, $yearStr, $monthStr);
            return ['otrifApplied' => false, 'opApplied' => false];
        }

        return DB::transaction(function () use ($consultantId, $yearStr, $monthStr, $otrif, $mandatoryGP) {
            $this->resetWithholdings($consultantId, $yearStr, $monthStr);

            $rows = DB::table('commission')
                ->where('consultant', $consultantId)
                ->where('dateMonth', $monthStr)
                ->where('dateYear', $yearStr)
                ->where('type', 'transaction')
                ->whereNull('deletedAt')
                ->where('chainOrder', '>=', 2)
                ->get();

            if ($rows->isEmpty()) {
                return ['otrifApplied' => false, 'opApplied' => false];
            }

            $branchRoot = $this->buildBranchRootMap($consultantId);
            $branchVolumes = [];
            $branchRows = [];

            foreach ($rows as $r) {
                $rootId = $branchRoot[$r->commissionFromOtherConsultant ?? 0] ?? 0;
                if (! $rootId) continue;
                $branchVolumes[$rootId] = ($branchVolumes[$rootId] ?? 0) + (float) $r->groupVolume;
                $branchRows[$rootId][] = $r;
            }

            $totalGroupVolume = array_sum($branchVolumes);
            $otrifApplied = false;
            $opApplied = false;

            // === ШАГ 1: Отрыв per-branch ===
            if ($otrif > 0 && $totalGroupVolume > 0) {
                $multipliers = $this->finaliser->detachmentMultipliers($branchVolumes);
                foreach ($multipliers as $rootId => $mult) {
                    if ($mult >= 1.0) continue;
                    foreach ($branchRows[$rootId] ?? [] as $r) {
                        $brutto = (float) ($r->groupBonusRub ?? $r->amountRUB ?? 0);
                        $withheld = round($brutto * (1 - $mult), 2);
                        DB::table('commission')->where('id', $r->id)->update([
                            'groupBonusRubBeforeGapReduction' => $brutto,
                            'withheldForGap' => $withheld,
                            'amountRUB' => round($brutto - $withheld, 2),
                            'groupBonusRub' => round($brutto - $withheld, 2),
                        ]);
                    }
                    $otrifApplied = true;
                }
            }

            // === ШАГ 2: ОП к итогу ===
            if ($mandatoryGP > 0) {
                $opMult = $this->finaliser->opMultiplier($totalGroupVolume, $mandatoryGP);
                if ($opMult < 1.0) {
                    $rowsAfterStep1 = DB::table('commission')
                        ->where('consultant', $consultantId)
                        ->where('dateMonth', $monthStr)
                        ->where('dateYear', $yearStr)
                        ->where('type', 'transaction')
                        ->whereNull('deletedAt')
                        ->where('chainOrder', '>=', 2)
                        ->get();
                    foreach ($rowsAfterStep1 as $r) {
                        $current = (float) ($r->amountRUB ?? 0);
                        $opCut = round($current * (1 - $opMult), 2);
                        DB::table('commission')->where('id', $r->id)->update([
                            'groupBonusRubBeforeGapReduction' => DB::raw(
                                'COALESCE("groupBonusRubBeforeGapReduction", "amountRUB" + COALESCE("withheldForGap", 0))'
                            ),
                            'withheldForCommission' => DB::raw('COALESCE("withheldForCommission", 0) + ' . $opCut),
                            'amountRUB' => round($current - $opCut, 2),
                            'groupBonusRub' => round($current - $opCut, 2),
                        ]);
                    }
                    $opApplied = true;
                }
            }

            return ['otrifApplied' => $otrifApplied, 'opApplied' => $opApplied];
        });
    }

    /** partnerId → id первой линии (branch root) для consultantId. */
    private function buildBranchRootMap(int $consultantId): array
    {
        $direct = DB::table('consultant')
            ->where('inviter', $consultantId)
            ->whereNull('dateDeleted')
            ->pluck('id')
            ->all();

        $map = [];
        foreach ($direct as $rootId) {
            $map[$rootId] = $rootId;
            $stack = [$rootId];
            while ($stack) {
                $cur = array_pop($stack);
                $children = DB::table('consultant')
                    ->where('inviter', $cur)
                    ->whereNull('dateDeleted')
                    ->pluck('id')
                    ->all();
                foreach ($children as $c) {
                    if (! isset($map[$c])) {
                        $map[$c] = $rootId;
                        $stack[] = $c;
                    }
                }
            }
        }
        return $map;
    }

    private function resetWithholdings(int $consultantId, string $year, string $month): void
    {
        DB::table('commission')
            ->where('consultant', $consultantId)
            ->where('dateMonth', $month)
            ->where('dateYear', $year)
            ->where('type', 'transaction')
            ->where('chainOrder', '>=', 2)
            ->whereNotNull('groupBonusRubBeforeGapReduction')
            ->update([
                'amountRUB' => DB::raw('"groupBonusRubBeforeGapReduction"'),
                'groupBonusRub' => DB::raw('"groupBonusRubBeforeGapReduction"'),
                'withheldForGap' => 0,
                'withheldForCommission' => 0,
            ]);
    }

    /** Уровень партнёра НА конец отчётного месяца (по qualificationLog). */
    private function getLevelAtMonth(int $consultantId, int $year, int $month): ?object
    {
        $endOfMonth = sprintf('%04d-%02d-%02d', $year, $month, date('t', mktime(0, 0, 0, $month, 1, $year)));

        $qLog = DB::table('qualificationLog')
            ->where('consultant', $consultantId)
            ->whereNull('dateDeleted')
            ->where('date', '<=', $endOfMonth)
            ->orderByDesc('date')
            ->first();
        $levelId = $qLog?->nominalLevel ?? $qLog?->calculationLevel ?? null;

        if (! $levelId) {
            $cons = DB::table('consultant')->where('id', $consultantId)->first();
            $levelId = $cons?->status_and_lvl ?? null;
        }

        if (! $levelId) return null;
        return DB::table('status_levels')->where('id', $levelId)->first();
    }
}
