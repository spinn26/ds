<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConsultantService
{
    /**
     * Batch-format a collection of consultants (avoids N+1 queries).
     */
    public function formatMembers(Collection $consultants): Collection
    {
        if ($consultants->isEmpty()) {
            return collect();
        }

        $ids = $consultants->pluck('id')->filter()->unique();

        // Batch load status_levels
        $statusLevelIds = $consultants->pluck('status_and_lvl')->filter()->unique();
        $statusLevels = $statusLevelIds->isNotEmpty()
            ? DB::table('status_levels')->whereIn('id', $statusLevelIds)->get()->keyBy('id')
            : collect();

        // Batch load latest qualificationLog per consultant
        $qLogLatestIds = DB::table('qualificationLog')
            ->whereIn('consultant', $ids)
            ->whereNull('dateDeleted')
            ->selectRaw('MAX(id) as id')
            ->groupBy('consultant')
            ->pluck('id');
        $qLogs = $qLogLatestIds->isNotEmpty()
            ? DB::table('qualificationLog')->whereIn('id', $qLogLatestIds)->get()->keyBy('consultant')
            : collect();

        // Batch count active clients per consultant
        $clientCounts = DB::table('client')
            ->whereIn('consultant', $ids)
            ->where('active', true)
            ->select('consultant', DB::raw('count(*) as cnt'))
            ->groupBy('consultant')
            ->pluck('cnt', 'consultant');

        // Batch count contracts per consultant
        $contractCounts = DB::table('contract')
            ->whereIn('consultant', $ids)
            ->whereNull('deletedAt')
            ->select('consultant', DB::raw('count(*) as cnt'))
            ->groupBy('consultant')
            ->pluck('cnt', 'consultant');

        // Batch count children (by inviter)
        $subCounts = DB::table('consultant')
            ->whereIn('inviter', $ids)
            ->whereNull('dateDeleted')
            ->select('inviter', DB::raw('count(*) as cnt'))
            ->groupBy('inviter')
            ->pluck('cnt', 'inviter');

        // Batch load activity names
        $activityIds = $consultants->map(fn ($c) => is_object($c->activity) ? $c->activity->value : $c->activity)->filter()->unique();
        $activityNames = $activityIds->isNotEmpty()
            ? DB::table('directory_of_activities')->whereIn('id', $activityIds)->pluck('name', 'id')
            : collect();

        // Batch load person data
        $personIds = $consultants->pluck('person')->filter()->unique();
        $persons = $personIds->isNotEmpty()
            ? DB::table('person')->whereIn('id', $personIds)->get()->keyBy('id')
            : collect();

        // Batch load cities
        $cityIds = $persons->pluck('city')->filter()->unique();
        $cities = $cityIds->isNotEmpty()
            ? DB::table('city')->whereIn('id', $cityIds)->pluck('cityNameRu', 'id')
            : collect();

        // Index status_levels by level number for qualificationLog fallback
        $statusLevelsByLevel = $statusLevels->keyBy('level');

        return $consultants->map(function ($c) use ($statusLevels, $statusLevelsByLevel, $qLogs, $clientCounts, $contractCounts, $subCounts, $activityNames, $persons, $cities) {
            $statusLevel = $c->status_and_lvl ? ($statusLevels[$c->status_and_lvl] ?? null) : null;
            $qLog = $qLogs[$c->id] ?? null;

            // Fallback: if consultant.status_and_lvl is empty but the latest
            // qualificationLog carries a level, resolve it to status_levels by
            // level number (status_levels has 10 tiers keyed by .level).
            if (! $statusLevel && $qLog) {
                $levelNum = $qLog->levelNew ?? $qLog->calculationLevel ?? $qLog->nominalLevel ?? null;
                if ($levelNum) {
                    $statusLevel = $statusLevelsByLevel[$levelNum] ?? null;
                }
            }
            $clientCount = $clientCounts[$c->id] ?? 0;
            $contractCount = $contractCounts[$c->id] ?? 0;
            $subCount = $subCounts[$c->id] ?? 0;

            $activityId = is_object($c->activity) ? $c->activity->value : $c->activity;
            $activityName = null;
            if ($c->activity) {
                $activityName = is_object($c->activity) ? $c->activity->label() : ($activityNames[$c->activity] ?? null);
            }

            $person = $c->person ? ($persons[$c->person] ?? null) : null;
            $birthDate = $person?->birthDate ?? null;
            $cityName = ($person && ($person->city ?? null)) ? ($cities[$person->city] ?? null) : null;

            return [
                'id' => $c->id,
                'personName' => $c->personName,
                'active' => $c->active,
                'activityId' => $activityId,
                'activityName' => $activityName ?? ($c->active ? 'Активный' : 'Неактивен'),
                'qualification' => $statusLevel ? [
                    'level' => $statusLevel->level,
                    'title' => $statusLevel->title,
                ] : null,
                'level' => $c->structureLevel,
                'personalVolume' => round((float) ($qLog->personalVolume ?? $c->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($qLog->groupVolume ?? $c->groupVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($qLog->groupVolumeCumulative ?? $c->groupVolumeCumulative ?? 0), 2),
                'clientCount' => $clientCount,
                'contractCount' => $contractCount,
                'hasChildren' => $subCount > 0,
                'residentCount' => $subCount,
                'fcCount' => 0,
                'partnersCount' => $subCount,
                'inviterName' => $c->inviterName,
                'birthDate' => $birthDate,
                'city' => $cityName,
                'dateActivity' => $c->dateActivity?->format('d.m.Y'),
                'yearPeriodEnd' => $c->yearPeriodEnd?->format('d.m.Y'),
                'activationDeadline' => $c->activationDeadline?->format('d.m.Y'),
            ];
        });
    }

    /**
     * Apply collection-level filters to formatted members.
     */
    public function applyFilters(Collection $members, array $filters): Collection
    {
        // ФИО
        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $members = $members->filter(fn ($m) => str_contains(mb_strtolower($m['personName']), $search));
        }

        // Статус активности (множественный) — принимаем ID или строковые алиасы
        // из UI: 'active', 'registered', 'terminated', 'excluded'
        $statusAlias = [
            'active' => \App\Enums\PartnerActivity::Active->value,
            'registered' => \App\Enums\PartnerActivity::Registered->value,
            'terminated' => \App\Enums\PartnerActivity::Terminated->value,
            'excluded' => \App\Enums\PartnerActivity::Excluded->value,
        ];
        $rawActivity = $filters['activity'] ?? $filters['status'] ?? null;
        if (! empty($rawActivity)) {
            $raw = is_array($rawActivity) ? $rawActivity : explode(',', $rawActivity);
            $activityIds = array_map(
                fn ($v) => is_numeric($v) ? (int) $v : ($statusAlias[$v] ?? null),
                $raw
            );
            $activityIds = array_filter($activityIds, fn ($v) => $v !== null);
            if ($activityIds) {
                $members = $members->filter(fn ($m) => in_array($m['activityId'], $activityIds));
            }
        }

        // Квалификация (множественный)
        if (! empty($filters['qualification'])) {
            $levels = is_array($filters['qualification']) ? $filters['qualification'] : explode(',', $filters['qualification']);
            $members = $members->filter(fn ($m) => $m['qualification'] && in_array($m['qualification']['level'], $levels));
        }

        // ЛП диапазон
        if (isset($filters['lp_min']) && $filters['lp_min'] !== '' && $filters['lp_min'] !== null) {
            $members = $members->filter(fn ($m) => $m['personalVolume'] >= (float) $filters['lp_min']);
        }
        if (isset($filters['lp_max']) && $filters['lp_max'] !== '' && $filters['lp_max'] !== null) {
            $members = $members->filter(fn ($m) => $m['personalVolume'] <= (float) $filters['lp_max']);
        }

        // ГП диапазон
        if (isset($filters['gp_min']) && $filters['gp_min'] !== '' && $filters['gp_min'] !== null) {
            $members = $members->filter(fn ($m) => $m['groupVolume'] >= (float) $filters['gp_min']);
        }
        if (isset($filters['gp_max']) && $filters['gp_max'] !== '' && $filters['gp_max'] !== null) {
            $members = $members->filter(fn ($m) => $m['groupVolume'] <= (float) $filters['gp_max']);
        }

        // НГП диапазон
        if (isset($filters['ngp_min']) && $filters['ngp_min'] !== '' && $filters['ngp_min'] !== null) {
            $members = $members->filter(fn ($m) => $m['groupVolumeCumulative'] >= (float) $filters['ngp_min']);
        }
        if (isset($filters['ngp_max']) && $filters['ngp_max'] !== '' && $filters['ngp_max'] !== null) {
            $members = $members->filter(fn ($m) => $m['groupVolumeCumulative'] <= (float) $filters['ngp_max']);
        }

        // Город
        if (! empty($filters['city'])) {
            $city = mb_strtolower($filters['city']);
            $members = $members->filter(fn ($m) => $m['city'] && str_contains(mb_strtolower($m['city']), $city));
        }

        return $members;
    }

    /**
     * Get partner counts by activity status for a team.
     */
    public function getPartnerCountsByStatus(int $consultantId, array $teamIds): array
    {
        $counts = \App\Models\Consultant::whereIn('id', $teamIds)
            ->where('id', '!=', $consultantId)
            ->select('activity', DB::raw('count(*) as cnt'))
            ->groupBy('activity')
            ->pluck('cnt', 'activity')
            ->toArray();

        return [
            'total' => array_sum($counts),
            'registered' => $counts[\App\Enums\PartnerActivity::Registered->value] ?? 0,
            'active' => $counts[\App\Enums\PartnerActivity::Active->value] ?? 0,
            'inactive' => 0, // Статус удалён
            'terminated' => $counts[\App\Enums\PartnerActivity::Terminated->value] ?? 0,
            'excluded' => $counts[\App\Enums\PartnerActivity::Excluded->value] ?? 0,
        ];
    }

    /**
     * Get partner counts at end of previous period (for comparison).
     */
    public function getPrevPartnerCounts(int $consultantId, array $teamIds, \Carbon\Carbon $prevEnd): array
    {
        $counts = \App\Models\Consultant::whereIn('id', $teamIds)
            ->where('id', '!=', $consultantId)
            ->where('dateCreated', '<=', $prevEnd)
            ->select('activity', DB::raw('count(*) as cnt'))
            ->groupBy('activity')
            ->pluck('cnt', 'activity')
            ->toArray();

        return [
            'total' => array_sum($counts),
            'registered' => $counts[\App\Enums\PartnerActivity::Registered->value] ?? 0,
            'active' => $counts[\App\Enums\PartnerActivity::Active->value] ?? 0,
            'inactive' => 0, // Статус удалён
            'terminated' => $counts[\App\Enums\PartnerActivity::Terminated->value] ?? 0,
            'excluded' => $counts[\App\Enums\PartnerActivity::Excluded->value] ?? 0,
        ];
    }

    /**
     * Recursively get all descendant consultant IDs (all levels deep).
     */
    public function getAllDescendants(int $parentId): array
    {
        $allIds = [];
        $currentLevel = [$parentId];

        for ($i = 0; $i < 20; $i++) {
            $children = DB::table('consultant')
                ->whereIn('inviter', $currentLevel)
                ->whereNull('dateDeleted')
                ->pluck('id')
                ->toArray();

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return array_unique($allIds);
    }

    /**
     * Get all team IDs (descendants + the consultant itself).
     */
    public function getTeamIds(int $consultantId): array
    {
        $ids = $this->getAllDescendants($consultantId);
        $ids[] = $consultantId;
        return $ids;
    }
}
