<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Controller;
use App\Models\BankRequisite;
use App\Models\Consultant;
use App\Models\Requisite;
use App\Services\PartnerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDataController extends Controller
{
    public function __construct(
        private readonly PartnerStatusService $statusService,
    ) {}

    /** Партнёры — список с фильтрами */
    public function partners(Request $request): JsonResponse
    {
        $query = Consultant::query()->whereNull('dateDeleted');

        if ($request->filled('search')) {
            $query->where('personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('activity')) {
            $query->where('activity', $request->activity);
        }
        if ($request->filled('active')) {
            $query->where('active', $request->active === 'true');
        }

        $total = $query->count();
        $rows = $query->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        // Batch load WebUser data
        $webUserIds = $rows->pluck('webUser')->filter()->unique();
        $webUsers = $webUserIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $webUserIds)->get()->keyBy('id')
            : collect();

        // Batch load person data
        $personIds = $rows->pluck('person')->filter()->unique();
        $persons = $personIds->isNotEmpty()
            ? DB::table('person')->whereIn('id', $personIds)->get()->keyBy('id')
            : collect();

        // Batch check which persons are also clients
        $personClients = $personIds->isNotEmpty()
            ? DB::table('client')->whereIn('person', $personIds)->pluck('person')->unique()->flip()
            : collect();

        // Batch load status titles
        $statusIds = $rows->pluck('status')->filter()->unique();
        $statusTitles = $statusIds->isNotEmpty()
            ? DB::table('status')->whereIn('id', $statusIds)->pluck('title', 'id')
            : collect();

        $partners = $rows->map(function ($c) use ($webUsers, $persons, $personClients, $statusTitles) {
                $webUser = $c->webUser ? ($webUsers[$c->webUser] ?? null) : null;
                $personData = $c->person ? ($persons[$c->person] ?? null) : $webUser;
                $isClient = $c->person ? isset($personClients[$c->person]) : false;
                $platformAccess = $webUser && ! ($webUser->isBlocked ?? false);

                return [
                    'id' => $c->id,
                    'personId' => $c->person,
                    'personName' => $c->personName,
                    'active' => $c->active,
                    'activityName' => $c->activityLabel(),
                    'activityId' => $c->activity?->value,
                    'statusName' => $c->status ? ($statusTitles[$c->status] ?? null) : null,
                    'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                    'groupVolumeCumulative' => round((float) ($c->groupVolumeCumulative ?? 0), 2),
                    'participantCode' => $c->participantCode,
                    'dateCreated' => $c->dateCreated?->format('d.m.Y'),
                    'terminationCount' => $c->terminationCount ?? 0,
                    'email' => $personData?->email ?? null,
                    'phone' => $personData?->phone ?? null,
                    'birthDate' => $personData?->birthDate ?? null,
                    'inviterName' => $c->inviterName,
                    'isClient' => $isClient,
                    'platformAccess' => $platformAccess,
                ];
            });

        return response()->json(['data' => $partners, 'total' => $total]);
    }

    /** Смена статуса активности партнёра */
    public function changePartnerStatus(Request $request, int $id): JsonResponse
    {
        $consultant = Consultant::findOrFail($id);

        $request->validate([
            'action' => 'required|in:activate,terminate,exclude,re-register',
            'reason' => 'nullable|string|max:500',
        ]);

        $result = match ($request->action) {
            'activate' => $this->statusService->activate($consultant) ? 'Активирован' : 'Не удалось активировать',
            'terminate' => $this->statusService->terminate($consultant, $request->reason ?? '')->label(),
            'exclude' => tap('Исключён', fn () => $this->statusService->exclude($consultant, $request->reason ?? '')),
            're-register' => $this->statusService->reRegister($consultant) ? 'Перерегистрирован' : 'Не удалось перерегистрировать',
        };

        return response()->json(['message' => $result]);
    }

    /** Статусы партнёров — сводка + детальный список */
    public function partnerStatuses(Request $request): JsonResponse
    {
        // Сводка по статусам
        $counts = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->select('activity', DB::raw('count(*) as cnt'))
            ->groupBy('activity')
            ->pluck('cnt', 'activity')
            ->toArray();

        $statuses = DB::table('directory_of_activities')->orderBy('id')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'count' => $counts[$s->id] ?? 0,
            ]);

        // Детальный список с дедлайнами
        $detailQuery = DB::table('consultant')->whereNull('dateDeleted');

        if ($request->filled('search')) {
            $detailQuery->where('personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('activity')) {
            $detailQuery->where('activity', $request->activity);
        }

        $detailTotal = $detailQuery->count();
        $detailRows = $detailQuery->orderBy('personName')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        // Batch load activity names
        $activityIds = $detailRows->pluck('activity')->filter()->unique();
        $activityNames = $activityIds->isNotEmpty()
            ? DB::table('directory_of_activities')->whereIn('id', $activityIds)->pluck('name', 'id')
            : collect();

        $details = $detailRows->map(function ($c) use ($activityNames) {
                $activityName = $c->activity ? ($activityNames[$c->activity] ?? '—') : '—';

                // Рассчитать "будет терминирован" для активных
                $willTerminate = null;
                if ($c->activity == 1 && $c->dateActivity) { // Активный
                    $willTerminate = \Carbon\Carbon::parse($c->dateActivity)->addYear()->format('Y-m-d');
                }

                return [
                    'id' => $c->id,
                    'personName' => $c->personName,
                    'activityId' => $c->activity,
                    'activityName' => $activityName,
                    'dateActivity' => $c->dateActivity,
                    'dateDeactivity' => $c->dateDeactivity,
                    'dateDeterministic' => $c->dateDeterministic,
                    'dateDeterministicPlan' => $c->dateDeterministicPlan,
                    'willTerminate' => $willTerminate,
                    'terminationCount' => $c->terminationCount ?? 0,
                    'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                ];
            });

        return response()->json([
            'summary' => $statuses,
            'data' => $details,
            'total' => $detailTotal,
        ]);
    }

    /** Клиенты — админ-список всех клиентов */
    public function clients(Request $request): JsonResponse
    {
        $query = DB::table('client');

        if ($request->filled('search')) {
            $query->where('personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('consultant')) {
            $query->where('consultant', $request->consultant);
        }

        $total = $query->count();
        $rows = $query->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        // Batch load person data
        $personIds = $rows->pluck('person')->filter()->unique();
        $persons = $personIds->isNotEmpty()
            ? DB::table('person')->whereIn('id', $personIds)->get()->keyBy('id')
            : collect();

        // Batch count contracts per client
        $clientIds = $rows->pluck('id')->filter()->unique();
        $contractCounts = $clientIds->isNotEmpty()
            ? DB::table('contract')->whereIn('client', $clientIds)->whereNull('deletedAt')
                ->select('client', DB::raw('count(*) as cnt'))
                ->groupBy('client')
                ->pluck('cnt', 'client')
            : collect();

        // Batch check which persons are also partners
        $personPartners = $personIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('person', $personIds)->whereNull('dateDeleted')
                ->pluck('person')->unique()->flip()
            : collect();

        // Batch load consultant names
        $consultantIds = $rows->pluck('consultant')->filter()->unique();
        $consultantNames = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('personName', 'id')
            : collect();

        $clients = $rows->map(function ($c) use ($persons, $contractCounts, $personPartners, $consultantNames) {
                $person = $c->person ? ($persons[$c->person] ?? null) : null;

                return [
                    'id' => $c->id,
                    'dsId' => $c->idDs,
                    'personId' => $c->person,
                    'personName' => $c->personName,
                    'active' => (bool) $c->active,
                    'consultantId' => $c->consultant,
                    'consultantName' => $c->consultant ? ($consultantNames[$c->consultant] ?? null) : null,
                    'dateCreated' => $c->dateCreated,
                    'workSince' => $c->workSince,
                    'contractCount' => $contractCounts[$c->id] ?? 0,
                    'isPartner' => $c->person ? isset($personPartners[$c->person]) : false,
                    'comment' => $c->comment,
                    'email' => $person?->email ?? null,
                    'phone' => $person?->phone ?? null,
                    'birthDate' => $person?->birthDate ?? null,
                ];
            });

        return response()->json(['data' => $clients, 'total' => $total]);
    }

    /** Реквизиты — список для верификации */
    public function requisites(Request $request): JsonResponse
    {
        $query = Requisite::whereNull('deletedAt');

        if ($request->filled('verified')) {
            $query->where('verified', $request->verified === 'true');
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('individualEntrepreneur', 'ilike', "%{$s}%")
                  ->orWhere('inn', 'ilike', "%{$s}%");
            });
        }

        $total = $query->count();
        $rows = $query->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        // Batch load consultant names
        $consultantIds = $rows->pluck('consultant')->filter()->unique();
        $consultantNames = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('personName', 'id')
            : collect();

        // Batch load bank requisites
        $reqIds = $rows->pluck('id')->filter()->unique();
        $bankReqs = $reqIds->isNotEmpty()
            ? BankRequisite::whereIn('requisites', $reqIds)->whereNull('deletedAt')->get()->keyBy('requisites')
            : collect();

        $requisites = $rows->map(function ($r) use ($consultantNames, $bankReqs) {
                $bankReq = $bankReqs[$r->id] ?? null;

                return [
                    'id' => $r->id,
                    'consultantId' => $r->consultant,
                    'consultantName' => $r->consultant ? ($consultantNames[$r->consultant] ?? null) : null,
                    'individualEntrepreneur' => $r->individualEntrepreneur,
                    'inn' => $r->inn,
                    'verified' => $r->verified,
                    'hasBankRequisites' => $bankReq !== null,
                    'bankVerified' => $bankReq?->verified ?? false,
                ];
            });

        return response()->json(['data' => $requisites, 'total' => $total]);
    }

    /** Верификация/отклонение реквизитов */
    public function verifyRequisites(Request $request, int $id): JsonResponse
    {
        $requisite = Requisite::findOrFail($id);

        $request->validate([
            'action' => 'required|in:verify,reject',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($request->action === 'verify') {
            $requisite->verified = true;
            $requisite->status = 3; // verified
            $requisite->save();
            return response()->json(['message' => 'Реквизиты верифицированы']);
        }

        $requisite->verified = false;
        $requisite->save();

        // Отправка комментария через коммуникацию
        if ($request->filled('comment')) {
            DB::table('platformCommunication')->insert([
                'consultant' => $requisite->consultant,
                'category' => 1, // Верификация реквизитов
                'message' => $request->comment,
                'date' => now(),
                'direction' => 'ds2p',
                'read' => false,
            ]);
        }

        return response()->json(['message' => 'Реквизиты отклонены']);
    }

    /** Акцепт документов — список */
    /** Акцепт документов — реестр всех партнёров с фактом акцепта */
    public function acceptance(Request $request): JsonResponse
    {
        $query = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->select('id', 'personName', 'acceptance');

        if ($request->filled('search')) {
            $query->where('personName', 'ilike', '%' . $request->search . '%');
        }

        // Фильтр: акцепт да/нет
        if ($request->filled('accepted')) {
            if ($request->accepted === 'true') {
                $query->where('acceptance', true);
            } else {
                $query->where(function ($q) {
                    $q->where('acceptance', false)->orWhereNull('acceptance');
                });
            }
        }

        $total = $query->count();
        $rows = $query->orderBy('personName')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        // Batch load latest acceptance logs per consultant
        $consultantIds = $rows->pluck('id')->filter()->unique();
        $acceptanceLogs = collect();
        if ($consultantIds->isNotEmpty()) {
            // Get latest log per consultant using a subquery
            $latestLogIds = DB::table('logAcceptance')
                ->whereIn('consultant', $consultantIds)
                ->selectRaw('MAX(id) as id')
                ->groupBy('consultant')
                ->pluck('id');
            if ($latestLogIds->isNotEmpty()) {
                $acceptanceLogs = DB::table('logAcceptance')
                    ->whereIn('id', $latestLogIds)
                    ->get()
                    ->keyBy('consultant');
            }
        }

        $data = $rows->map(function ($c) use ($acceptanceLogs) {
                $log = $acceptanceLogs[$c->id] ?? null;

                return [
                    'id' => $c->id,
                    'personName' => $c->personName,
                    'accepted' => (bool) $c->acceptance,
                    'dateAccepted' => $log->dateAccepted ?? null,
                    'source' => $log->source ?? null,
                ];
            });

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Менеджер контрактов */
    public function contracts(Request $request): JsonResponse
    {
        $query = DB::table('contract')->whereNull('deletedAt');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('clientName', 'ilike', "%{$s}%")
                  ->orWhere('consultantName', 'ilike', "%{$s}%")
                  ->orWhere('number', 'ilike', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $total = $query->count();
        $rows = $query->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        // Batch load contract statuses
        $statusIds = $rows->pluck('status')->filter()->unique();
        $contractStatuses = $statusIds->isNotEmpty()
            ? DB::table('contractStatus')->whereIn('id', $statusIds)->pluck('name', 'id')
            : collect();

        // Batch load currencies
        $currencyIds = $rows->pluck('currency')->filter()->unique();
        $currencies = $currencyIds->isNotEmpty()
            ? DB::table('currency')->whereIn('id', $currencyIds)->pluck('symbol', 'id')
            : collect();

        $contracts = $rows->map(fn ($c) => [
                'id' => $c->id,
                'number' => $c->number,
                'clientName' => $c->clientName,
                'consultant' => $c->consultant ?? null,
                'consultantName' => $c->consultantName,
                'productName' => $c->productName,
                'programName' => $c->programName,
                'statusName' => $c->status ? ($contractStatuses[$c->status] ?? null) : null,
                'ammount' => $c->ammount,
                'currencySymbol' => $c->currency ? ($currencies[$c->currency] ?? null) : null,
                'openDate' => $c->openDate ?? $c->createDate ?? $c->createdAt,
            ]);

        return response()->json(['data' => $contracts, 'total' => $total]);
    }

    /** История перестановок */
    public function transfers(Request $request): JsonResponse
    {
        $query = DB::table('changeConsultantInviterLog');

        if ($request->filled('search')) {
            $query->where('consultantName', 'ilike', '%' . $request->search . '%');
        }

        $total = $query->count();
        $data = $query->orderByDesc('dateCreated')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'dateCreated' => $r->dateCreated,
                'consultantName' => $r->consultantName,
                'inviterOldName' => $r->inviterOldName,
                'inviterNewName' => $r->inviterNewName,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }
}
