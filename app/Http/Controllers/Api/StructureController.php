<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StructureController extends Controller
{
    /**
     * Структура команды — 1 линия (прямые дети).
     * Расширенные фильтры: ФИО, квалификация, уровень, статус активности,
     * ЛП/ГП/НГП диапазон, город, дата рождения.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRoles = array_map('trim', explode(',', $user->role ?? ''));
        $isStaff = array_intersect($userRoles, ['admin', 'backoffice', 'support', 'head', 'calculations', 'corrections']);
        $consultant = Consultant::where('webUser', $user->id)->first();

        // Staff without consultant role → show top-level consultants (no inviter) as tree roots
        if ($isStaff && ! in_array('consultant', $userRoles)) {
            // If searching — flat search across all consultants
            if ($request->filled('search')) {
                $query = Consultant::whereNull('dateDeleted')
                    ->where('personName', 'ilike', '%' . $request->search . '%');

                if ($request->filled('activity')) {
                    $activityIds = explode(',', $request->activity);
                    $query->whereIn('activity', $activityIds);
                }

                $rows = $query->orderBy('personName')->limit(50)->get();
                $members = $this->formatMembers($rows);

                return response()->json(['data' => $members->values()]);
            }

            // No search → show top-level structure (consultants without inviter)
            $topLevelRows = Consultant::whereNull('dateDeleted')
                ->where(function ($q) {
                    $q->whereNull('inviter')->orWhere('inviter', 0);
                })
                ->orderBy('personName')
                ->get();
            $topLevel = $this->formatMembers($topLevelRows);

            $members = $this->applyFilters($topLevel, $request);
            return response()->json(['data' => $members->values()]);
        }

        // Consultant → show own team
        if (! $consultant) {
            return response()->json(['data' => []]);
        }

        // Children = consultants whose inviter is this consultant
        $rows = Consultant::where('inviter', $consultant->id)
            ->whereNull('dateDeleted')
            ->get();
        $members = $this->formatMembers($rows);

        // Apply filters
        $members = $this->applyFilters($members, $request);

        return response()->json(['data' => $members->values()]);
    }

    public function children(Request $request, int $consultantId): JsonResponse
    {
        $rows = Consultant::where('inviter', $consultantId)
            ->whereNull('dateDeleted')
            ->get();
        $members = $this->formatMembers($rows);

        return response()->json(['data' => $members->values()]);
    }

    /**
     * Справочник уровней квалификации (для фильтра).
     */
    public function qualificationLevels(): JsonResponse
    {
        $levels = DB::table('status_levels')
            ->orderBy('level')
            ->get()
            ->map(fn ($l) => ['id' => $l->id, 'level' => $l->level, 'title' => $l->title]);

        return response()->json($levels);
    }

    /**
     * Справочник статусов активности (для фильтра).
     */
    public function activityStatuses(): JsonResponse
    {
        $statuses = DB::table('directory_of_activities')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);

        return response()->json($statuses);
    }

    /**
     * Batch-format a collection of consultants (avoids N+1 queries).
     */
    private function formatMembers($consultants)
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

        return $consultants->map(function ($c) use ($statusLevels, $qLogs, $clientCounts, $contractCounts, $subCounts, $activityNames, $persons, $cities) {
            $statusLevel = $c->status_and_lvl ? ($statusLevels[$c->status_and_lvl] ?? null) : null;
            $qLog = $qLogs[$c->id] ?? null;
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
                'inviterName' => $c->inviterName,
                'birthDate' => $birthDate,
                'city' => $cityName,
                'dateActivity' => $c->dateActivity?->format('d.m.Y'),
            ];
        });
    }

    private function applyFilters($members, Request $request)
    {
        // ФИО
        if ($request->filled('search')) {
            $search = mb_strtolower($request->search);
            $members = $members->filter(fn ($m) => str_contains(mb_strtolower($m['personName']), $search));
        }

        // Статус активности (множественный)
        if ($request->filled('activity')) {
            $activityIds = is_array($request->activity) ? $request->activity : explode(',', $request->activity);
            $members = $members->filter(fn ($m) => in_array($m['activityId'], $activityIds));
        }

        // Квалификация (множественный)
        if ($request->filled('qualification')) {
            $levels = is_array($request->qualification) ? $request->qualification : explode(',', $request->qualification);
            $members = $members->filter(fn ($m) => $m['qualification'] && in_array($m['qualification']['level'], $levels));
        }

        // ЛП диапазон
        if ($request->filled('lp_min')) {
            $members = $members->filter(fn ($m) => $m['personalVolume'] >= (float) $request->lp_min);
        }
        if ($request->filled('lp_max')) {
            $members = $members->filter(fn ($m) => $m['personalVolume'] <= (float) $request->lp_max);
        }

        // ГП диапазон
        if ($request->filled('gp_min')) {
            $members = $members->filter(fn ($m) => $m['groupVolume'] >= (float) $request->gp_min);
        }
        if ($request->filled('gp_max')) {
            $members = $members->filter(fn ($m) => $m['groupVolume'] <= (float) $request->gp_max);
        }

        // НГП диапазон
        if ($request->filled('ngp_min')) {
            $members = $members->filter(fn ($m) => $m['groupVolumeCumulative'] >= (float) $request->ngp_min);
        }
        if ($request->filled('ngp_max')) {
            $members = $members->filter(fn ($m) => $m['groupVolumeCumulative'] <= (float) $request->ngp_max);
        }

        // Город
        if ($request->filled('city')) {
            $city = mb_strtolower($request->city);
            $members = $members->filter(fn ($m) => $m['city'] && str_contains(mb_strtolower($m['city']), $city));
        }

        return $members;
    }
}
