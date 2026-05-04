<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\ConsultantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StructureController extends Controller
{
    public function __construct(
        private readonly ConsultantService $consultantService,
    ) {}

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

                $rows = $query->orderBy('personName')->limit(100)->get();
                $members = $this->consultantService->formatMembers($rows);
                // Let the service map status/activity aliases and other filters
                $members = $this->consultantService->applyFilters($members, $request->all());

                return response()->json(['data' => $members->values()]);
            }

            // No search → show top-level structure (consultants without inviter).
            // Терминированных-сирот в корне НЕ показываем: после терминации
            // их структура улетает наставнику, поэтому видеть их «висящими»
            // в верхушке нет смысла — они только засоряют дерево.
            $topLevelRows = Consultant::whereNull('dateDeleted')
                ->where(function ($q) {
                    $q->whereNull('inviter')->orWhere('inviter', 0);
                })
                ->where('activity', '!=', PartnerActivity::Terminated->value)
                ->orderBy('personName')
                ->get();
            $topLevel = $this->consultantService->formatMembers($topLevelRows);

            $members = $this->consultantService->applyFilters($topLevel, $request->all());
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
        $members = $this->consultantService->formatMembers($rows);

        // Apply filters
        $members = $this->consultantService->applyFilters($members, $request->all());

        return response()->json(['data' => $members->values()]);
    }

    public function children(Request $request, int $consultantId): JsonResponse
    {
        $target = Consultant::whereNull('dateDeleted')->findOrFail($consultantId);
        $this->authorize('viewTree', $target);

        $rows = Consultant::where('inviter', $consultantId)
            ->whereNull('dateDeleted')
            ->get();
        $members = $this->consultantService->formatMembers($rows);

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
     * Автокомплит по городам из справочника city.
     */
    public function cities(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $query = DB::table('city')->select('id', 'cityNameRu')->orderBy('cityNameRu');
        if ($q !== '') {
            $query->where('cityNameRu', 'ilike', '%' . $q . '%');
        }

        return response()->json(
            $query->limit(30)->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->cityNameRu])
        );
    }
}
