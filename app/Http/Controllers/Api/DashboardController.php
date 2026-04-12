<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Consultant;
use App\Models\QualificationLog;
use App\Services\PartnerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        private readonly PartnerStatusService $statusService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('person', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        // Period filter (default: current month)
        $month = $request->input('month', now()->format('Y-m'));
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

        // Team consultants (structure)
        $teamConsultantIds = DB::table('consultantStructure')
            ->where('parent', $consultant->id)
            ->pluck('child')
            ->toArray();
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

        // Personal/Group volumes for period
        $personalVolume = $periodQLog->personalVolume ?? $consultant->personalVolume ?? 0;
        $groupVolume = $periodQLog->groupVolume ?? $consultant->groupVolume ?? 0;
        $groupVolumeCumulative = $currentQLog->groupVolumeCumulative ?? $consultant->groupVolumeCumulative ?? 0;

        // Previous period values for comparison
        $prevPersonalVolume = $prevQLog->personalVolume ?? 0;
        $prevGroupVolume = $prevQLog->groupVolume ?? 0;
        $prevGroupVolumeCumulative = $prevQLog->groupVolumeCumulative ?? 0;

        // --- Partner counts by activity status (new) ---
        $partnerCounts = $this->getPartnerCountsByStatus($consultant->id, $teamConsultantIds);

        // Previous period partner counts for comparison
        $prevPartnerCounts = $this->getPrevPartnerCounts($consultant->id, $teamConsultantIds, $prevPeriodEnd);

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
            ->where('status.title', 'Финансовый консультант')
            ->count();

        $totalConsultants = Consultant::whereIn('id', $teamConsultantIds)
            ->where('active', true)
            ->count();

        // Status info (countdown, deadlines)
        $statusInfo = $this->statusService->getStatusInfo($consultant);

        return response()->json([
            'consultant' => [
                'id' => $consultant->id,
                'personName' => $consultant->personName,
                'participantCode' => $consultant->participantCode,
                'active' => $consultant->active,
                'statusName' => $statusName ?? 'Резидент',
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
            'period' => $month,
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
                'groupVolumeCumulative' => $l->groupVolumeCumulative ?? 0,
                'otrif' => $l->otrif ?? 0,
                'pool' => $l->pool ?? 0,
                'dsShare' => $l->dsShare ?? 0,
            ]);

        return response()->json($levels);
    }

    /**
     * Счётчики партнёров в команде по статусу активности.
     */
    private function getPartnerCountsByStatus(int $consultantId, array $teamIds): array
    {
        $counts = Consultant::whereIn('id', $teamIds)
            ->where('id', '!=', $consultantId)
            ->select('activity', DB::raw('count(*) as cnt'))
            ->groupBy('activity')
            ->pluck('cnt', 'activity')
            ->toArray();

        return [
            'total' => array_sum($counts),
            'registered' => $counts[PartnerActivity::Registered->value] ?? 0,
            'active' => $counts[PartnerActivity::Active->value] ?? 0,
            'inactive' => $counts[PartnerActivity::Inactive->value] ?? 0,
            'terminated' => $counts[PartnerActivity::Terminated->value] ?? 0,
            'excluded' => $counts[PartnerActivity::Excluded->value] ?? 0,
        ];
    }

    /**
     * Счётчики партнёров на конец предыдущего периода (для сравнения).
     * Упрощённая версия: считаем текущих с dateCreated до конца прошлого периода.
     */
    private function getPrevPartnerCounts(int $consultantId, array $teamIds, \Carbon\Carbon $prevEnd): array
    {
        $counts = Consultant::whereIn('id', $teamIds)
            ->where('id', '!=', $consultantId)
            ->where('dateCreated', '<=', $prevEnd)
            ->select('activity', DB::raw('count(*) as cnt'))
            ->groupBy('activity')
            ->pluck('cnt', 'activity')
            ->toArray();

        return [
            'total' => array_sum($counts),
            'registered' => $counts[PartnerActivity::Registered->value] ?? 0,
            'active' => $counts[PartnerActivity::Active->value] ?? 0,
            'inactive' => $counts[PartnerActivity::Inactive->value] ?? 0,
            'terminated' => $counts[PartnerActivity::Terminated->value] ?? 0,
            'excluded' => $counts[PartnerActivity::Excluded->value] ?? 0,
        ];
    }
}
