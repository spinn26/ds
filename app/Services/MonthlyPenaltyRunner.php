<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly penalty runner — applies §5 (detachment, OP shortfall, combo)
 * to the commission table for a given year/month.
 *
 * Flow for each consultant with level ≥ 3:
 *   1. Load all their group-commissions for the month (chainOrder ≥ 2).
 *   2. Group commissions by "first-line branch" — walk up inviter chain
 *      from the original seller until we hit a consultant whose inviter
 *      is this person; that child is the branch key.
 *   3. Run detachment multipliers on branch volumes (only if
 *      status_levels.otrif > 0, i.e. level ≥ 6).
 *   4. Run OP multiplier on total group volume vs status_levels.mandatoryGP
 *      (only if mandatoryGP > 0, i.e. level ≥ 3).
 *   5. Write per-commission reductions and a qualificationLog summary row.
 *
 * All writes are gated behind `applyWrite=true`. Preview mode returns the
 * same diff as a JSON-serialisable array without touching the DB.
 *
 * The frozen-period guard refuses to run (preview or write) on a month
 * already closed via PeriodFreezeService — spec ✅Комиссии §1.
 */
class MonthlyPenaltyRunner
{
    public function __construct(
        private readonly MonthlyFinaliser $finaliser,
        private readonly PeriodFreezeService $periodFreeze,
        private readonly CommissionCalculator $calculator,
    ) {}

    /**
     * @return array{
     *   year:int, month:int, applyWrite:bool,
     *   frozen:bool,
     *   processed:int, affected:int,
     *   consultants: list<array<string,mixed>>,
     * }
     */
    public function run(int $year, int $month, bool $applyWrite = false): array
    {
        // Исторические данные (< HISTORICAL_CUTOFF) неизменны — не пишем штрафы.
        // Read-режим (applyWrite=false, превью) разрешён.
        if ($applyWrite && CommissionCalculator::isHistorical(sprintf('%04d-%02d', $year, $month))) {
            return [
                'year' => $year,
                'month' => $month,
                'applyWrite' => $applyWrite,
                'frozen' => true,
                'processed' => 0,
                'affected' => 0,
                'consultants' => [],
                'error' => "Период {$month}.{$year} — исторический (< " . CommissionCalculator::HISTORICAL_CUTOFF . "), финализация не применяется",
            ];
        }

        if ($this->periodFreeze->isFrozen($year, $month)) {
            return [
                'year' => $year,
                'month' => $month,
                'applyWrite' => $applyWrite,
                'frozen' => true,
                'processed' => 0,
                'affected' => 0,
                'consultants' => [],
                'error' => "Период {$month}.{$year} закрыт — финализация не применяется",
            ];
        }

        // Pre-load the entire inviter map once (1-2k rows) instead of
        // N recursive lookups per consultant.
        $inviterMap = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->pluck('inviter', 'id')
            ->map(fn ($v) => $v === null ? null : (int) $v)
            ->toArray();

        // All consultants that can be subject to penalties: have a
        // qualification level with mandatoryGP > 0 (i.e. level ≥ 3).
        $candidates = DB::table('consultant as c')
            ->join('status_levels as sl', 'sl.id', '=', 'c.status_and_lvl')
            ->where('sl.level', '>=', 3)
            ->whereNull('c.dateDeleted')
            ->select([
                'c.id',
                'c.personName',
                'c.status_and_lvl',
                'sl.level',
                'sl.percent',
                'sl.mandatoryGP',
                'sl.otrif',
            ])
            ->get();

        // Legacy format: dateMonth = "YYYY-MM" (not just "MM"), dateYear = "YYYY".
        $dateMonth = sprintf('%04d-%02d', $year, $month);
        $dateYear = (string) $year;
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth();

        // Pre-fetch ЛП партнёров одним запросом — иначе processConsultant
        // делает sum(personalVolume) для каждого из ~1030 партнёров,
        // что даёт ~1030 round-trip-ов на каждом ночном прогоне.
        $personalVolumes = DB::table('commission')
            ->where('chainOrder', 1)
            ->where('dateYear', $dateYear)
            ->where('dateMonth', $dateMonth)
            ->whereNull('deletedAt')
            ->whereIn('consultant', $candidates->pluck('id'))
            ->selectRaw('consultant, SUM("personalVolume") AS pv')
            ->groupBy('consultant')
            ->pluck('pv', 'consultant')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        $stats = [];
        $affectedTotal = 0;

        // ID партнёров, у которых реально применили штраф — после цикла
        // пересчитаем им consultantBalance, иначе snapshot останется
        // со старыми (до-штрафа) суммами amountRUB, и Реестр выплат
        // покажет неправильные «к выплате».
        $affectedConsultantIds = [];

        foreach ($candidates as $cons) {
            // Per-consultant atomarity: при applyWrite все записи одного
            // партнёра (UPDATE commission + delete/insert qualificationLog)
            // должны быть all-or-nothing, иначе сбой в середине оставляет
            // ledger полу-оштрафованным с выставленным флагом reduction.
            // В preview-режиме транзакция не нужна (записей нет).
            $run = fn () => $this->processConsultant(
                consultant: $cons,
                year: $year,
                month: $month,
                dateYear: $dateYear,
                dateMonth: $dateMonth,
                monthEnd: $monthEnd,
                inviters: $inviterMap,
                applyWrite: $applyWrite,
                personalVolume: (float) ($personalVolumes[$cons->id] ?? 0),
            );
            $result = $applyWrite ? DB::transaction($run) : $run();
            if ($result['affectedCommissions'] > 0) {
                $stats[] = $result;
                $affectedTotal += $result['affectedCommissions'];
                if ($applyWrite) {
                    $affectedConsultantIds[] = (int) $cons->id;
                }
            }
        }

        // Пересчёт consultantBalance для затронутых партнёров —
        // commission.amountRUB снижены штрафами, но snapshot в
        // consultantBalance.accruedTransactional остаётся старым,
        // если его не пересчитать. Иначе Реестр выплат покажет
        // «к выплате» БЕЗ учёта штрафов до следующего ручного rebuild'а.
        // Делаем после всех processConsultant — батчем по уникальным id.
        if ($applyWrite && $affectedConsultantIds) {
            foreach (array_unique($affectedConsultantIds) as $cid) {
                try {
                    $this->calculator->rebuildBalanceFor($cid, $dateMonth, $dateYear);
                } catch (\Throwable $e) {
                    \Log::warning('rebuildBalance after penalty failed', [
                        'consultant' => $cid,
                        'month' => $dateMonth,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'applyWrite' => $applyWrite,
            'frozen' => false,
            'processed' => $candidates->count(),
            'affected' => $affectedTotal,
            'consultants' => $stats,
        ];
    }

    /**
     * @param array<int,?int> $inviters  consultantId → inviterId
     */
    private function processConsultant(
        object $consultant,
        int $year,
        int $month,
        string $dateYear,
        string $dateMonth,
        Carbon $monthEnd,
        array $inviters,
        bool $applyWrite,
        float $personalVolume = 0.0,
    ): array {
        // Group commissions for this mentor in the target month.
        $commissions = DB::table('commission')
            ->where('consultant', $consultant->id)
            ->where('chainOrder', '>=', 2)
            ->where('dateYear', $dateYear)
            ->where('dateMonth', $dateMonth)
            ->whereNull('deletedAt')
            ->get();

        // $personalVolume пробрасывается из run() — pre-fetched одним
        // запросом для всех кандидатов. Per spec ✅Бизнес-логика §1:
        // ГП = ЛП + downline. ЛП не участвует в per-branch отрыве
        // (у себя нет «ветки»), но обязан попасть в totalGroupVolume.

        if ($commissions->isEmpty() && $personalVolume <= 0) {
            return $this->emptyResult($consultant);
        }

        // Bucket commissions by first-line branch under this mentor.
        // Seller id can live in `commissionFromOtherConsultant` (new) or
        // `consultantsChain` (legacy) — try both; otherwise the row ends up
        // as "unassigned" and only participates in the OP calc.
        $byBranch = [];
        $branchVolumes = [];
        $unassigned = [];
        foreach ($commissions as $c) {
            $sellerId = (int) ($c->commissionFromOtherConsultant ?? 0)
                ?: (int) ($c->consultantsChain ?? 0);
            $branchKey = $sellerId > 0
                ? $this->firstLineBranchUnder($sellerId, (int) $consultant->id, $inviters)
                : null;
            if ($branchKey === null) {
                $unassigned[] = $c;
                continue;
            }
            $byBranch[$branchKey][] = $c;
            $branchVolumes[$branchKey] = ($branchVolumes[$branchKey] ?? 0.0)
                + (float) $c->groupVolume;
        }

        // Detachment: only level ≥ 6 (otrif > 0).
        $otrif = (float) ($consultant->otrif ?? 0);
        $detachMults = ($otrif > 0 && !empty($branchVolumes))
            ? $this->finaliser->detachmentMultipliers($branchVolumes)
            : array_fill_keys(array_keys($branchVolumes), 1.0);

        // OP: only when mandatoryGP > 0. Per spec ✅Бизнес-логика §1:
        // ГП = ЛП + объёмы downline. ОП = минимальный ГП. Поэтому в
        // totalGroupVolume включаем и personalVolume самого партнёра
        // (chainOrder=1), и downline (chainOrder>=2). Unassigned-строки
        // (битый branch) тоже добавляем — они часть месячного ГП.
        $mandatoryGp = (float) ($consultant->mandatoryGP ?? 0);
        $totalGroupVolume = $personalVolume + array_sum($branchVolumes);
        foreach ($unassigned as $u) $totalGroupVolume += (float) $u->groupVolume;
        $opMult = $mandatoryGp > 0
            ? $this->finaliser->opMultiplier($totalGroupVolume, $mandatoryGp)
            : 1.0;

        // ВАЖНО: даже когда штрафа нет (opMult=1, нет отрыва), мы НЕ выходим
        // раньше — ниже всё равно пишем строку qualificationLog за месяц.
        // Это месячный снапшот квалификации (groupVolume + уровень + ОП-итог),
        // из которого «Расчёт пула» определяет, выполнил ли партнёр ОП.
        // Раньше строка писалась только при штрафе → партнёр, ВЫПОЛНИВШИЙ ОП,
        // не попадал в qualificationLog, и пул всегда показывал ему «ОП не
        // выполнен» (жалоба по июню: Денис с ЛП 1млн не определился).

        // Apply per commission.
        $affected = 0;
        $withheldTotal = 0.0;
        $updates = [];

        $applyToRow = function ($c, float $dm) use (&$affected, &$withheldTotal, &$updates, $opMult, $applyWrite) {
            $totalMult = $dm * $opMult;
            if ($totalMult >= 1.0) return;
            if ((bool) ($c->reduction ?? false)) return; // idempotent

            $originalRub = (float) $c->groupBonusRub;
            $newRub = $originalRub * $totalMult;
            $withheldTotal += $originalRub - $newRub;
            $affected++;

            $updates[] = [
                'id' => (int) $c->id,
                'originalRub' => $originalRub,
                'newRub' => $newRub,
                'detachMult' => $dm,
                'opMult' => $opMult,
            ];

            if ($applyWrite) {
                DB::table('commission')
                    ->where('id', $c->id)
                    ->update([
                        'reduction' => true,
                        'groupBonusRubBeforeGapReduction' => $originalRub,
                        'withheldPercent' => (1.0 - $totalMult) * 100.0,
                        'withheldForGap' => $dm < 1 ? ($originalRub * (1.0 - $dm)) : 0,
                        'withheldForCommission' => $opMult < 1
                            ? (($originalRub * $dm) * (1.0 - $opMult))
                            : 0,
                        'amountRUB' => $newRub,
                        'groupBonusRub' => $newRub,
                    ]);
            }
        };

        // Branched rows — detachment multiplier applies per branch.
        foreach ($byBranch as $branchKey => $rows) {
            $dm = $detachMults[$branchKey] ?? 1.0;
            foreach ($rows as $c) $applyToRow($c, $dm);
        }
        // Unassigned rows — no branch detach, just OP.
        foreach ($unassigned as $c) $applyToRow($c, 1.0);

        $gapBranchKey = array_search(0.5, $detachMults, true);
        if ($applyWrite) {
            // Идемпотентность: повторный прогон финализа за тот же месяц не
            // должен плодить дубли penalty-строк. Penalty-строка живёт на
            // конце месяца (monthEnd 23:59:59), а штатные снапшоты — на начале
            // месяца, поэтому удаление по date=monthEnd безопасно. Это также
            // самовосстанавливает старые дубли при следующем прогоне.
            DB::table('qualificationLog')
                ->where('consultant', $consultant->id)
                ->where('date', $monthEnd)
                ->delete();

            // Пишем строку qualificationLog ВСЕГДА (не только при штрафе) — это
            // месячный снапшот квалификации, по которому «Расчёт пула» видит
            // выполнение ОП. Раньше писалось лишь при штрафе → партнёр,
            // ВЫПОЛНИВШИЙ ОП, в qualificationLog не попадал, и пул показывал
            // ему «ОП не выполнен».
            //
            // Перенос накопительного ГП: строка не добавляет объём, поэтому
            // cumulative = последний НЕ-NULL до monthEnd (НГП не инфлейтим;
            // см. project-ngp-cumulative-fix). Без этого свежая строка с NULL
            // ломала бы НГП на дашборде.
            {
                $carryCumulative = DB::table('qualificationLog')
                    ->where('consultant', $consultant->id)
                    ->whereNull('dateDeleted')
                    ->whereNotNull('groupVolumeCumulative')
                    ->where('date', '<', $monthEnd)
                    ->orderByDesc('date')
                    ->value('groupVolumeCumulative');

                DB::table('qualificationLog')->insert([
                    'consultant' => $consultant->id,
                    'date' => $monthEnd,
                    'savingDate' => now(),
                    'gap' => $gapBranchKey !== false,
                    'gapValuePercentage' => $gapBranchKey !== false
                        ? round($branchVolumes[$gapBranchKey] / max($totalGroupVolume, 0.0001) * 100, 2)
                        : null,
                    'gapValue' => $gapBranchKey !== false ? $branchVolumes[$gapBranchKey] : null,
                    'branchWithGap' => $gapBranchKey !== false ? $gapBranchKey : null,
                    'result' => $this->buildResultLabel($opMult, $gapBranchKey),
                    'calculationLevel' => $consultant->status_and_lvl,
                    'nominalLevel' => $consultant->status_and_lvl,
                    // Месячный ЛП партнёра (SUM commission chainOrder=1 за месяц,
                    // пробрасывается из run()). Раньше не писался → снимок
                    // qualificationLog имел ЛП=NULL, и раздел «Квалификации»
                    // показывал 0 (инцидент «июнь нули»). Теперь снимок полный.
                    'personalVolume' => $personalVolume,
                    'groupVolume' => $totalGroupVolume,
                    'groupVolumeCumulative' => $carryCumulative,
                    'consultantPersonName' => $consultant->personName,
                    'commissionsToReduceCounter' => $affected,
                    'commissionsToReduceAmount' => (int) round($withheldTotal),
                    'createdAt' => now(),
                    'changedAt' => now(),
                ]);
            }
        }

        return [
            'id' => (int) $consultant->id,
            'personName' => $consultant->personName,
            'level' => (int) $consultant->level,
            'mandatoryGp' => $mandatoryGp,
            'otrif' => $otrif,
            'totalGroupVolume' => $totalGroupVolume,
            'branchVolumes' => $branchVolumes,
            'detachmentMultipliers' => $detachMults,
            'opMultiplier' => $opMult,
            'affectedCommissions' => $affected,
            'withheldTotalRub' => round($withheldTotal, 2),
            'unassignedCommissions' => count($unassigned),
            'updates' => $applyWrite ? [] : $updates, // preview payload
        ];
    }

    /**
     * From a seller, walk up the inviter chain until we hit a consultant
     * whose inviter == mentorId; that consultant is the branch key
     * under the mentor. Returns null if the seller is not in the mentor's
     * subtree (shouldn't happen for legit commission rows, but we guard
     * against broken inviter chains).
     *
     * @param array<int,?int> $inviters
     */
    private function firstLineBranchUnder(?int $sellerId, int $mentorId, array $inviters): ?int
    {
        if (!$sellerId) return null;
        if ($sellerId === $mentorId) return null;

        $current = $sellerId;
        $visited = [];
        while ($current !== null && !isset($visited[$current])) {
            $visited[$current] = true;
            $parent = $inviters[$current] ?? null;
            if ($parent === $mentorId) {
                return $current;
            }
            $current = $parent;
        }
        return null;
    }

    private function buildResultLabel(float $opMult, int|false $gapBranchKey): string
    {
        $parts = [];
        if ($gapBranchKey !== false) $parts[] = 'Отрыв >70%';
        if ($opMult < 1.0) $parts[] = 'Недобор ОП';
        return empty($parts) ? 'OK' : implode(' + ', $parts);
    }

    private function emptyResult(object $consultant, array $extra = []): array
    {
        return array_merge([
            'id' => (int) $consultant->id,
            'personName' => $consultant->personName,
            'level' => (int) $consultant->level,
            'affectedCommissions' => 0,
        ], $extra);
    }
}
