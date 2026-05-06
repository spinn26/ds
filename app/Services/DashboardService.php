<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Consultant;
use App\Models\QualificationLog;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private readonly PartnerStatusService $statusService,
        private readonly ConsultantService $consultantService,
    ) {}

    /**
     * Get full dashboard data for a consultant.
     */
    public function getDashboardData(Consultant $consultant, string $month): array
    {
        // Period filter
        $periodStart = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        // Current qualification
        $currentQLog = QualificationLog::where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->orderByDesc('date')
            ->first();

        // Qualification for the selected period
        $periodQLog = QualificationLog::where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->where('date', '>=', $periodStart)
            ->where('date', '<=', $periodEnd)
            ->orderByDesc('date')
            ->first();

        // Previous period qualification (for comparison)
        $prevPeriodStart = $periodStart->copy()->subMonth()->startOfMonth();
        $prevPeriodEnd = $prevPeriodStart->copy()->endOfMonth();
        $prevQLog = QualificationLog::where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->where('date', '>=', $prevPeriodStart)
            ->where('date', '<=', $prevPeriodEnd)
            ->orderByDesc('date')
            ->first();

        // Client counts (all clients, not just active — matches legacy platform)
        $myClientsCount = Client::where('consultant', $consultant->id)->count();
        $myClientsActive = Client::where('consultant', $consultant->id)->where('active', true)->count();

        // Team consultants (full tree — all levels deep)
        $teamConsultantIds = $this->consultantService->getAllDescendants($consultant->id);
        $teamConsultantIds[] = $consultant->id;

        $teamClientsCount = Client::whereIn('consultant', $teamConsultantIds)->count();
        $teamClientsActive = Client::whereIn('consultant', $teamConsultantIds)->where('active', true)->count();

        // Capital under management (from clientsIndicators)
        $capitalUsd = DB::table('clientsIndicators')
            ->join('client', 'clientsIndicators.client', '=', 'client.id')
            ->whereIn('client.consultant', $teamConsultantIds)
            ->where('clientsIndicators.indicator', 1)
            ->sum('clientsIndicators.valueUsd');

        // Batch-load all status_levels in one query (instead of 3 separate)
        $nominalStatusLevel = null;
        $calcStatusLevel = null;
        $nextLevel = null;
        if ($currentQLog) {
            $levelIds = array_filter([
                $currentQLog->nominalLevel ?? null,
                $currentQLog->calculationLevel ?? null,
            ]);
            $allLevels = DB::table('status_levels')->get()->keyBy('id');

            if ($currentQLog->nominalLevel) {
                $nominalStatusLevel = $allLevels[$currentQLog->nominalLevel] ?? null;
            }
            if ($currentQLog->calculationLevel) {
                $calcStatusLevel = $allLevels[$currentQLog->calculationLevel] ?? null;
            }

            if (!$nominalStatusLevel && $calcStatusLevel) $nominalStatusLevel = $calcStatusLevel;
            if (!$calcStatusLevel && $nominalStatusLevel) $calcStatusLevel = $nominalStatusLevel;

            // Per .claude/specs/✅Квалификации.md Part 2 §2: «Единая квалификация».
            // Directual's legacy split (nominalLevel vs calculationLevel) is
            // retired — partners get one level per month. Prefer the higher
            // of the two; it's what the partner actually earned by НГП.
            // Keeping both fields in the response for backward compat, but
            // they're now always equal.
            if ($nominalStatusLevel && $calcStatusLevel
                && ($nominalStatusLevel->level ?? 0) > ($calcStatusLevel->level ?? 0)
            ) {
                $calcStatusLevel = $nominalStatusLevel;
            }

            if ($nominalStatusLevel) {
                $nextLevelNum = ($nominalStatusLevel->level ?? 0) + 1;
                $nextLevel = $allLevels->first(fn ($l) => $l->level === $nextLevelNum);
            }
        }

        $statusLevel = $calcStatusLevel;

        // Fallback per ✅Бизнес-логика «ОП по ГП»: если qualificationLog
        // за период ещё не сформирован (партнёр новый, или month_close
        // не отработал), берём status_and_lvl из consultant — он
        // обновляется in-place синхронно с расчётами квалификаций.
        // Без этого fallback'а блок «ОП по ГП» молча пропадал в начале
        // месяца, до первой переоценки.
        if (! $statusLevel && $consultant->status_and_lvl) {
            $statusLevel = DB::table('status_levels')->where('id', $consultant->status_and_lvl)->first();
        }

        // Consultant status name (batch with breakaway branch name)
        $lookupIds = array_filter([$consultant->status ? $consultant->status : null]);
        $statusName = $consultant->status
            ? DB::table('status')->where('id', $consultant->status)->value('title')
            : null;

        // Ambassador products
        $ambassadorProducts = $consultant->ambassadorProductNames;

        // Breakaway info (отрыв) from qualificationLog
        $breakaway = null;
        if ($currentQLog && $currentQLog->gap) {
            $branchName = $currentQLog->branchWithGap
                ? DB::table('consultant')->where('id', $currentQLog->branchWithGap)->value('personName')
                : null;
            $breakaway = [
                'gap' => true,
                'gapValue' => round((float) ($currentQLog->gapValue ?? 0), 2),
                'gapValuePercentage' => round((float) ($currentQLog->gapValuePercentage ?? 0), 2),
                'branchWithGap' => $currentQLog->branchWithGap,
                'branchWithGapName' => $branchName,
                'branchWithGapGroupVolume' => round((float) ($currentQLog->branchWithGapGroupVolume ?? 0), 2),
            ];
        }

        // Personal/Group volumes for period
        $personalVolume = $periodQLog->personalVolume ?? $consultant->personalVolume ?? 0;
        $groupVolume = $periodQLog->groupVolume ?? $consultant->groupVolume ?? 0;
        $groupVolumeCumulative = $currentQLog->groupVolumeCumulative ?? $consultant->groupVolumeCumulative ?? 0;

        // Previous period values for comparison
        $prevPersonalVolume = $prevQLog->personalVolume ?? 0;
        $prevGroupVolume = $prevQLog->groupVolume ?? 0;
        $prevGroupVolumeCumulative = $prevQLog->groupVolumeCumulative ?? 0;

        // Partner counts by activity status
        $partnerCounts = $this->consultantService->getPartnerCountsByStatus($consultant->id, $teamConsultantIds);

        // Previous period partner counts for comparison
        $prevPartnerCounts = $this->consultantService->getPrevPartnerCounts($consultant->id, $teamConsultantIds, $prevPeriodEnd);

        // First-line counts (via inviter chain — matches legacy platform)
        $firstLineAll = DB::table('consultant')
            ->where('inviter', $consultant->id)
            ->whereNull('dateDeleted')
            ->count();

        $firstLineActive = DB::table('consultant')
            ->where('inviter', $consultant->id)
            ->whereNull('dateDeleted')
            ->where('active', true)
            ->count();

        $totalConsultants = count($teamConsultantIds);
        $totalConsultantsActive = Consultant::whereIn('id', $teamConsultantIds)
            ->where('active', true)
            ->count();

        // Status info (countdown, deadlines)
        $statusInfo = $this->statusService->getStatusInfo($consultant);

        // Mandatory GP plan fulfillment (ОП по ГП) — from Expert onwards
        $mandatoryPlan = null;
        if ($statusLevel && ($statusLevel->mandatoryGP ?? 0) > 0) {
            $mandatoryGP = (float) $statusLevel->mandatoryGP;
            $currentGP = round((float) $groupVolume, 2);
            $fulfillment = $mandatoryGP > 0 ? min($currentGP / $mandatoryGP, 1.0) : 1.0;
            $fulfilled = $currentGP >= $mandatoryGP;

            // Commission reduction when plan not met: 20% reduction from ОП
            $commissionReduction = 0;
            if (!$fulfilled) {
                $commissionReduction = 20;
            }

            $mandatoryPlan = [
                'mandatoryGP' => $mandatoryGP,
                'currentGP' => $currentGP,
                'fulfillment' => round($fulfillment * 100, 1),
                'fulfilled' => $fulfilled,
                'commissionReduction' => $commissionReduction,
            ];
        }

        // Pool eligibility (from TOP FC = level 6 onwards).
        // Per spec ✅Расчет пула §6.4: пул не выплачивается если
        //   (а) ОП по ГП не выполнен на 100% — или
        //   (б) у партнёра отрыв ≥ 90% (одна ветка занимает >90% от ГП).
        $poolInfo = null;
        if ($statusLevel && ($statusLevel->pool ?? 0) > 0) {
            $opFulfilled = $mandatoryPlan ? (bool) $mandatoryPlan['fulfilled'] : true;
            $gapPct = (float) ($currentQLog->gapValuePercentage ?? 0);
            $gapDisqualifies = $gapPct > 90.0;
            $poolEligible = $opFulfilled && ! $gapDisqualifies;
            $reason = null;
            if (! $opFulfilled)         $reason = 'ОП по ГП не выполнен на 100%';
            elseif ($gapDisqualifies)   $reason = sprintf('Отрыв %.0f%% > 90%%', $gapPct);
            $poolInfo = [
                'poolPercent' => (float) $statusLevel->pool,
                'eligible' => $poolEligible,
                'reason' => $reason,
            ];
        }

        // Breakaway rules (отрыв) — structured info
        $breakawayRules = null;
        if ($statusLevel && ($statusLevel->otrif ?? 0) > 0) {
            $breakawayRules = [
                'threshold' => (float) $statusLevel->otrif,
                'tiers' => [
                    ['branches' => 1, 'points' => 7500, 'reduction' => 75],
                    ['branches' => 2, 'points' => 5000, 'reduction' => 50],
                    ['branches' => 3, 'points' => 1000, 'reduction' => 25],
                    ['branches' => 4, 'points' => 2500, 'reduction' => 25],
                ],
            ];
        }

        return [
            'consultant' => [
                'id' => $consultant->id,
                'personName' => $consultant->personName,
                'participantCode' => $consultant->participantCode,
                'active' => $consultant->active,
                'statusName' => $statusName ?? 'Партнёр',
                'activityName' => $consultant->activityLabel(),
                'activityId' => $consultant->activity?->value,
                'ambassadorProducts' => $ambassadorProducts,
            ],
            'statusInfo' => $statusInfo,
            'qualification' => [
                // «Единая квалификация» (spec ✅Квалификации.md §2). После
                // унификации выше nominalStatusLevel === calcStatusLevel,
                // поэтому primary поле — просто `level`. Старые nominalLevel /
                // calculationLevel оставлены для обратной совместимости с
                // фронтом, но всегда указывают на тот же уровень.
                'level' => $calcStatusLevel ? [
                    'id' => $calcStatusLevel->id,
                    'level' => $calcStatusLevel->level,
                    'title' => $calcStatusLevel->title,
                    'percent' => $calcStatusLevel->percent,
                    'groupVolume' => $calcStatusLevel->groupVolume ?? 0,
                    'mandatoryGP' => $calcStatusLevel->mandatoryGP ?? 0,
                    'groupVolumeCumulative' => $calcStatusLevel->groupVolumeCumulative ?? 0,
                    'personalVolume' => $calcStatusLevel->personalVolume ?? 0,
                    'otrif' => $calcStatusLevel->otrif ?? 0,
                    'pool' => $calcStatusLevel->pool ?? 0,
                    'dsShare' => $calcStatusLevel->dsShare ?? 0,
                ] : null,
                // BC aliases — точно то же, что и `level`.
                'nominalLevel' => $calcStatusLevel ? [
                    'id' => $calcStatusLevel->id,
                    'level' => $calcStatusLevel->level,
                    'title' => $calcStatusLevel->title,
                    'percent' => $calcStatusLevel->percent,
                    'groupVolume' => $calcStatusLevel->groupVolume ?? 0,
                    'mandatoryGP' => $calcStatusLevel->mandatoryGP ?? 0,
                    'groupVolumeCumulative' => $calcStatusLevel->groupVolumeCumulative ?? 0,
                    'personalVolume' => $calcStatusLevel->personalVolume ?? 0,
                    'otrif' => $calcStatusLevel->otrif ?? 0,
                    'pool' => $calcStatusLevel->pool ?? 0,
                    'dsShare' => $calcStatusLevel->dsShare ?? 0,
                ] : null,
                'calculationLevel' => $calcStatusLevel ? [
                    'id' => $calcStatusLevel->id,
                    'level' => $calcStatusLevel->level,
                    'title' => $calcStatusLevel->title,
                    'percent' => $calcStatusLevel->percent,
                ] : null,
                // Всегда false — spec «Единая квалификация» убрала этот split.
                'levelsDontMatch' => false,
                'nextLevel' => $nextLevel ? [
                    'id' => $nextLevel->id,
                    'level' => $nextLevel->level,
                    'title' => $nextLevel->title,
                    'percent' => $nextLevel->percent,
                    'groupVolume' => $nextLevel->groupVolume ?? 0,
                    'mandatoryGP' => $nextLevel->mandatoryGP ?? 0,
                    'groupVolumeCumulative' => $nextLevel->groupVolumeCumulative ?? 0,
                    'personalVolume' => $nextLevel->personalVolume ?? 0,
                    'otrif' => $nextLevel->otrif ?? 0,
                    'pool' => $nextLevel->pool ?? 0,
                    'dsShare' => $nextLevel->dsShare ?? 0,
                ] : null,
            ],
            'volumes' => [
                'personalVolume' => round((float) $personalVolume, 2),
                'groupVolume' => round((float) $groupVolume, 2),
                'groupVolumeCumulative' => round((float) $groupVolumeCumulative, 2),
                'prevPersonalVolume' => round((float) $prevPersonalVolume, 2),
                'prevGroupVolume' => round((float) $prevGroupVolume, 2),
                'prevGroupVolumeCumulative' => round((float) $prevGroupVolumeCumulative, 2),
            ],
            'team' => [
                'myClients' => $myClientsCount,
                'myClientsActive' => $myClientsActive,
                'teamClients' => $teamClientsCount,
                'teamClientsActive' => $teamClientsActive,
                'firstLineAll' => $firstLineAll,
                'firstLineActive' => $firstLineActive,
                'totalPartners' => $totalConsultants,
                'totalPartnersActive' => $totalConsultantsActive,
                'capitalUsd' => round((float) $capitalUsd, 2),
            ],
            'partners' => $partnerCounts,
            'prevPartners' => $prevPartnerCounts,
            'breakaway' => $breakaway,
            'breakawayRules' => $breakawayRules,
            'mandatoryPlan' => $mandatoryPlan,
            'poolInfo' => $poolInfo,
            'period' => $month,
        ];
    }
}
