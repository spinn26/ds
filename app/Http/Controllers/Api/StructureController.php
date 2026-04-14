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

                $members = $query->orderBy('personName')->limit(50)->get()
                    ->map(fn ($c) => $this->formatMember($c));

                return response()->json(['data' => $members->values()]);
            }

            // No search → show top-level structure (consultants without inviter)
            $topLevel = Consultant::whereNull('dateDeleted')
                ->where(function ($q) {
                    $q->whereNull('inviter')->orWhere('inviter', 0);
                })
                ->orderBy('personName')
                ->get()
                ->map(fn ($c) => $this->formatMember($c));

            $members = $this->applyFilters($topLevel, $request);
            return response()->json(['data' => $members->values()]);
        }

        // Consultant → show own team
        if (! $consultant) {
            return response()->json(['data' => []]);
        }

        // Children = consultants whose inviter is this consultant
        $members = Consultant::where('inviter', $consultant->id)
            ->whereNull('dateDeleted')
            ->get()
            ->map(fn ($c) => $this->formatMember($c));

        // Apply filters
        $members = $this->applyFilters($members, $request);

        return response()->json(['data' => $members->values()]);
    }

    public function children(Request $request, int $consultantId): JsonResponse
    {
        $members = Consultant::where('inviter', $consultantId)
            ->whereNull('dateDeleted')
            ->get()
            ->map(fn ($c) => $this->formatMember($c));

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

    private function formatMember(Consultant $c): array
    {
        $statusLevel = null;
        if ($c->status_and_lvl) {
            $statusLevel = DB::table('status_levels')->where('id', $c->status_and_lvl)->first();
        }

        $qLog = DB::table('qualificationLog')
            ->where('consultant', $c->id)
            ->whereNull('dateDeleted')
            ->orderByDesc('date')
            ->first();

        $clientCount = DB::table('client')
            ->where('consultant', $c->id)
            ->where('active', true)
            ->count();

        $contractCount = DB::table('contract')
            ->where('consultant', $c->id)
            ->whereNull('deletedAt')
            ->count();

        // Count children by inviter (not consultantStructure)
        $subCount = DB::table('consultant')
            ->where('inviter', $c->id)
            ->whereNull('dateDeleted')
            ->count();

        $activityName = $c->activity
            ? (is_object($c->activity) ? $c->activity->label() : DB::table('directory_of_activities')->where('id', $c->activity)->value('name'))
            : null;

        // Person data from WebUser for birth date and city
        $person = $c->person
            ? DB::table('person')->where('id', $c->person)->first()
            : null;
        $birthDate = $person?->birthDate ?? null;
        $cityName = $person && $person?->city
            ? DB::table('city')->where('id', $person->city)->value('cityNameRu')
            : null;

        // Count subordinate partners
        $residentCount = $subCount;
        $fcCount = 0;

        return [
            'id' => $c->id,
            'personName' => $c->personName,
            'active' => $c->active,
            'activityId' => is_object($c->activity) ? $c->activity->value : $c->activity,
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
            'residentCount' => $residentCount,
            'fcCount' => $fcCount,
            'inviterName' => $c->inviterName,
            'birthDate' => $birthDate,
            'city' => $cityName,
            'dateActivity' => $c->dateActivity?->format('d.m.Y'),
        ];
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
