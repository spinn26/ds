<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Api\Concerns\PaginatesRequests;
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
    use PaginatesRequests;

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
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
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
                    'inviterId' => $c->inviter,
                    'isClient' => $isClient,
                    'platformAccess' => $platformAccess,
                ];
            });

        return response()->json(['data' => $partners, 'total' => $total]);
    }

    /**
     * Показать полный профиль партнёра для формы редактирования.
     */
    public function showPartner(int $id): JsonResponse
    {
        $consultant = Consultant::findOrFail($id);
        $webUser = $consultant->webUser
            ? DB::table('WebUser')->where('id', $consultant->webUser)->first()
            : null;

        return response()->json([
            'consultant' => [
                'id' => $consultant->id,
                'personName' => $consultant->personName,
                'participantCode' => $consultant->participantCode,
                'inviter' => $consultant->inviter,
                'inviterName' => $consultant->inviterName,
                'activityId' => $consultant->activity?->value,
                'activityName' => $consultant->activityLabel(),
                'active' => $consultant->active,
            ],
            'webUser' => $webUser ? [
                'id' => $webUser->id,
                'firstName' => $webUser->firstName,
                'lastName' => $webUser->lastName,
                'patronymic' => $webUser->patronymic,
                'email' => $webUser->email,
                'phone' => $webUser->phone,
                'nicTG' => $webUser->nicTG,
                'gender' => $webUser->gender,
                'birthDate' => $webUser->birthDate,
                'role' => $webUser->role,
                'isBlocked' => (bool) ($webUser->isBlocked ?? false),
            ] : null,
        ]);
    }

    /**
     * Редактирование партнёра: обновляем Consultant и связанный WebUser.
     * Все поля опциональны — обновляются только присланные.
     */
    public function updatePartner(Request $request, int $id): JsonResponse
    {
        $consultant = Consultant::findOrFail($id);

        $data = $request->validate([
            // consultant fields
            'participantCode' => ['nullable', 'string', 'max:64',
                "unique:consultant,participantCode,{$id},id",
            ],
            'inviter' => ['nullable', 'integer', 'exists:consultant,id'],
            // web user fields
            'firstName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'patronymic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255',
                ($consultant->webUser ? "unique:WebUser,email,{$consultant->webUser},id" : 'unique:WebUser,email'),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'nicTG' => ['sometimes', 'nullable', 'string', 'max:128'],
            'gender' => ['sometimes', 'nullable', 'in:male,female'],
            'birthDate' => ['sometimes', 'nullable', 'date'],
            'role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isBlocked' => ['sometimes', 'boolean'],
            'newPassword' => ['sometimes', 'nullable', 'string', 'min:6', 'max:255'],
        ]);

        DB::transaction(function () use ($consultant, $data, $request) {
            // --- consultant columns ---
            if (array_key_exists('participantCode', $data)) {
                $consultant->participantCode = $data['participantCode'] ?: null;
            }
            if (array_key_exists('inviter', $data)) {
                $consultant->inviter = $data['inviter'] ?: null;
            }

            // --- WebUser columns ---
            if ($consultant->webUser) {
                $userUpdates = [];
                $map = ['firstName', 'lastName', 'patronymic', 'email', 'phone', 'nicTG', 'gender', 'birthDate', 'role'];
                foreach ($map as $col) {
                    if ($request->has($col)) {
                        $userUpdates[$col] = $data[$col] ?: null;
                    }
                }
                if ($request->has('isBlocked')) {
                    $userUpdates['isBlocked'] = (bool) $data['isBlocked'];
                }
                if (! empty($data['newPassword'])) {
                    $userUpdates['password'] = \Illuminate\Support\Facades\Hash::make($data['newPassword']);
                }
                if (! empty($userUpdates)) {
                    DB::table('WebUser')->where('id', $consultant->webUser)->update($userUpdates);
                }

                // Keep consultant.personName in sync with WebUser name parts
                if (isset($userUpdates['firstName']) || isset($userUpdates['lastName']) || isset($userUpdates['patronymic'])) {
                    $u = DB::table('WebUser')->where('id', $consultant->webUser)->first();
                    $consultant->personName = trim("{$u->lastName} {$u->firstName} {$u->patronymic}");
                }
            }

            $consultant->save();
        });

        return response()->json(['message' => 'Обновлён', 'id' => $consultant->id]);
    }

    /**
     * Массовое действие над выборкой партнёров.
     * actions:
     *   - activate / terminate / exclude / re-register (смена статуса)
     *   - set-inviter (смена наставника, требует inviter)
     *   - block / unblock (блокировка WebUser)
     */
    public function bulkPartners(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', 'string', 'in:activate,terminate,exclude,re-register,set-inviter,block,unblock'],
            'reason' => ['nullable', 'string', 'max:500'],
            'inviter' => ['nullable', 'integer', 'exists:consultant,id'],
        ]);

        $ok = 0;
        $fail = 0;
        $errors = [];

        foreach ($data['ids'] as $cid) {
            try {
                $c = Consultant::find($cid);
                if (! $c) { $fail++; continue; }

                switch ($data['action']) {
                    case 'activate':
                        $this->statusService->activate($c) ? $ok++ : $fail++;
                        break;
                    case 'terminate':
                        $this->statusService->terminate($c, $data['reason'] ?? '');
                        $ok++;
                        break;
                    case 'exclude':
                        $this->statusService->exclude($c, $data['reason'] ?? '');
                        $ok++;
                        break;
                    case 're-register':
                        $this->statusService->reRegister($c) ? $ok++ : $fail++;
                        break;
                    case 'set-inviter':
                        if (! $request->filled('inviter')) {
                            throw new \InvalidArgumentException('inviter required');
                        }
                        if ((int) $data['inviter'] === $cid) {
                            throw new \InvalidArgumentException('Нельзя назначить самого себя');
                        }
                        $c->inviter = $data['inviter'];
                        $c->save();
                        $ok++;
                        break;
                    case 'block':
                    case 'unblock':
                        if ($c->webUser) {
                            DB::table('WebUser')->where('id', $c->webUser)
                                ->update(['isBlocked' => $data['action'] === 'block']);
                            $ok++;
                        } else {
                            $fail++;
                        }
                        break;
                }
            } catch (\Throwable $e) {
                $fail++;
                $errors[] = "ID {$cid}: " . $e->getMessage();
            }
        }

        return response()->json([
            'message' => "Выполнено: {$ok}, не удалось: {$fail}",
            'ok' => $ok,
            'fail' => $fail,
            'errors' => array_slice($errors, 0, 10),
        ]);
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

    /**
     * Ручной override статуса партнёра (per spec ✅Статусы партнеров.md §3).
     *
     * Сотрудник может задать ЛЮБОЙ статус + ЛЮБУЮ дату (включая
     * ретроспективную) + обязательный комментарий. Бизнес-правила
     * обходятся: если сотрудник вручную ставит статус «Активен», система
     * не проверяет ЛП-порог. Audit-лог обеспечивается активити-логом
     * на Consultant (см. CommissionSpecTest::invariant_manual_status_override_is_audit_logged).
     *
     * Какую дату обновлять выбирается по статусу:
     *   activity=1 (Активен) → dateActivity
     *   activity=3 (Терминирован) → dateDeterministic
     *   activity=4 (Зарегистрирован) → dateCreated
     *   activity=5 (Исключён) → dateDeleted (мягкое удаление)
     */
    public function overridePartnerStatus(Request $request, int $id): JsonResponse
    {
        $consultant = Consultant::findOrFail($id);

        $request->validate([
            'activity' => 'required|integer|in:1,3,4,5',
            'date' => 'required|date',
            'comment' => 'required|string|min:3|max:500',
        ]);

        $activity = (int) $request->activity;
        $date = $request->input('date');
        $comment = $request->input('comment');

        DB::transaction(function () use ($consultant, $activity, $date, $comment, $request) {
            // Логируем намерение в activity_log через свойство.
            // (Activitylog подхватит изменения трекаемых полей автоматически)
            activity()
                ->performedOn($consultant)
                ->causedBy($request->user())
                ->withProperties(['comment' => $comment, 'override' => true])
                ->log('manual-status-override');

            $consultant->activity = $activity;

            switch ($activity) {
                case 1: // Активен
                    $consultant->dateActivity = $date;
                    $consultant->dateDeterministic = (new \DateTime($date))->modify('+12 months')->format('Y-m-d');
                    $consultant->active = true;
                    break;
                case 3: // Терминирован
                    $consultant->dateDeterministic = $date;
                    $consultant->dateDeactivity = $date;
                    $consultant->active = false;
                    break;
                case 4: // Зарегистрирован
                    $consultant->dateCreated = $date;
                    $consultant->active = false;
                    break;
                case 5: // Исключён
                    $consultant->dateDeleted = $date;
                    $consultant->active = false;
                    break;
            }
            $consultant->save();
        });

        return response()->json(['message' => 'Статус обновлён вручную, изменение зафиксировано в аудит-логе']);
    }

    /**
     * История изменений контракта (per spec ✅Менеджер контрактов §4).
     *
     * Берётся из Spatie\Activitylog `activity_log`. Возвращает все
     * правки контракта (Contract model уже логирует client/consultant/
     * product/program/status/currency/amount/number/openDate/closeDate).
     *
     * Спека требует:
     *   - Дата и время изменения
     *   - Что изменено (название поля: было → стало)
     *   - Автор изменений (ФИО сотрудника или Система)
     */
    public function contractHistory(int $id): JsonResponse
    {
        $rows = DB::table('activity_log')
            ->where('subject_type', \App\Models\Contract::class)
            ->where('subject_id', $id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $causerIds = $rows->pluck('causer_id')->filter()->unique();
        $causers = $causerIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $causerIds)->select(['id', 'firstName', 'lastName', 'patronymic'])->get()->keyBy('id')
            : collect();

        $fieldLabels = [
            'client' => 'Клиент', 'consultant' => 'Консультант',
            'product' => 'Продукт', 'program' => 'Программа',
            'status' => 'Статус', 'currency' => 'Валюта',
            'amount' => 'Сумма', 'number' => 'Номер',
            'openDate' => 'Дата открытия', 'closeDate' => 'Дата закрытия',
        ];

        $data = $rows->map(function ($r) use ($causers, $fieldLabels) {
            $props = json_decode($r->properties ?: '{}', true);
            $changes = [];
            $oldValues = $props['old'] ?? [];
            $newValues = $props['attributes'] ?? [];
            foreach ($newValues as $field => $newVal) {
                $oldVal = $oldValues[$field] ?? null;
                if ($oldVal === $newVal) continue;
                $changes[] = [
                    'field' => $field,
                    'fieldLabel' => $fieldLabels[$field] ?? $field,
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }

            $causer = $r->causer_id ? ($causers[$r->causer_id] ?? null) : null;
            $author = $causer
                ? trim("{$causer->lastName} {$causer->firstName} {$causer->patronymic}")
                : 'Система';

            return [
                'id' => $r->id,
                'createdAt' => $r->created_at,
                'description' => $r->description,
                'event' => $r->event,
                'author' => $author,
                'changes' => $changes,
            ];
        });

        return response()->json(['data' => $data]);
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
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
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
                    'dateCreated' => $c->dateCreated,
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

    /**
     * Soft-delete партнёра (consultant). Ставит dateDeleted = now(),
     * не трогая FK на contract/commission/transaction — они продолжают
     * ссылаться, исторические данные сохраняются. Обратимо через
     * прямой UPDATE consultant SET dateDeleted=NULL (нет UI reverse).
     *
     * Блокируется если партнёр активен (activity=1) и имеет детей в
     * структуре — staff должен сначала перестроить ветку.
     */
    public function deletePartner(Request $request, int $id): JsonResponse
    {
        $consultant = DB::table('consultant')->where('id', $id)->first();
        if (! $consultant) {
            return response()->json(['message' => 'Партнёр не найден'], 404);
        }
        if ($consultant->dateDeleted) {
            return response()->json(['message' => 'Партнёр уже удалён'], 422);
        }

        // Нельзя удалить активного с детьми — осиротит ветку.
        if ($consultant->activity == 1) {
            $children = DB::table('consultant')
                ->where('inviter', $id)
                ->whereNull('dateDeleted')
                ->count();
            if ($children > 0) {
                return response()->json([
                    'message' => "Нельзя удалить активного партнёра с {$children} детьми в структуре. Сначала переназначьте их на другого наставника.",
                ], 422);
            }
        }

        DB::transaction(function () use ($id, $request) {
            DB::table('consultant')->where('id', $id)->update([
                'dateDeleted' => now(),
            ]);

            // Audit через Spatie
            if (function_exists('activity')) {
                try {
                    activity('partner_delete')
                        ->performedOn(new Consultant(['id' => $id]))
                        ->causedBy($request->user())
                        ->withProperties(['reason' => $request->input('reason')])
                        ->log('partner soft-deleted');
                } catch (\Throwable) {}
            }
        });

        return response()->json(['message' => 'Партнёр удалён']);
    }

    /**
     * Soft-delete клиента. Если у клиента есть активные контракты —
     * блокируем. FK из contract/commission остаются.
     */
    public function deleteClient(Request $request, int $id): JsonResponse
    {
        $client = DB::table('client')->where('id', $id)->first();
        if (! $client) {
            return response()->json(['message' => 'Клиент не найден'], 404);
        }
        if ($client->dateDeleted ?? null) {
            return response()->json(['message' => 'Клиент уже удалён'], 422);
        }

        $activeContracts = DB::table('contract')
            ->where('client', $id)
            ->whereNull('deletedAt')
            ->count();
        if ($activeContracts > 0) {
            return response()->json([
                'message' => "Нельзя удалить: у клиента {$activeContracts} активных контрактов. Сначала закройте или удалите их.",
            ], 422);
        }

        DB::table('client')->where('id', $id)->update([
            'dateDeleted' => now(),
        ]);

        if (function_exists('activity')) {
            try {
                activity('client_delete')
                    ->causedBy($request->user())
                    ->withProperties(['clientId' => $id, 'reason' => $request->input('reason')])
                    ->log('client soft-deleted');
            } catch (\Throwable) {}
        }

        return response()->json(['message' => 'Клиент удалён']);
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
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
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
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
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

    /**
     * Документы, загруженные партнёром для этого requisite (паспорт +
     * заявление на выплаты). Берём поля прямо с consultant — DocumentController
     * пишет их именно туда. Возвращаем только те типы, где файл
     * действительно есть.
     */
    public function requisiteDocuments(int $id): JsonResponse
    {
        $req = DB::table('requisites')->where('id', $id)->first();
        if (! $req) {
            return response()->json(['message' => 'Реквизиты не найдены'], 404);
        }

        $consultant = $req->consultant
            ? DB::table('consultant')->where('id', $req->consultant)->first()
            : null;
        if (! $consultant) {
            return response()->json([]);
        }

        // В consultant.passportScanPage1/... хранится числовой FileUpload.uuid
        // (legacy Directual). Нужно ждойнить FileUpload по uuid, чтобы получить
        // реальный URL файла (urlLink = directual CDN).
        $map = [
            'passportPage1' => 'passportScanPage1',
            'passportPage2' => 'passportScanPage2',
            'applicationForPayment' => 'applicationForPayment',
        ];

        $uuids = [];
        foreach ($map as $column) {
            $v = $consultant->{$column} ?? null;
            if ($v !== null && $v !== '' && is_numeric($v)) $uuids[] = (int) $v;
        }

        $files = $uuids
            ? DB::table('FileUpload')->whereIn('uuid', $uuids)
                ->get(['uuid', 'urlLink', 'originalFileName', 'extension'])
                ->keyBy('uuid')
            : collect();

        $out = [];
        foreach ($map as $type => $column) {
            $raw = $consultant->{$column} ?? null;
            if ($raw === null || $raw === '') continue;

            // Если uuid есть в FileUpload — берём готовый URL оттуда
            if (is_numeric($raw) && isset($files[(int) $raw])) {
                $f = $files[(int) $raw];
                $out[] = [
                    'type' => $type,
                    'uuid' => (int) $raw,
                    'url' => $f->urlLink,
                    'filename' => $f->originalFileName,
                    'extension' => $f->extension,
                ];
            } elseif (str_starts_with((string) $raw, 'http')) {
                $out[] = ['type' => $type, 'url' => (string) $raw];
            } else {
                // legacy: /storage/{relative-path}
                $out[] = ['type' => $type, 'url' => '/storage/' . $raw, 'path' => (string) $raw];
            }
        }

        return response()->json($out);
    }

    /**
     * Сводка по партнёру для drawer'а реквизитов:
     * ФИО, контакты, уровень квалификации, дата регистрации, активность.
     */
    public function requisitePartner(int $id): JsonResponse
    {
        $req = DB::table('requisites')->where('id', $id)->first();
        if (! $req || ! $req->consultant) {
            return response()->json(null);
        }

        $c = DB::table('consultant')->where('id', $req->consultant)->first();
        if (! $c) return response()->json(null);

        $user = $c->webUser
            ? DB::table('WebUser')->where('id', $c->webUser)->first([
                'firstName', 'lastName', 'patronymic', 'email', 'phone', 'nicTG',
            ])
            : null;

        $level = $c->status_and_lvl
            ? DB::table('status_levels')->where('id', $c->status_and_lvl)->first(['level', 'title', 'percent'])
            : null;

        $activity = $c->activity
            ? DB::table('directory_of_activities')->where('id', $c->activity)->value('name')
            : null;

        return response()->json([
            'consultantId' => $c->id,
            'personName' => $c->personName,
            'firstName' => $user->firstName ?? null,
            'lastName' => $user->lastName ?? null,
            'patronymic' => $user->patronymic ?? null,
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
            'telegram' => $user->nicTG ?? null,
            'qualification' => $level ? "{$level->level} [{$level->title}]" : null,
            'percent' => $level->percent ?? null,
            'activity' => $activity,
            'dateCreated' => $c->dateCreated,
            'dateActivity' => $c->dateActivity,
            'personalVolume' => (float) ($c->personalVolume ?? 0),
            'groupVolume' => (float) ($c->groupVolume ?? 0),
        ]);
    }

    /**
     * Проверка ИНН через DaData: находит ИП/юрлицо и сравнивает ФИО с ФИО
     * партнёра из WebUser. Используется для быстрой сверки реквизитов.
     */
    public function checkRequisiteInn(int $id): JsonResponse
    {
        $req = DB::table('requisites')->where('id', $id)->first();
        if (! $req) {
            return response()->json(['message' => 'Реквизиты не найдены'], 404);
        }
        if (! $req->inn) {
            return response()->json(['message' => 'ИНН не заполнен'], 422);
        }

        $dadata = app(\App\Services\DadataService::class);
        $result = $dadata->findByInn((string) $req->inn);

        // Если нашли — сравниваем ФИО с профилем партнёра.
        if (! empty($result['found']) && $req->consultant) {
            $webUserId = DB::table('consultant')->where('id', $req->consultant)->value('webUser');
            if ($webUserId) {
                $user = DB::table('WebUser')->where('id', $webUserId)->first([
                    'firstName', 'lastName', 'patronymic',
                ]);
                if ($user) {
                    $result['fioCheck'] = $dadata->compareFio(
                        $result['fio'],
                        $user->lastName,
                        $user->firstName,
                        $user->patronymic,
                    );
                }
            }
        }

        return response()->json($result);
    }

    /** Верификация/отклонение реквизитов */
    public function verifyRequisites(Request $request, int $id): JsonResponse
    {
        $requisite = Requisite::findOrFail($id);

        $request->validate([
            'action' => 'required|in:verify,reject',
            'comment' => 'nullable|string|max:1000',
        ]);

        $consultantUserId = DB::table('consultant')->where('id', $requisite->consultant)->value('webUser');

        if ($request->action === 'verify') {
            $requisite->verified = true;
            $requisite->status = 3; // verified
            $requisite->dateChange = now();
            $requisite->save();

            if ($consultantUserId) {
                \App\Http\Controllers\Api\NotificationController::create(
                    (int) $consultantUserId,
                    'requisites',
                    'Реквизиты подтверждены',
                    'Банковские реквизиты прошли проверку.',
                    '/profile'
                );
            }

            return response()->json(['message' => 'Реквизиты верифицированы']);
        }

        // Reject: return to consultant for corrections.
        // status_requisites: 1=backoffice, 2=consultant, 3=verified — no dedicated "rejected" id,
        // so we use 2 ("returned to consultant"), which is also what ProfileController sets on resubmit.
        $requisite->verified = false;
        $requisite->status = 2;
        $requisite->dateChange = now();
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

        if ($consultantUserId) {
            \App\Http\Controllers\Api\NotificationController::create(
                (int) $consultantUserId,
                'requisites',
                'Реквизиты отклонены',
                $request->input('comment') ?: 'Проверьте и отправьте реквизиты повторно.',
                '/profile'
            );
        }

        return response()->json(['message' => 'Реквизиты отклонены']);
    }

    /** Акцепт документов — список */
    /** Акцепт документов — реестр всех партнёров с фактом акцепта */
    /**
     * Per spec ✅Акцепт документов.md:
     * Главная строка — партнёр + индикатор «X из 5». При раскрытии —
     * строка на каждый из 5 системных документов с галочкой и timestamp.
     * Колонка «Источник» убрана (по спеке источник по умолчанию — Платформа).
     */
    public function acceptance(Request $request): JsonResponse
    {
        $query = DB::table('consultant')->whereNull('dateDeleted');

        if ($request->filled('search')) {
            $query->where('personName', 'ilike', '%' . $request->search . '%');
        }

        $total = $query->count();
        $rows = $query->orderBy('personName')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get(['id', 'personName', 'acceptance']);

        $consultantIds = $rows->pluck('id')->all();

        // Все документы (5 системных)
        $allDocs = DB::table('agreementPartnersDocuments')
            ->orderBy('number')
            ->get(['id', 'name', 'link', 'number']);
        $totalDocs = $allDocs->count() ?: 5;

        // Все акцепты этих консультантов (latest per (consultant, document))
        $latestPerPair = DB::table('partnerAcceptance')
            ->whereIn('consultant', $consultantIds)
            ->where('accepted', true)
            ->selectRaw('consultant, "documentType", MAX("dateAccepted") as last_date')
            ->groupBy(['consultant', 'documentType'])
            ->get()
            ->groupBy('consultant');

        $data = $rows->map(function ($c) use ($allDocs, $latestPerPair, $totalDocs) {
            $accs = $latestPerPair[$c->id] ?? collect();
            $byDoc = $accs->keyBy('documentType');
            $documents = $allDocs->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'link' => $d->link,
                'number' => $d->number,
                'accepted' => isset($byDoc[$d->id]),
                'dateAccepted' => $byDoc[$d->id]->last_date ?? null,
            ]);
            $signed = $documents->where('accepted', true)->count();
            return [
                'id' => $c->id,
                'personName' => $c->personName,
                'signedCount' => $signed,
                'totalCount' => $totalDocs,
                'fullyAccepted' => $signed >= $totalDocs,
                'documents' => $documents,
            ];
        });

        // Filter «Акцептовано/не акцептовано» (выполняем после агрегации, чтобы
        // не переписывать оптимизированный запрос — данных уже в памяти).
        if ($request->filled('accepted')) {
            $expect = $request->accepted === 'true';
            $data = $data->filter(fn ($r) => $r['fullyAccepted'] === $expect)->values();
        }

        // Filter по типу документа: оставляем только партнёров, у кого выбранный
        // документ имеет нужный статус (по умолчанию — не акцептован).
        if ($request->filled('document_type')) {
            $docId = (int) $request->document_type;
            $data = $data->filter(function ($r) use ($docId) {
                $d = collect($r['documents'])->firstWhere('id', $docId);
                return $d && ! $d['accepted'];
            })->values();
        }

        return response()->json([
            'data' => $data,
            'total' => $total,
            'documents' => $allDocs,
        ]);
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
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('number')) $query->where('number', 'ilike', '%' . $request->number . '%');
        if ($request->filled('comment')) $query->where('comment', 'ilike', '%' . $request->comment . '%');
        if ($request->filled('product')) $query->where('product', $request->product);
        if ($request->filled('created_from')) $query->where('createDate', '>=', $request->created_from);
        if ($request->filled('created_to')) $query->where('createDate', '<=', $request->created_to . ' 23:59:59');
        if ($request->filled('opened_from')) $query->where('openDate', '>=', $request->opened_from);
        if ($request->filled('opened_to')) $query->where('openDate', '<=', $request->opened_to . ' 23:59:59');
        if ($request->filled('closed_from')) $query->where('closeDate', '>=', $request->closed_from);
        if ($request->filled('closed_to')) $query->where('closeDate', '<=', $request->closed_to . ' 23:59:59');

        $total = $query->count();
        $rows = $query->orderByDesc('id')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
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

    /**
     * Single contract for edit modal (per spec ✅Менеджер контрактов §3).
     * Возвращает все поля контракта + цепочка партнёров (read-only) для
     * блока «Цепочка Партнеров» в модалке редактирования.
     */
    public function contractDetails(int $id): JsonResponse
    {
        $row = DB::table('contract')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Контракт не найден'], 404);

        // Цепочка наставников: вверх по consultant.inviter
        $chain = [];
        if ($row->consultant) {
            $current = $row->consultant;
            $visited = [];
            for ($i = 0; $i < 20; $i++) {
                if (in_array($current, $visited)) break;
                $visited[] = $current;
                $cons = DB::table('consultant')->where('id', $current)->first(['id', 'personName', 'inviter']);
                if (! $cons) break;
                $chain[] = ['id' => $cons->id, 'personName' => $cons->personName];
                if (! $cons->inviter) break;
                $current = $cons->inviter;
            }
        }

        return response()->json([
            'contract' => $row,
            'chain' => $chain,
        ]);
    }

    /**
     * Form-data для модалки контракта: справочники.
     */
    public function contractFormData(): JsonResponse
    {
        return response()->json([
            'statuses' => DB::table('contractStatus')->orderBy('id')->get(['id', 'name']),
            'currencies' => DB::table('currency')->where('selectable', true)->orderBy('id')
                ->get()->map(fn ($c) => ['id' => $c->id, 'symbol' => $c->symbol, 'name' => $c->nameRu]),
            // Реальные имена колонок в legacy: country.countryNameRu, riskProfile.name, setup.setup
            'countries' => DB::table('country')->orderBy('countryNameRu')
                ->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->countryNameRu]),
            'riskProfiles' => DB::table('riskProfile')->orderBy('id')
                ->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'setups' => DB::table('setup')->orderBy('id')
                ->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->setup]),
        ]);
    }

    /**
     * Создать контракт (per spec ✅Менеджер контрактов §3 «Сохранить контракт»).
     * Партнёр подтягивается автоматически из выбранного клиента.
     */
    public function storeContract(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => 'required|string|max:255',
            'counterpartyContractId' => 'nullable|string|max:255',
            'status' => 'required|integer|exists:contractStatus,id',
            'client' => 'required|integer|exists:client,id',
            'product' => 'required|integer|exists:product,id',
            'program' => 'required|integer|exists:program,id',
            'country' => 'nullable|integer|exists:country,id',
            'createDate' => 'required|date',
            'openDate' => 'nullable|date',
            'closeDate' => 'nullable|date',
            'ammount' => 'required|numeric|min:0',
            'currency' => 'required|integer|exists:currency,id',
            'riskProfile' => 'nullable|integer|exists:riskProfile,id',
            'setup' => 'nullable|integer|exists:setup,id',
            'type' => 'nullable|string|max:50',
            'comment' => 'nullable|string|max:2000',
        ]);

        // Партнёр и его данные подтягиваются из клиента
        $client = DB::table('client')->where('id', $data['client'])->first();
        $consultantId = $client?->consultant;
        $consultantName = $consultantId
            ? DB::table('consultant')->where('id', $consultantId)->value('personName')
            : null;

        $product = DB::table('product')->where('id', $data['product'])->first();
        $program = DB::table('program')->where('id', $data['program'])->first();

        $id = DB::transaction(function () use ($data, $client, $consultantId, $consultantName, $product, $program, $request) {
            return DB::table('contract')->insertGetId([
                'number' => $data['number'],
                'counterpartyContractId' => $data['counterpartyContractId'] ?? null,
                'status' => $data['status'],
                'client' => $data['client'],
                'clientName' => $client?->personName,
                'consultant' => $consultantId,
                'consultantName' => $consultantName,
                'product' => $data['product'],
                'productName' => $product?->name,
                'program' => $data['program'],
                'programName' => $program?->name,
                'country' => $data['country'] ?? null,
                'createDate' => $data['createDate'],
                'openDate' => $data['openDate'] ?? null,
                'closeDate' => $data['closeDate'] ?? null,
                'ammount' => $data['ammount'],
                'currency' => $data['currency'],
                'riskProfile' => $data['riskProfile'] ?? null,
                'setup' => $data['setup'] ?? null,
                'type' => $data['type'] ?? null,
                'comment' => $data['comment'] ?? null,
                'createdAt' => now(),
                'changedAt' => now(),
            ]);
        });

        return response()->json(['message' => 'Контракт создан', 'id' => $id], 201);
    }

    /**
     * Обновить контракт. Закрытые периоды защищены через freeze.
     */
    public function updateContract(Request $request, int $id): JsonResponse
    {
        $contract = DB::table('contract')->where('id', $id)->first();
        if (! $contract) return response()->json(['message' => 'Контракт не найден'], 404);

        $data = $request->validate([
            'number' => 'sometimes|string|max:255',
            'counterpartyContractId' => 'nullable|string|max:255',
            'status' => 'sometimes|integer|exists:contractStatus,id',
            'client' => 'sometimes|integer|exists:client,id',
            'product' => 'sometimes|integer|exists:product,id',
            'program' => 'sometimes|integer|exists:program,id',
            'country' => 'nullable|integer|exists:country,id',
            'createDate' => 'sometimes|date',
            'openDate' => 'nullable|date',
            'closeDate' => 'nullable|date',
            'ammount' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|integer|exists:currency,id',
            'riskProfile' => 'nullable|integer|exists:riskProfile,id',
            'setup' => 'nullable|integer|exists:setup,id',
            'type' => 'nullable|string|max:50',
            'comment' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($data, $id) {
            // Денормализация имён при изменении FK
            if (isset($data['client'])) {
                $client = DB::table('client')->where('id', $data['client'])->first();
                $data['clientName'] = $client?->personName;
                if ($client?->consultant) {
                    $data['consultant'] = $client->consultant;
                    $data['consultantName'] = DB::table('consultant')->where('id', $client->consultant)->value('personName');
                }
            }
            if (isset($data['product'])) {
                $data['productName'] = DB::table('product')->where('id', $data['product'])->value('name');
            }
            if (isset($data['program'])) {
                $data['programName'] = DB::table('program')->where('id', $data['program'])->value('name');
            }
            $data['changedAt'] = now();
            DB::table('contract')->where('id', $id)->update($data);
        });

        return response()->json(['message' => 'Контракт обновлён']);
    }

    /**
     * Soft-delete контракта (per spec §3 «Удалить контракт» с предупреждением).
     */
    public function deleteContract(int $id): JsonResponse
    {
        $row = DB::table('contract')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Контракт не найден'], 404);

        DB::table('contract')->where('id', $id)->update(['deletedAt' => now()]);
        return response()->json(['message' => 'Контракт удалён']);
    }

    /**
     * История перестановок (per spec ✅История перестановок.md).
     * 3 вкладки: partner / contract / client. Колонка «Автор изменений»
     * резолвится через webUser → WebUser.firstName/lastName/patronymic
     * или «Система» если webUser=null.
     */
    public function transfers(Request $request): JsonResponse
    {
        $tab = $request->input('tab', 'partner');
        $tableConfig = match ($tab) {
            'contract' => [
                'table' => 'changeConsultantContractLog',
                'subjectColumn' => 'contractNumber',
                'subjectKey' => 'subjectName',
                'subjectIdKey' => 'subjectId',
                'subjectFkColumn' => 'contract',
            ],
            'client' => [
                'table' => 'changeConsultantClientLog',
                'subjectColumn' => 'clientName',
                'subjectKey' => 'subjectName',
                'subjectIdKey' => 'subjectId',
                'subjectFkColumn' => 'client',
            ],
            'partner' => [
                'table' => 'changeConsultantInviterLog',
                'subjectColumn' => 'consultantName',
                'subjectKey' => 'subjectName',
                'subjectIdKey' => 'subjectId',
                'subjectFkColumn' => 'consultant',
            ],
            default => throw new \InvalidArgumentException('Bad tab'),
        };

        $query = DB::table($tableConfig['table']);

        if ($request->filled('search')) {
            $query->where($tableConfig['subjectColumn'], 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('date_from')) {
            $query->where('dateCreated', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('dateCreated', '<=', $request->date_to . ' 23:59:59');
        }

        $total = $query->count();
        $rows = $query->orderByDesc('dateCreated')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        $userIds = $rows->pluck('webUser')->filter()->unique();
        $users = $userIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $userIds)
                ->get(['id', 'firstName', 'lastName', 'patronymic'])
                ->keyBy('id')
            : collect();

        $oldKey = $tab === 'partner' ? 'inviterOldName' : 'consultantOldName';
        $newKey = $tab === 'partner' ? 'inviterNewName' : 'consultantNewName';

        $data = $rows->map(function ($r) use ($users, $tableConfig, $oldKey, $newKey) {
            $author = $r->webUser
                ? trim(($users[$r->webUser]?->lastName ?? '') . ' ' . ($users[$r->webUser]?->firstName ?? ''))
                : 'Система';
            return [
                'id' => $r->id,
                'dateCreated' => $r->dateCreated,
                'subjectName' => $r->{$tableConfig['subjectColumn']} ?? null,
                'subjectId' => $r->{$tableConfig['subjectFkColumn']} ?? null,
                'oldName' => $r->{$oldKey} ?? null,
                'newName' => $r->{$newKey} ?? null,
                'author' => $author ?: 'Система',
                'triggeredBy' => $r->triggeredBy ?? null,
            ];
        });

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /**
     * Массовая верификация / отклонение реквизитов.
     */
    public function bulkRequisites(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', 'string', 'in:verify,reject'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $ok = 0;
        $fail = 0;

        foreach ($data['ids'] as $rid) {
            try {
                $r = Requisite::find($rid);
                if (! $r) { $fail++; continue; }

                $r->verified = $data['action'] === 'verify';
                $r->status = $data['action'] === 'verify' ? 3 : 2;
                $r->dateChange = now();
                $r->save();

                if ($data['action'] === 'reject' && ! empty($data['comment'])) {
                    DB::table('platformCommunication')->insert([
                        'consultant' => $r->consultant,
                        'category' => 1,
                        'message' => $data['comment'],
                        'date' => now(),
                        'direction' => 'ds2p',
                        'read' => false,
                    ]);
                }
                $ok++;
            } catch (\Throwable) {
                $fail++;
            }
        }

        return response()->json([
            'message' => "Выполнено: {$ok}, не удалось: {$fail}",
            'ok' => $ok, 'fail' => $fail,
        ]);
    }
}
