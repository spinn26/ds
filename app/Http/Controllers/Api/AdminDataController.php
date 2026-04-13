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
        $partners = $query->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(function ($c) {
                // Person data from WebUser
                $webUser = $c->webUser
                    ? DB::table('WebUser')->where('id', $c->webUser)->first()
                    : null;
                $personData = $c->person
                    ? DB::table('person')->where('id', $c->person)->first()
                    : $webUser;

                // Check if person is also a client
                $isClient = $c->person
                    ? DB::table('client')->where('person', $c->person)->exists()
                    : false;

                // Platform access: webUser exists and not blocked
                $platformAccess = $webUser && ! ($webUser->isBlocked ?? false);

                return [
                    'id' => $c->id,
                    'personId' => $c->person,
                    'personName' => $c->personName,
                    'active' => $c->active,
                    'activityName' => $c->activityLabel(),
                    'activityId' => $c->activity?->value,
                    'statusName' => $c->status ? DB::table('status')->where('id', $c->status)->value('title') : null,
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
        $details = $detailQuery->orderBy('personName')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(function ($c) {
                $activityName = $c->activity
                    ? DB::table('directory_of_activities')->where('id', $c->activity)->value('name')
                    : '—';

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
        $clients = $query->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(function ($c) {
                // Person data from WebUser
                $person = $c->person
                    ? DB::table('person')->where('id', $c->person)->first()
                    : null;

                $contractCount = DB::table('contract')
                    ->where('client', $c->id)
                    ->whereNull('deletedAt')
                    ->count();

                $isPartner = $c->person
                    ? DB::table('consultant')->where('person', $c->person)->whereNull('dateDeleted')->exists()
                    : false;

                return [
                    'id' => $c->id,
                    'dsId' => $c->idDs,
                    'personId' => $c->person,
                    'personName' => $c->personName,
                    'active' => (bool) $c->active,
                    'consultantId' => $c->consultant,
                    'consultantName' => $c->consultant ? DB::table('consultant')->where('id', $c->consultant)->value('personName') : null,
                    'dateCreated' => $c->dateCreated,
                    'workSince' => $c->workSince,
                    'contractCount' => $contractCount,
                    'isPartner' => $isPartner,
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
        $requisites = $query->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(function ($r) {
                $consultantName = $r->consultant
                    ? DB::table('consultant')->where('id', $r->consultant)->value('personName')
                    : null;
                $bankReq = BankRequisite::where('requisites', $r->id)->whereNull('deletedAt')->first();

                return [
                    'id' => $r->id,
                    'consultantId' => $r->consultant,
                    'consultantName' => $consultantName,
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
        $data = $query->orderBy('personName')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(function ($c) {
                // Найти дату акцепта из logAcceptance
                $log = DB::table('logAcceptance')
                    ->where('consultant', $c->id)
                    ->orderByDesc('dateAccepted')
                    ->first();

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
        $contracts = $query->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'number' => $c->number,
                'clientName' => $c->clientName,
                'consultantName' => $c->consultantName,
                'productName' => $c->productName,
                'programName' => $c->programName,
                'statusName' => $c->status ? DB::table('contractStatus')->where('id', $c->status)->value('name') : null,
                'ammount' => $c->ammount,
                'currencySymbol' => $c->currency ? DB::table('currency')->where('id', $c->currency)->value('symbol') : null,
                'openDate' => $c->openDate,
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
