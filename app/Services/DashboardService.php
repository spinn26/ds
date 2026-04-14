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

        // Client counts
        $myClientsCount = Client::where('consultant', $consultant->id)
            ->where('active', true)
            ->count();

        // Team consultants (full tree — all levels deep)
        $teamConsultantIds = $this->consultantService->getAllDescendants($consultant->id);
        $teamConsultantIds[] = $consultant->id;

        $teamClientsCount = Client::whereIn('consultant', $teamConsultantIds)
            ->where('active', true)
            ->count();

        // Capital under management (from clientsIndicators)
        $capitalUsd = DB::table('clientsIndicators')
            ->join('client', 'clientsIndicators.client', '=', 'client.id')
            ->whereIn('client.consultant', $teamConsultantIds)
            ->where('clientsIndicators.indicator', 1)
            ->sum('clientsIndicators.valueUsd');

        // Status level info
        $statusLevel = null;
        $nextLevel = null;
        if ($currentQLog) {
            $statusLevel = DB::table('status_levels')
                ->where('id', $currentQLog->calculationLevel ?? $currentQLog->nominalLevel)
                ->first();

            if ($statusLevel) {
                $nextLevel = DB::table('status_levels')
                    ->where('level', ($statusLevel->level ?? 0) + 1)
                    ->first();
            }
        }

        // Consultant status name
        $statusName = null;
        if ($consultant->status) {
            $statusName = DB::table('status')->where('id', $consultant->status)->value('title');
        }

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

        // First-line counts
        $firstLineResidents = DB::table('consultantStructure')
            ->join('consultant', 'consultantStructure.child', '=', 'consultant.id')
            ->where('consultantStructure.parent', $consultant->id)
            ->where('consultant.active', true)
            ->count();

        $firstLineConsultants = DB::table('consultantStructure')
            ->join('consultant', 'consultantStructure.child', '=', 'consultant.id')
            ->join('status', 'consultant.status', '=', 'status.id')
            ->where('consultantStructure.parent', $consultant->id)
            ->where('consultant.active', true)
            ->where('status.title', 'Партнёр')
            ->count();

        $totalConsultants = Consultant::whereIn('id', $teamConsultantIds)
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

        // Pool eligibility (from TOP FC = level 6 onwards)
        $poolInfo = null;
        if ($statusLevel && ($statusLevel->pool ?? 0) > 0) {
            $poolEligible = true;
            // Must meet 80% of GP plan to qualify for pool
            if ($mandatoryPlan) {
                $poolEligible = $mandatoryPlan['fulfillment'] >= 80;
            }
            $poolInfo = [
                'poolPercent' => (float) $statusLevel->pool,
                'eligible' => $poolEligible,
                'reason' => $poolEligible ? null : 'План ГП выполнен менее чем на 80%',
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
                'ambassadorProducts' => $ambassadorProducts,
            ],
            'statusInfo' => $statusInfo,
            'qualification' => [
                'nominalLevel' => $statusLevel ? [
                    'id' => $statusLevel->id,
                    'level' => $statusLevel->level,
                    'title' => $statusLevel->title,
                    'percent' => $statusLevel->percent,
                    'groupVolume' => $statusLevel->groupVolume ?? 0,
                    'mandatoryGP' => $statusLevel->mandatoryGP ?? 0,
                    'groupVolumeCumulative' => $statusLevel->groupVolumeCumulative ?? 0,
                    'personalVolume' => $statusLevel->personalVolume ?? 0,
                    'otrif' => $statusLevel->otrif ?? 0,
                    'pool' => $statusLevel->pool ?? 0,
                    'dsShare' => $statusLevel->dsShare ?? 0,
                ] : null,
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
                'teamClients' => $teamClientsCount,
                'firstLineResidents' => $firstLineResidents,
                'totalResidents' => count($teamConsultantIds),
                'firstLineConsultants' => $firstLineConsultants,
                'totalConsultants' => $totalConsultants,
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
