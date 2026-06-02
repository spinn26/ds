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

        // Breakaway info — единый формат с FinanceReportService::summary.breakaway,
        // чтобы UI на дашборде и в финрезе использовал одну и ту же карточку
        // («Отрыва нет» / «≥70% удержание ГП» / «≥90% блокировка пула»).
        // Возвращаем всегда (если есть qualificationLog) с топ-веткой, даже
        // когда отрыва формально нет — фронту нужно показать прогресс-шкалу.
        $breakaway = $this->buildBreakawaySummary($consultant->id, $month, $currentQLog);

        // Personal/Group volumes for period
        // qualificationLog обновляется ночным финализом (partners:check-statuses
        // в 02:00). После ручной фиксации/импорта transaction новые commission
        // уже есть, но snapshot ещё нет — карточка «ОП по ГП» показывала 0,
        // прогресс-бар плана не двигался.
        // Берём max(snapshot, live SUM) — паттерн из Реестра выплат.
        $snapshotPersonal = (float) ($periodQLog->personalVolume ?? $consultant->personalVolume ?? 0);
        $snapshotGroup = (float) ($periodQLog->groupVolume ?? $consultant->groupVolume ?? 0);

        $livePersonal = (float) DB::table('commission')
            ->where('consultant', $consultant->id)
            ->where('dateMonth', $month)
            ->whereNull('deletedAt')
            ->sum('personalVolume');
        $liveGroup = (float) DB::table('commission')
            ->where('consultant', $consultant->id)
            ->where('dateMonth', $month)
            ->whereNull('deletedAt')
            ->sum('groupVolume');

        $personalVolume = max($snapshotPersonal, $livePersonal);
        $groupVolume = max($snapshotGroup, $liveGroup);

        // НГП (накопительный) = последний НЕ-NULL groupVolumeCumulative с
        // date <= конец периода. Carry-forward делает дашборд согласованным с
        // отчётом и невосприимчивым к строкам финализа Отрыв/ОП
        // (MonthlyPenaltyRunner вставляет строку monthEnd с NULL cumulative,
        // которая иначе перебивает реальный снапшот как самая свежая).
        $cumulativeAsOf = fn ($asOf) => (float) (DB::table('qualificationLog')
            ->where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->whereNotNull('groupVolumeCumulative')
            ->where('date', '<=', $asOf)
            ->orderByDesc('date')
            ->value('groupVolumeCumulative') ?? $consultant->groupVolumeCumulative ?? 0);

        $groupVolumeCumulative = $cumulativeAsOf($periodEnd);

        // Previous period values for comparison
        $prevPersonalVolume = $prevQLog->personalVolume ?? 0;
        $prevGroupVolume = $prevQLog->groupVolume ?? 0;
        $prevGroupVolumeCumulative = $cumulativeAsOf($prevPeriodEnd);

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

        // Прогресс активационного/годового периода берём из надёжного
        // периодного ЛП (тот же $personalVolume, что в карточке «Личные
        // продажи»), а не из сырой колонки consultant.personalVolume —
        // она денормализована и может содержать устаревшее/несопоставимое
        // значение (на проде встречались суммы в млн, при пороге 500).
        if (isset($statusInfo['currentPoints'])) {
            $statusInfo['currentPoints'] = round((float) $personalVolume, 2);
        }

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

    /**
     * Сводка по самой крупной ветке партнёра — для отрисовки шкалы
     * «Отрыва нет / ≥70% удержание ГП / ≥90% блокировка пула».
     *
     * Логика идентична FinanceReportService::breakaway:
     *   • если qualificationLog зафиксировал branchWithGap (gap=true) —
     *     берём оттуда;
     *   • иначе самостоятельно ищем топ-ветку по qualificationLog
     *     текущего месяца (сначала первая линия, потом всё поддерево
     *     через рекурсивный CTE).
     *
     * Возвращает null, если для партнёра нет qualificationLog
     * (новичок, ещё не было пересчёта). Фронт рендерит карточку только
     * когда $breakaway !== null.
     *
     * @return array{
     *   hasGap:bool, partnerName:?string, groupVolume:float,
     *   gapPercentage:float, gapValue:float,
     *   holdThresholdPercent:int, poolThresholdPercent:int,
     *   gpHeld:bool, poolBlocked:bool
     * }|null
     */
    private function buildBreakawaySummary(int $consultantId, string $month, ?QualificationLog $qLogCurrent): ?array
    {
        if (! $qLogCurrent) return null;

        $hasGap   = (bool) ($qLogCurrent->gap ?? false);
        $branchId = $qLogCurrent->branchWithGap;
        $branchName = $branchId
            ? DB::table('consultant')->where('id', $branchId)->value('personName')
            : null;
        $branchGv = (float) ($qLogCurrent->branchWithGapGroupVolume ?? 0);
        $gapPct   = (float) ($qLogCurrent->gapValuePercentage ?? 0);
        $gapVal   = (float) ($qLogCurrent->gapValue ?? 0);

        // Если в qLog имени нет (orphan-импорт / отрыв глубже первой
        // линии / branchWithGap=null) — ищем сами.
        if (empty($branchName)) {
            $myGv = (float) ($qLogCurrent->groupVolumeCumulative ?? 0);
            $monthStart = $month . '-01';
            $monthEnd = date('Y-m-d', strtotime("$monthStart +1 month"));

            $top = DB::table('qualificationLog as ql')
                ->join('consultant as c', 'c.id', '=', 'ql.consultant')
                ->where('c.inviter', $consultantId)
                ->whereNull('c.dateDeleted')
                ->whereNull('ql.dateDeleted')
                ->where('ql.date', '>=', $monthStart)
                ->where('ql.date', '<', $monthEnd)
                ->orderByDesc('ql.groupVolumeCumulative')
                ->select(['c.id', 'c.personName', 'ql.groupVolumeCumulative as gv'])
                ->first();

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
                ', [$consultantId, $monthStart, $monthEnd]);
            }

            if ($top && (float) ($top->gv ?? 0) > 0) {
                $branchId = $top->id;
                $branchName = $top->personName ?: ('Партнёр #' . $top->id);
                if ($branchGv <= 0) {
                    $branchGv = (float) $top->gv;
                    $gapPct = $myGv > 0 ? round($branchGv / $myGv * 100, 2) : 0;
                    $gapVal = max(0, $branchGv - $myGv * 0.7);
                }
            }
        }

        if (empty($branchName) && $branchId) {
            $branchName = 'Партнёр #' . $branchId;
        }

        return [
            'hasGap'        => $hasGap,
            'partnerName'   => $branchName,
            'groupVolume'   => round($branchGv, 2),
            'gapPercentage' => round($gapPct, 2),
            'gapValue'      => round($gapVal, 2),
            'holdThresholdPercent' => 70,
            'poolThresholdPercent' => 90,
            'gpHeld'      => $gapPct >= 70,
            'poolBlocked' => $gapPct >= 90,
        ];
    }
}
