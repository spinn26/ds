<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Api\Concerns\AppliesSorting;
use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Models\BankRequisite;
use App\Models\Consultant;
use App\Models\Requisite;
use App\Support\Audit;
use App\Support\LegacyId;
use App\Services\PartnerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminDataController extends Controller
{
    use PaginatesRequests;
    use AppliesSorting;

    /**
     * Whitelist кодов «Сетап» для дропдауна в формах контрактов.
     *
     * В legacy-таблице `setup` ~сотни записей, но платформа DS работает
     * только с этим коротким списком (запрос заказчика 2026-05-06).
     * Остальные сетапы в БД не трогаем — они нужны для legacy-отчётности,
     * но в UI заведения контрактов не показываются.
     *
     * Если список нужно расширить — добавьте код сюда. Имя ФК подтянется
     * автоматически через JOIN setup.consultant → consultant.personName.
     */
    private const ALLOWED_SETUP_CODES = [
        '565395', // Ламакин Александр Валерьевич
        '576095', // Тужилкин Дмитрий Владимирович
        '576255', // Рахманов Ленар Минибаевич
        '576328', // Бикбулатов Артур Альбертович
        '576484', // Шиндлер Виктория Анатольевна
        '576504', // Перкулимова Милана Алексеевна / Перкулимов Алексей
        '576522', // Смоленская Екатерина Николаевна
        '576839', // Зарипов Сирин Раифович
        '576861', // Магдиева Алина Рафисовна
        '577126', // Лунин Павел Валерьевич
        '577467', // Чачина Анна Сергеевна
    ];

    public function __construct(
        private readonly PartnerStatusService $statusService,
    ) {}

    /**
     * Лёгкий lookup партнёров для автокомплита (поле «Пригласивший» и др.).
     * GET /api/v1/admin/partners/lookup?q=Иванов&ids[]=1374
     *  - q — поиск по personName / participantCode (минимум 1 символ).
     *  - ids[] — гарантированно вернуть указанных партнёров (например,
     *    текущий выбранный inviter), чтобы автокомплит мог отобразить
     *    ФИО без дополнительного запроса.
     * Возвращает максимум 30 строк: id, personName, participantCode.
     */
    public function partnerLookup(Request $request): JsonResponse
    {
        $q   = trim((string) $request->input('q', ''));
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));

        $query = DB::table('consultant')->whereNull('dateDeleted');
        $query->where(function ($w) use ($q, $ids) {
            if ($q !== '') {
                $like = '%' . $q . '%';
                $w->where('personName', 'ilike', $like)
                  ->orWhere('participantCode', 'ilike', $like);
            }
            if ($ids) {
                $w->orWhereIn('id', $ids);
            }
            // Если ничего не передано — пустой результат (а не вся таблица).
            if ($q === '' && ! $ids) {
                $w->whereRaw('1 = 0');
            }
        });

        $rows = $query->orderBy('personName')
            ->limit(30)
            ->get(['id', 'personName', 'participantCode']);

        return response()->json(['items' => $rows]);
    }

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
        // Доп. фильтры per spec ✅Партнёры §1.1
        if ($request->filled('partner_id')) {
            $query->where('id', (int) $request->partner_id);
        }
        if ($request->filled('inviter_name')) {
            $query->where('inviterName', 'ilike', '%' . $request->inviter_name . '%');
        }
        if ($request->filled('email')) {
            // Email/Phone лежат на WebUser/person — фильтруем через подзапрос.
            $emailLike = '%' . $request->email . '%';
            $query->where(function ($q) use ($emailLike) {
                $q->whereIn('webUser', function ($sub) use ($emailLike) {
                    $sub->select('id')->from('WebUser')->where('email', 'ilike', $emailLike);
                })->orWhereIn('person', function ($sub) use ($emailLike) {
                    $sub->select('id')->from('person')->where('email', 'ilike', $emailLike);
                });
            });
        }
        if ($request->filled('phone')) {
            $phoneLike = '%' . preg_replace('/\D/', '', $request->phone) . '%';
            $query->where(function ($q) use ($phoneLike) {
                $q->whereIn('webUser', function ($sub) use ($phoneLike) {
                    $sub->select('id')->from('WebUser')->where('phone', 'ilike', $phoneLike);
                })->orWhereIn('person', function ($sub) use ($phoneLike) {
                    $sub->select('id')->from('person')->where('phone', 'ilike', $phoneLike);
                });
            });
        }

        $total = $query->count();
        // Postgres + camelCase legacy-таблицы → колонки в whitelist
        // обязаны быть в двойных кавычках (applySorting кладёт их
        // буквально в orderByRaw, без авто-квотинга).
        $this->applySorting($query, $request, [
            'id'                    => 'id',
            'personName'            => '"personName"',
            'activityName'          => 'activity',
            'personalVolume'        => '"personalVolume"',
            'groupVolumeCumulative' => '"groupVolumeCumulative"',
            'participantCode'       => '"participantCode"',
            'dateCreated'           => '"dateCreated"',
            'inviterName'           => '"inviterName"',
            'terminationCount'      => '"terminationCount"',
        ], 'id', 'desc');

        $rows = $query
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

                // «Дата смены статуса» (per spec ✅Партнеры §1.2):
                // - Активен → +12 мес от dateActivity
                // - Зарегистрирован → +90 дней от dateCreated
                $statusChangeDate = null;
                $activityValue = $c->activity?->value;
                if ($activityValue == 1 && $c->dateActivity) { // Active
                    $statusChangeDate = \Carbon\Carbon::parse($c->dateActivity)->addYear()->format('Y-m-d');
                } elseif ($activityValue == 4 && $c->dateCreated) { // Registered
                    $statusChangeDate = \Carbon\Carbon::parse($c->dateCreated)->addDays(90)->format('Y-m-d');
                }

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
                    'createdAt' => $c->dateCreated?->format('d.m.Y'),
                    'statusChangeDate' => $statusChangeDate,
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
                // $webUser — stdClass из DB::table (не модель): birthDate это
                // строка-таймстамп Postgres '1980-02-18 00:00:00'. Отдаём Y-m-d,
                // иначе фронт (split('T')) не распознаёт и поле даты пустое.
                'birthDate' => $webUser->birthDate ? substr((string) $webUser->birthDate, 0, 10) : null,
                'role' => $webUser->role,
                'isBlocked' => (bool) ($webUser->isBlocked ?? false),
            ] : null,
        ]);
    }

    /**
     * POST /admin/partners — создать нового партнёра per spec ✅Партнёры §2.
     * Двухшаг (антидубль) делается на фронте; этот эндпоинт принимает
     * уже подтверждённый «новая персона».
     */
    public function storePartner(Request $request): JsonResponse
    {
        // Кириллица в ФИО — единый формат для регистрации/партнёров/клиентов.
        $cyrillicRegex = '/^[А-Яа-яЁё][А-Яа-яЁё\s\-]*$/u';
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'lastName' => ['required', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'patronymic' => ['nullable', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'email' => ['nullable', 'email', 'max:255', 'unique:WebUser,email'],
            'phone' => ['nullable', 'string', 'max:64'],
            'birthDate' => ['nullable', 'date'],
            'activity' => ['required', 'integer', 'in:1,3,4,5'],
            'inviter' => ['nullable', 'integer', 'exists:consultant,id'],
            'participantCode' => ['nullable', 'string', 'max:64', 'unique:consultant,participantCode'],
        ], [
            'firstName.regex' => 'Имя — только русские буквы',
            'lastName.regex' => 'Фамилия — только русские буквы',
            'patronymic.regex' => 'Отчество — только русские буквы',
        ]);

        $personName = trim("{$data['lastName']} {$data['firstName']}" . ($data['patronymic'] ?? '' ? ' ' . $data['patronymic'] : ''));

        $consultantId = DB::transaction(function () use ($data, $personName) {
            // 1. Создаём WebUser (источник истины для identity per CLAUDE.md).
            $webUserId = DB::table('WebUser')->insertGetId([
                'firstName' => $data['firstName'],
                'lastName' => $data['lastName'],
                'patronymic' => $data['patronymic'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'birthDate' => $data['birthDate'] ?? null,
                'role' => 'consultant',
                'dateCreated' => now(),
            ]);

            // 2. Создаём Consultant.
            $inviterName = null;
            if (! empty($data['inviter'])) {
                $inviterName = DB::table('consultant')->where('id', $data['inviter'])->value('personName');
            }

            return DB::table('consultant')->insertGetId([
                'webUser' => $webUserId,
                'personName' => $personName,
                'activity' => $data['activity'],
                'active' => $data['activity'] == 1,
                'inviter' => $data['inviter'] ?? null,
                'inviterName' => $inviterName,
                'participantCode' => $data['participantCode'] ?? null,
                'dateCreated' => now(),
                'dateActivity' => $data['activity'] == 1 ? now() : null,
            ]);
        });

        return response()->json(['message' => 'Партнёр создан', 'id' => $consultantId], 201);
    }

    /**
     * Канонизация пола: легаси-значения Directual («Мужской»/«Женский»),
     * однобуквенные коды и en-варианты → «male»/«female». null — если пусто
     * или нераспознано (тогда пол просто не меняется/очищается).
     */
    private function normalizeGender($v): ?string
    {
        $s = mb_strtolower(trim((string) $v));
        if ($s === '') return null;
        if (in_array($s, ['male', 'm', 'м', 'муж', 'мужской'], true)) return 'male';
        if (in_array($s, ['female', 'f', 'ж', 'жен', 'женский'], true)) return 'female';
        return null;
    }

    /**
     * Редактирование партнёра: обновляем Consultant и связанный WebUser.
     * Все поля опциональны — обновляются только присланные.
     */
    public function updatePartner(Request $request, int $id): JsonResponse
    {
        $consultant = Consultant::findOrFail($id);
        // Strict: только роль admin может менять role/password/isBlocked.
        // isAdmin() в User модели пускает ещё и backoffice — это не то.
        $isAdmin = $request->user()->hasAnyRole(['admin']);

        // ФИО: только кириллица + пробел/дефис. Поля sometimes — если они
        // вообще пришли в запросе, валидируем формат; если null/пусто,
        // правило regex автоматически пропускается (nullable).
        // Легаси-значения пола приходят из Directual по-русски («Мужской»/
        // «Женский»); приводим к канону male/female до валидации, иначе
        // in:male,female отклонит сохранение старой записи.
        if ($request->has('gender')) {
            $request->merge(['gender' => $this->normalizeGender($request->input('gender'))]);
        }

        $cyrillicRegex = '/^[А-Яа-яЁё][А-Яа-яЁё\s\-]*$/u';
        $data = $request->validate([
            // consultant fields
            'participantCode' => ['nullable', 'string', 'max:64',
                "unique:consultant,participantCode,{$id},id",
            ],
            'inviter' => ['nullable', 'integer', 'exists:consultant,id'],
            // web user fields
            'firstName' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'patronymic' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'email' => ['sometimes', 'nullable', 'email', 'max:255',
                ($consultant->webUser ? "unique:WebUser,email,{$consultant->webUser},id" : 'unique:WebUser,email'),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'nicTG' => ['sometimes', 'nullable', 'string', 'max:128'],
            'gender' => ['sometimes', 'nullable', 'in:male,female'],
            'birthDate' => ['sometimes', 'nullable', 'date'],
            'role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isBlocked' => ['sometimes', 'boolean'],
            'newPassword' => ['sometimes', 'nullable', 'string',
                'min:8', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers(),
            ],
        ], [
            'firstName.regex' => 'Имя — только русские буквы',
            'lastName.regex' => 'Фамилия — только русские буквы',
            'patronymic.regex' => 'Отчество — только русские буквы',
        ]);

        // Critical поля доступны только admin'у — иначе любой staff
        // мог бы выдать себе/коллеге роль admin или сбросить пароль.
        if (! $isAdmin) {
            unset($data['role'], $data['newPassword'], $data['isBlocked']);
        }

        // diff: per-field {from, to} — даёт возможность построить
        // «История изменений» в карточке партнёра. Старые значения
        // снимаем ДО апдейта, новые — после нормализации.
        $diff = [];

        DB::transaction(function () use ($consultant, $data, &$diff) {
            // --- consultant columns ---
            $consultantFields = ['participantCode', 'inviter'];
            foreach ($consultantFields as $col) {
                if (! array_key_exists($col, $data)) continue;
                $old = $consultant->{$col};
                $new = $data[$col] ?: null;
                if ((string) $old !== (string) $new) {
                    $diff[$col] = ['from' => $old, 'to' => $new];
                }
            }
            if (array_key_exists('participantCode', $data)) {
                $consultant->participantCode = $data['participantCode'] ?: null;
            }
            if (array_key_exists('inviter', $data)) {
                $consultant->inviter = $data['inviter'] ?: null;
                // Денорм-имя пригласителя держим в синхроне с FK — иначе
                // мини-профиль/списки показывают старого пригласителя.
                $consultant->inviterName = $data['inviter']
                    ? DB::table('consultant')->where('id', $data['inviter'])->value('personName')
                    : null;
            }

            // --- WebUser columns ---
            if ($consultant->webUser) {
                $current = DB::table('WebUser')->where('id', $consultant->webUser)->first();

                $userUpdates = [];
                $map = ['firstName', 'lastName', 'patronymic', 'email', 'phone', 'nicTG', 'gender', 'birthDate', 'role'];
                foreach ($map as $col) {
                    if (! array_key_exists($col, $data)) continue;
                    $new = $data[$col] ?: null;
                    $old = $current->{$col} ?? null;
                    if ((string) $old !== (string) $new) {
                        $diff[$col] = ['from' => $old, 'to' => $new];
                    }
                    $userUpdates[$col] = $new;
                }
                if (array_key_exists('isBlocked', $data)) {
                    $newBlocked = (bool) $data['isBlocked'];
                    $oldBlocked = (bool) ($current->isBlocked ?? false);
                    if ($newBlocked !== $oldBlocked) {
                        $diff['isBlocked'] = ['from' => $oldBlocked, 'to' => $newBlocked];
                    }
                    $userUpdates['isBlocked'] = $newBlocked;
                }
                if (! empty($data['newPassword'])) {
                    $userUpdates['password'] = \Illuminate\Support\Facades\Hash::make($data['newPassword']);
                    $diff['password'] = ['from' => '***', 'to' => '***'];
                }
                if (! empty($userUpdates)) {
                    DB::table('WebUser')->where('id', $consultant->webUser)->update($userUpdates);
                }

                // При блокировке отзываем токены — иначе залогиненный партнёр
                // работает до истечения токена (≤7 дней).
                if (! empty($userUpdates['isBlocked'])) {
                    \App\Models\User::find($consultant->webUser)?->tokens()->delete();
                }

                // Keep consultant.personName in sync with WebUser name parts
                if (isset($userUpdates['firstName']) || isset($userUpdates['lastName']) || isset($userUpdates['patronymic'])) {
                    $u = DB::table('WebUser')->where('id', $consultant->webUser)->first();
                    $consultant->personName = trim("{$u->lastName} {$u->firstName} {$u->patronymic}");
                }
            }

            $consultant->save();

            // Каскад смены ФИО в видимые денорм-копии имени консультанта, чтобы
            // оно поменялось ВЕЗДЕ: пригласитель у приглашённых, консультант в
            // контрактах и клиентах. Внутренние calc-денормы (баланс/qualLog)
            // не трогаем — их переписывают раннеры, часть заморожена cutoff'ом.
            if ($consultant->wasChanged('personName')) {
                $this->propagateConsultantName($consultant->id, $consultant->personName);
            }
        });

        // В audit_log пишем только если действительно что-то поменялось,
        // иначе «История изменений» забивалась бы пустыми «нажал Сохранить».
        if (! empty($diff)) {
            Audit::log('partner_update', 'consultant', $consultant->id, [
                'diff' => $diff,
            ]);
        }

        return response()->json(['message' => 'Обновлён', 'id' => $consultant->id]);
    }

    /**
     * Распространить новое ФИО консультанта по всем видимым денорм-копиям,
     * чтобы имя поменялось ВЕЗДЕ за один заход:
     *   - inviterName у всех, кого этот консультант пригласил;
     *   - consultantName во всех его контрактах;
     *   - consultantName во всех его клиентских карточках.
     * Логи изменений (changeConsultant*Log) и calc-денормы (баланс/qualLog)
     * намеренно не трогаем — первые историчны, вторые переписывают раннеры.
     */
    private function propagateConsultantName(int $consultantId, ?string $newName): void
    {
        DB::table('consultant')->where('inviter', $consultantId)
            ->update(['inviterName' => $newName]);
        DB::table('contract')->where('consultant', $consultantId)
            ->update(['consultantName' => $newName]);
        DB::table('client')->where('consultant', $consultantId)
            ->update(['consultantName' => $newName]);
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
        // Смена статуса / блокировка / роль наставника — только admin.
        // Раньше любой staff из admin-route-group мог дёрнуть и обойти UI.
        if (! $request->user()->hasAnyRole(['admin'])) {
            return response()->json(['message' => 'Недостаточно прав'], 403);
        }

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
                        $c->inviterName = DB::table('consultant')
                            ->where('id', $data['inviter'])->value('personName');
                        $c->save();
                        $ok++;
                        break;
                    case 'block':
                    case 'unblock':
                        if ($c->webUser) {
                            DB::table('WebUser')->where('id', $c->webUser)
                                ->update(['isBlocked' => $data['action'] === 'block']);
                            // Блокировка выкидывает уже залогиненного — отзываем токены.
                            if ($data['action'] === 'block') {
                                \App\Models\User::find($c->webUser)?->tokens()->delete();
                            }
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

    /** Смена статуса активности партнёра — только для роли admin. */
    public function changePartnerStatus(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['admin'])) {
            return response()->json(['message' => 'Недостаточно прав'], 403);
        }

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
     * История смены статусов партнёра.
     *
     * Источник — Spatie\Activitylog `activity_log`. Consultant уже логирует
     * `activity` через LogsActivity (см. Consultant::getActivitylogOptions).
     * Возвращаем только события, где реально менялось поле activity:
     * автор (ФИО сотрудника или «Система»), дата, было → стало.
     */
    /**
     * Полная история изменений партнёра — для блока «История изменений»
     * под смены статуса. Объединяем:
     *   1. activity_log (Spatie) — изменения колонок Consultant + ручные
     *      override-статусы (manual-status-override).
     *   2. audit_log — partner_update (включая поля WebUser, обновляемые
     *      через DB::table мимо Eloquent — Spatie их не видит).
     *
     * Каждая запись формата:
     *   { id, source, createdAt, author, action, changes: [{field, from, to}] }
     */
    public function partnerChangeLog(int $id): JsonResponse
    {
        // --- 1. Spatie activity_log (Consultant) ---
        $spatieRows = DB::table('activity_log')
            ->where('subject_type', \App\Models\Consultant::class)
            ->where('subject_id', $id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // --- 2. audit_log (partner_update + статус-смены через сервис) ---
        $auditRows = DB::table('audit_log')
            ->where('entity', 'consultant')
            ->where('entity_id', (string) $id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // Авторы — собираем все WebUser id одним запросом, без N+1.
        $causerIds = $spatieRows->pluck('causer_id')->filter()
            ->merge($auditRows->pluck('user_id')->filter())
            ->unique();
        $causers = $causerIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $causerIds)
                ->select(['id', 'firstName', 'lastName', 'patronymic'])->get()->keyBy('id')
            : collect();
        $authorOf = function ($uid) use ($causers) {
            if (! $uid) return 'Система';
            $u = $causers[$uid] ?? null;
            if (! $u) return "Пользователь #{$uid}";
            $name = trim("{$u->lastName} {$u->firstName} {$u->patronymic}");
            return $name !== '' ? $name : "Пользователь #{$uid}";
        };

        // Лейблы полей (англ. → русский) для UI. Что не покрыто — показываем как есть.
        $fieldLabels = [
            'firstName' => 'Имя', 'lastName' => 'Фамилия', 'patronymic' => 'Отчество',
            'email' => 'Email', 'phone' => 'Телефон', 'nicTG' => 'Telegram',
            'gender' => 'Пол', 'birthDate' => 'Дата рождения', 'role' => 'Роль(и)',
            'isBlocked' => 'Блокировка', 'password' => 'Пароль',
            'participantCode' => 'Реф. код', 'inviter' => 'Пригласивший',
            'activity' => 'Статус активности', 'status' => 'Квалификация',
            'active' => 'Активен', 'acceptance' => 'Согласие',
            'webUser' => 'WebUser', 'person' => 'Person',
            'activationDeadline' => 'Дедлайн активации',
            'yearPeriodEnd' => 'Конец годового периода',
            'terminationCount' => 'Кол-во терминаций',
            'dateActivity' => 'Дата активации',
            'dateDeactivity' => 'Дата деактивации',
            'dateDeleted' => 'Дата удаления (soft)',
            'status_and_lvl' => 'Статус + уровень',
            'qualificationLocked' => 'Квалификация заблок.',
            'personName' => 'ФИО',
        ];
        $activityLabel = function ($v) {
            if ($v === null || $v === '') return null;
            $enum = PartnerActivity::tryFrom((int) $v);
            return $enum ? $enum->label() : (string) $v;
        };
        $renderValue = function ($field, $val) use ($activityLabel) {
            if ($val === null || $val === '') return null;
            if ($field === 'activity') return $activityLabel($val);
            if (is_bool($val)) return $val ? 'да' : 'нет';
            return (string) $val;
        };

        $entries = [];

        foreach ($spatieRows as $r) {
            $props = json_decode($r->properties ?: '{}', true);
            $newAttrs = $props['attributes'] ?? [];
            $oldAttrs = $props['old'] ?? [];
            $changes = [];
            $keys = array_unique(array_merge(array_keys($newAttrs), array_keys($oldAttrs)));
            foreach ($keys as $k) {
                $oldV = $oldAttrs[$k] ?? null;
                $newV = $newAttrs[$k] ?? null;
                if ((string) $oldV === (string) $newV) continue;
                $changes[] = [
                    'field' => $k,
                    'fieldLabel' => $fieldLabels[$k] ?? $k,
                    'from' => $renderValue($k, $oldV),
                    'to' => $renderValue($k, $newV),
                ];
            }
            // Override-логи проходят с пустыми атрибутами (logged через activity()->log).
            // Покажем их как отдельные события с комментарием.
            $action = $r->event ?: ($r->description ?: 'change');
            if (empty($changes) && empty($props['comment'])) {
                continue;
            }
            $entries[] = [
                'id' => 'a' . $r->id,
                'source' => 'activity',
                'createdAt' => $r->created_at,
                'author' => $authorOf($r->causer_id),
                'action' => $action,
                'comment' => $props['comment'] ?? null,
                'changes' => $changes,
            ];
        }

        foreach ($auditRows as $r) {
            $payload = json_decode($r->payload ?: '{}', true);
            $diff = $payload['diff'] ?? [];
            // Пропускаем старые partner_update-записи без diff'а — они
            // содержали только список названий полей и ничего не дают UI.
            if ($r->action === 'partner_update' && empty($diff)) continue;
            $changes = [];
            foreach ($diff as $field => $pair) {
                $changes[] = [
                    'field' => $field,
                    'fieldLabel' => $fieldLabels[$field] ?? $field,
                    'from' => $renderValue($field, $pair['from'] ?? null),
                    'to' => $renderValue($field, $pair['to'] ?? null),
                ];
            }
            $entries[] = [
                'id' => 'u' . $r->id,
                'source' => 'audit',
                'createdAt' => $r->created_at,
                'author' => $authorOf($r->user_id) ?: ($r->user_email ?: 'Система'),
                'action' => $r->action,
                'comment' => $payload['comment'] ?? null,
                'changes' => $changes,
            ];
        }

        // Сортировка по дате убыв., обрезаем до 100 — больше в UI не нужно.
        usort($entries, fn ($a, $b) => strcmp((string) $b['createdAt'], (string) $a['createdAt']));
        $entries = array_slice($entries, 0, 100);

        return response()->json(['data' => $entries]);
    }

    public function partnerStatusHistory(int $id): JsonResponse
    {
        $rows = DB::table('activity_log')
            ->where('subject_type', \App\Models\Consultant::class)
            ->where('subject_id', $id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $causerIds = $rows->pluck('causer_id')->filter()->unique();
        $causers = $causerIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $causerIds)
                ->select(['id', 'firstName', 'lastName', 'patronymic'])->get()->keyBy('id')
            : collect();

        $activityLabel = function ($v) {
            if ($v === null || $v === '') return null;
            $enum = PartnerActivity::tryFrom((int) $v);
            return $enum ? $enum->label() : (string) $v;
        };

        $history = [];
        foreach ($rows as $r) {
            $props = json_decode($r->properties ?: '{}', true);
            $oldA = $props['old']['activity'] ?? null;
            $newA = $props['attributes']['activity'] ?? null;
            // Берём только события, где реально менялась activity.
            if ($oldA === null && $newA === null) continue;
            if ($oldA === $newA) continue;

            $causer = $r->causer_id ? ($causers[$r->causer_id] ?? null) : null;
            $author = $causer
                ? trim("{$causer->lastName} {$causer->firstName} {$causer->patronymic}")
                : 'Система';

            $history[] = [
                'id' => $r->id,
                'createdAt' => $r->created_at,
                'author' => $author,
                'oldStatus' => $activityLabel($oldA),
                'newStatus' => $activityLabel($newA),
                'comment' => $props['comment'] ?? null,
            ];
        }

        return response()->json(['data' => $history]);
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
            'number' => '№ контракта',
            'counterpartyContractId' => 'ИД контрагента',
            'client' => 'Клиент', 'consultant' => 'Партнёр',
            'product' => 'Продукт', 'program' => 'Программа',
            'status' => 'Статус', 'currency' => 'Валюта',
            'ammount' => 'Сумма', 'amount' => 'Сумма',
            'country' => 'Страна оформления',
            'createDate' => 'Дата создания',
            'openDate' => 'Дата открытия',
            'closeDate' => 'Дата закрытия',
            'riskProfile' => 'Риск-профиль',
            'setup' => 'Сетап',
            'type' => 'Тип (страх.)',
            'comment' => 'Комментарий',
        ];

        // Собираем все ID, чтобы резолвить human-friendly значения FK одним батчем.
        $idsByField = [
            'client' => [], 'consultant' => [], 'product' => [], 'program' => [],
            'status' => [], 'currency' => [], 'country' => [], 'riskProfile' => [], 'setup' => [],
        ];
        foreach ($rows as $r) {
            $props = json_decode($r->properties ?: '{}', true);
            foreach (['old', 'attributes'] as $bucket) {
                foreach ($props[$bucket] ?? [] as $field => $val) {
                    if (isset($idsByField[$field]) && $val !== null && $val !== '') {
                        $idsByField[$field][] = (int) $val;
                    }
                }
            }
        }
        $resolveFn = function (string $table, string $col, array $ids): array {
            $ids = array_values(array_unique(array_filter($ids)));
            if (! $ids) return [];
            return DB::table($table)->whereIn('id', $ids)->pluck($col, 'id')->toArray();
        };
        $maps = [
            'client'      => $resolveFn('client', 'personName', $idsByField['client']),
            'consultant'  => $resolveFn('consultant', 'personName', $idsByField['consultant']),
            'product'     => $resolveFn('product', 'name', $idsByField['product']),
            'program'     => $resolveFn('program', 'name', $idsByField['program']),
            'status'      => $resolveFn('contractStatus', 'name', $idsByField['status']),
            'currency'    => $resolveFn('currency', 'symbol', $idsByField['currency']),
            'country'     => $resolveFn('country', 'countryNameRu', $idsByField['country']),
            'riskProfile' => $resolveFn('riskProfile', 'name', $idsByField['riskProfile']),
            'setup'       => $resolveFn('setup', 'setup', $idsByField['setup']),
        ];

        $humanize = function ($field, $val) use ($maps) {
            if ($val === null || $val === '') return null;
            if (isset($maps[$field][$val])) return $maps[$field][$val];
            // Даты приводим к Y-m-d
            if (in_array($field, ['createDate', 'openDate', 'closeDate'], true)) {
                try { return (new \DateTimeImmutable((string) $val))->format('Y-m-d'); } catch (\Throwable) { return $val; }
            }
            return $val;
        };

        $data = $rows->map(function ($r) use ($causers, $fieldLabels, $humanize) {
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
                    'old' => $humanize($field, $oldVal),
                    'new' => $humanize($field, $newVal),
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
        // 4 диапазона дат per spec ✅Статусы партнеров §1
        if ($request->filled('created_from')) $detailQuery->where('dateCreated', '>=', $request->created_from);
        if ($request->filled('created_to')) $detailQuery->where('dateCreated', '<=', $request->created_to . ' 23:59:59');
        if ($request->filled('activity_from')) $detailQuery->where('dateActivity', '>=', $request->activity_from);
        if ($request->filled('activity_to')) $detailQuery->where('dateActivity', '<=', $request->activity_to . ' 23:59:59');
        if ($request->filled('plan_from')) $detailQuery->where('dateDeterministicPlan', '>=', $request->plan_from);
        if ($request->filled('plan_to')) $detailQuery->where('dateDeterministicPlan', '<=', $request->plan_to . ' 23:59:59');
        if ($request->filled('term_from')) $detailQuery->where('dateDeterministic', '>=', $request->term_from);
        if ($request->filled('term_to')) $detailQuery->where('dateDeterministic', '<=', $request->term_to . ' 23:59:59');

        $detailTotal = $detailQuery->count();
        // camelCase колонки квотируем — см. partners() выше.
        $this->applySorting($detailQuery, $request, [
            'personName'            => '"personName"',
            'activityName'          => 'activity',
            'dateCreated'           => '"dateCreated"',
            'dateActivity'          => '"dateActivity"',
            'dateDeterministicPlan' => '"dateDeterministicPlan"',
            'dateDeterministic'     => '"dateDeterministic"',
            'personalVolume'        => '"personalVolume"',
        ], '"personName"', 'asc');

        $detailRows = $detailQuery
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        // Batch load activity names
        $activityIds = $detailRows->pluck('activity')->filter()->unique();
        $activityNames = $activityIds->isNotEmpty()
            ? DB::table('directory_of_activities')->whereIn('id', $activityIds)->pluck('name', 'id')
            : collect();

        // Email партнёра: основной источник — WebUser (consultant.webUser →
        // WebUser.email). У legacy/терминированных webUser часто пуст —
        // фолбэк на Directual-контакт (consultant.person → person.email).
        // Покрытие с фолбэком ~97% против ~53% только по WebUser.
        $webUserIds = $detailRows->pluck('webUser')->filter()->unique();
        $emailByWebUser = $webUserIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $webUserIds)->pluck('email', 'id')
            : collect();
        $personIds = $detailRows->pluck('person')->filter()->unique();
        $emailByPerson = $personIds->isNotEmpty()
            ? DB::table('person')->whereIn('id', $personIds)->pluck('email', 'id')
            : collect();

        // Per spec ✅Статусы партнеров §2 col.7: «Сумма ЛП от даты активации
        // (каждый год обнуляется)». Считаем ЛП за текущий годовой цикл,
        // отсчитывая от dateActivity. Один batch-SUM по commission, чтобы
        // не плодить N+1 на 1k+ строках.
        $consultantIds = $detailRows->pluck('id')->filter()->unique()->values();
        $lpFromActivation = collect();
        if ($consultantIds->isNotEmpty()) {
            $rows = DB::select('
                WITH window_start AS (
                    SELECT
                        c.id,
                        c."dateActivity"
                          + make_interval(years => FLOOR(EXTRACT(YEAR FROM AGE(NOW(), c."dateActivity")))::int)
                          AS year_start
                    FROM consultant c
                    WHERE c.id = ANY(?::int[]) AND c."dateActivity" IS NOT NULL
                )
                SELECT w.id, COALESCE(SUM(cm."personalVolume"), 0) AS lp
                FROM window_start w
                LEFT JOIN commission cm
                  ON cm.consultant = w.id
                 AND cm."deletedAt" IS NULL
                 AND cm.date >= w.year_start
                GROUP BY w.id
            ', ['{' . $consultantIds->implode(',') . '}']);
            foreach ($rows as $r) {
                $lpFromActivation[$r->id] = (float) $r->lp;
            }
        }

        $details = $detailRows->map(function ($c) use ($activityNames, $lpFromActivation, $emailByWebUser, $emailByPerson) {
                $activityName = $c->activity ? ($activityNames[$c->activity] ?? '—') : '—';

                // Рассчитать "будет терминирован" для активных
                $willTerminate = null;
                if ($c->activity == 1 && $c->dateActivity) { // Активный
                    $willTerminate = \Carbon\Carbon::parse($c->dateActivity)->addYear()->format('Y-m-d');
                }

                return [
                    'id' => $c->id,
                    'personName' => $c->personName,
                    'email' => ($c->webUser ? ($emailByWebUser[$c->webUser] ?? null) : null)
                        ?: ($c->person ? ($emailByPerson[$c->person] ?? null) : null) ?: null,
                    'activityId' => $c->activity,
                    'activityName' => $activityName,
                    'dateCreated' => $c->dateCreated,
                    'dateActivity' => $c->dateActivity,
                    'dateDeactivity' => $c->dateDeactivity,
                    'dateDeterministic' => $c->dateDeterministic,
                    'dateDeterministicPlan' => $c->dateDeterministicPlan,
                    'willTerminate' => $willTerminate,
                    'terminationCount' => $c->terminationCount ?? 0,
                    // ЛП «глобальное» из consultant.personalVolume (для совместимости).
                    'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                    // ЛП с даты активации, обнуляющееся раз в год — то самое поле из спеки.
                    'lpFromActivation' => round((float) ($lpFromActivation[$c->id] ?? 0), 2),
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
     * Единые правила валидации формы клиента (storeClient + updateClient).
     *
     * ФИО/город — только кириллица + пробел/дефис (строгий формат
     * по запросу заказчика 2026-05-13: «не как попало»).
     * Email — стандартный, фронт дополнительно режет нелатиницу.
     * Phone — формат +CC… от vue-tel-input (E.164), храним как пришло.
     */
    private static function clientValidationRules(): array
    {
        $cyrillicRegex = '/^[А-Яа-яЁё][А-Яа-яЁё\s\-]*$/u';
        return [
            'firstName' => ['required', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'lastName' => ['required', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'patronymic' => ['nullable', 'string', 'max:255', 'regex:' . $cyrillicRegex],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'birthDate' => ['nullable', 'date'],
            'city' => ['nullable', 'string', 'max:128', 'regex:' . $cyrillicRegex],
            'consultant' => ['required', 'integer', 'exists:consultant,id'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private static function clientValidationMessages(): array
    {
        return [
            'firstName.regex' => 'Имя — только русские буквы',
            'lastName.regex' => 'Фамилия — только русские буквы',
            'patronymic.regex' => 'Отчество — только русские буквы',
            'city.regex' => 'Город — только русские буквы',
        ];
    }

    /**
     * GET /admin/clients/check-duplicates?firstName=X&lastName=Y
     *
     * Антидубль для шага 2 (полной формы) — оператор ввёл ФИО, нам надо
     * показать ему всех тёзок с email/телефоном/наставником, даже если
     * на шаге 1 он искал по фамилии, а сейчас поправил имя.
     *
     * Возвращает до 5 клиентов с совпадающими firstName+lastName.
     */
    public function checkClientDuplicates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'excludeId' => 'nullable|integer',
        ]);

        $firstName = mb_strtolower(trim($data['firstName']));
        $lastName = mb_strtolower(trim($data['lastName']));
        if (mb_strlen($firstName) < 2 || mb_strlen($lastName) < 2) {
            return response()->json(['duplicates' => []]);
        }

        $query = DB::table('client as c')
            ->leftJoin('person as p', 'p.id', '=', 'c.person')
            ->leftJoin('consultant as cn', 'cn.id', '=', 'c.consultant')
            ->whereNull('c.dateDeleted')
            ->whereRaw('LOWER(p."firstName") = ?', [$firstName])
            ->whereRaw('LOWER(p."lastName") = ?', [$lastName])
            ->select([
                'c.id', 'c.personName', 'p.email', 'p.phone', 'p.city',
                'p.birthDate', 'c.consultant as consultantId',
                'cn.personName as consultantName',
                'c.dateCreated',
            ])
            ->orderByDesc('c.dateCreated')
            ->limit(5);

        if (! empty($data['excludeId'])) {
            $query->where('c.id', '!=', $data['excludeId']);
        }

        return response()->json(['duplicates' => $query->get()]);
    }

    /**
     * GET /admin/consultants/{id}/chain
     *
     * Цепочка наставников выбранного консультанта вверх по структуре
     * (inviter → inviter → ... до корня). Нужно для UI при создании
     * клиента: чтобы сотрудник видел не только прямого ФК, но и всю
     * ветку выше — кто за кем стоит. Защита от циклов — visited set,
     * жёсткий лимит 20 уровней.
     */
    public function consultantChain(int $id): JsonResponse
    {
        $chain = [];
        $visited = [];
        $currentId = $id;
        for ($i = 0; $i < 20; $i++) {
            if (in_array($currentId, $visited, true)) break;
            $visited[] = $currentId;

            $row = DB::table('consultant')
                ->where('id', $currentId)
                ->select(['id', 'personName', 'inviter', 'status_and_lvl'])
                ->first();
            if (! $row) break;

            $levelTitle = $row->status_and_lvl
                ? DB::table('status_levels')->where('id', $row->status_and_lvl)->value('title')
                : null;

            $chain[] = [
                'id' => $row->id,
                'personName' => $row->personName,
                'level' => $levelTitle,
                'depth' => count($chain),
            ];

            if (! $row->inviter) break;
            $currentId = (int) $row->inviter;
        }

        return response()->json(['chain' => $chain]);
    }

    /**
     * POST /admin/clients — создать клиента per spec ✅Клиенты §3.
     * Двухшаг (антидубль) делается на фронте, эндпоинт принимает уже
     * подтверждённую новую персону.
     */
    public function storeClient(Request $request): JsonResponse
    {
        $data = $request->validate(self::clientValidationRules(), self::clientValidationMessages());

        $personName = trim("{$data['lastName']} {$data['firstName']}" . (! empty($data['patronymic']) ? ' ' . $data['patronymic'] : ''));

        $clientId = DB::transaction(function () use ($data, $personName) {
            // Клиент — это legacy person-запись (Directual). client.person →
            // person.id (своя id-namespace, не WebUser). WebUser не создаём:
            // у клиента нет login-аккаунта, он живёт только в person.
            // Если клиент позже станет партнёром, регистрация заведёт
            // отдельную WebUser-запись.
            \App\Support\LegacyId::syncSequence('person'); // защита от duplicate person_pkey
            $personId = DB::table('person')->insertGetId([
                'firstName' => $data['firstName'],
                'lastName' => $data['lastName'],
                'patronymic' => $data['patronymic'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'birthDate' => $data['birthDate'] ?? null,
                'city' => $data['city'] ?? null,
                'role' => 'client',
                'dateCreated' => now()->toIso8601String(),
            ]);

            \App\Support\LegacyId::syncSequence('client');
            return DB::table('client')->insertGetId([
                'person' => $personId,
                'personName' => $personName,
                'consultant' => $data['consultant'],
                'comment' => $data['comment'] ?? null,
                'dateCreated' => now(),
            ]);
        });

        return response()->json(['message' => 'Клиент создан', 'id' => $clientId], 201);
    }

    /**
     * Редактирование карточки клиента.
     *
     * Per spec ✅Клиенты §4 + cabinetPermissions backoffice.clients=EDIT.
     * Пишем в person (личные данные) и client (наставник + комментарий)
     * одной транзакцией. personName на client денормализован — обновляем
     * вместе с firstName/lastName/patronymic.
     */
    public function updateClient(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(self::clientValidationRules(), self::clientValidationMessages());

        $client = DB::table('client')->where('id', $id)->first();
        if (! $client) {
            return response()->json(['message' => 'Клиент не найден'], 404);
        }
        if ($client->dateDeleted ?? null) {
            return response()->json(['message' => 'Клиент удалён, редактирование недоступно'], 422);
        }

        $personName = trim("{$data['lastName']} {$data['firstName']}" . (! empty($data['patronymic']) ? ' ' . $data['patronymic'] : ''));

        DB::transaction(function () use ($client, $data, $personName) {
            if ($client->person) {
                DB::table('person')->where('id', $client->person)->update([
                    'firstName' => $data['firstName'],
                    'lastName' => $data['lastName'],
                    'patronymic' => $data['patronymic'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'birthDate' => $data['birthDate'] ?? null,
                    'city' => $data['city'] ?? null,
                ]);
            }

            DB::table('client')->where('id', $client->id)->update([
                'personName' => $personName,
                'consultant' => $data['consultant'],
                'comment' => $data['comment'] ?? null,
            ]);
        });

        Audit::log('update', 'client', $id, [
            'consultant' => $data['consultant'],
        ]);

        return response()->json(['message' => 'Клиент обновлён']);
    }

    public function deleteClient(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

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

        Audit::log('delete', 'client', $id, ['reason' => $request->input('reason')]);

        return response()->json(['message' => 'Клиент удалён']);
    }

    /** Клиенты — админ-список всех клиентов */
    public function clients(Request $request): JsonResponse
    {
        // Soft-deleted клиентов в админке тоже скрываем по умолчанию.
        // Если потребуется аудит-лог удалений — отдельный endpoint.
        $query = DB::table('client')->whereNull('dateDeleted');

        if ($request->filled('search')) {
            $query->where('personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('consultant')) {
            $query->where('consultant', $request->consultant);
        }
        // Доп. фильтры per spec ✅Клиенты §1
        if ($request->filled('id')) {
            $query->where('id', (int) $request->id);
        }
        if ($request->filled('consultant_name')) {
            $consName = '%' . $request->consultant_name . '%';
            $query->whereIn('consultant', function ($sub) use ($consName) {
                $sub->select('id')->from('consultant')->where('personName', 'ilike', $consName);
            });
        }
        // Фильтр по статусу/квалификации наставника (10-уровневая матрица
        // status_levels, см. project_commission_spec). Юзер выбирает уровень
        // в выпадашке «Статус наставника» — оператор хочет фильтровать
        // клиентов по ФК/Эксперт/… своего наставника.
        if ($request->filled('consultant_status_id')) {
            $statusId = (int) $request->consultant_status_id;
            $query->whereIn('consultant', function ($sub) use ($statusId) {
                $sub->select('id')->from('consultant')
                    ->where('status_and_lvl', $statusId);
            });
        }
        if ($request->filled('comment')) {
            $query->where('comment', 'ilike', '%' . $request->comment . '%');
        }
        if ($request->filled('created_from')) {
            $query->where('dateCreated', '>=', $request->created_from);
        }
        if ($request->filled('created_to')) {
            $query->where('dateCreated', '<=', $request->created_to . ' 23:59:59');
        }

        $total = $query->count();
        $this->applySorting($query, $request, [
            'personName' => '"personName"',
            'consultantName' => '"consultantName"',
            'dateCreated' => '"dateCreated"',
        ], 'id', 'desc');
        $rows = $query
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

        // Batch load consultant names + статус (квалификация) — оператору
        // нужно видеть текущий уровень партнёра рядом с клиентом.
        $consultantIds = $rows->pluck('consultant')->filter()->unique();
        $consultantInfo = $consultantIds->isNotEmpty()
            ? DB::table('consultant as c')
                ->leftJoin('status_levels as sl', 'sl.id', '=', 'c.status_and_lvl')
                ->whereIn('c.id', $consultantIds)
                ->select('c.id', 'c.personName', 'sl.title as statusName', 'sl.level as statusLevel')
                ->get()->keyBy('id')
            : collect();

        $clients = $rows->map(function ($c) use ($persons, $contractCounts, $personPartners, $consultantInfo) {
                $person = $c->person ? ($persons[$c->person] ?? null) : null;
                $cInfo = $c->consultant ? ($consultantInfo[$c->consultant] ?? null) : null;

                return [
                    'id' => $c->id,
                    'dsId' => $c->idDs,
                    'personId' => $c->person,
                    'personName' => $c->personName,
                    'active' => (bool) $c->active,
                    'consultantId' => $c->consultant,
                    'consultantName' => $cInfo?->personName,
                    'consultantStatus' => $cInfo?->statusName
                        ? ($cInfo->statusLevel . ' [' . $cInfo->statusName . ']')
                        : null,
                    'dateCreated' => $c->dateCreated,
                    'workSince' => $c->workSince,
                    'contractCount' => $contractCounts[$c->id] ?? 0,
                    'isPartner' => $c->person ? isset($personPartners[$c->person]) : false,
                    'comment' => $c->comment,
                    'email' => $person?->email ?? null,
                    'phone' => $person?->phone ?? null,
                    'birthDate' => $person?->birthDate ?? null,
                    'city' => $person?->city ?? null,
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
        // Per spec ✅Реквизиты партнёров: status фильтр от UI присылается
        // как 'verified' / 'pending' / 'rejected'. Маппим на колонки
        // `verified` (boolean) + `status` (1=backoffice, 2=consultant-возврат, 3=verified).
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'verified':
                    $query->where('verified', true);
                    break;
                case 'rejected':
                    // Отклонено = есть причина отказа (rejection_reason) и не
                    // верифицировано. NB: status=2 ставится на ЛЮБОЕ сохранение
                    // (и «на проверке» тоже) — отличаем именно по причине отказа.
                    $query->where('verified', false)
                          ->whereNotNull('rejection_reason')->where('rejection_reason', '!=', '');
                    break;
                case 'pending':
                    // На проверке = не верифицировано и БЕЗ причины отказа.
                    $query->where('verified', false)->where(function ($q) {
                        $q->whereNull('rejection_reason')->orWhere('rejection_reason', '');
                    });
                    break;
            }
        }
        // Фильтр по статусу партнёра (consultant.activity): 1 Активен,
        // 3 Терминирован, 4 Зарегистрирован, 5 Исключён. Legacy 2 = «Активен».
        if ($request->filled('partner_status')) {
            $statuses = array_map('intval', (array) $request->input('partner_status'));
            if (in_array(1, $statuses, true)) {
                $statuses[] = 2;
            }
            $ids = DB::table('consultant')->whereIn('activity', $statuses)->pluck('id')->all();
            $query->whereIn('consultant', $ids ?: [-1]);
        }
        // Фильтр по приостановке выплат: 'request' — партнёр сам подал запрос на
        // смену реквизитов (есть pending-запрос), 'manual' — Катя проставила
        // галочку вручную (приостановлен, но активного запроса нет).
        if ($request->filled('suspend')) {
            $pendingIds = DB::table('bank_requisite_change_requests')
                ->where('status', 'pending')->distinct()->pluck('consultant')->all();
            if ($request->input('suspend') === 'request') {
                $query->whereIn('consultant', $pendingIds ?: [-1]);
            } elseif ($request->input('suspend') === 'manual') {
                $manualIds = DB::table('consultant')
                    ->where('payments_suspended', true)
                    ->when(! empty($pendingIds), fn ($q) => $q->whereNotIn('id', $pendingIds))
                    ->pluck('id')->all();
                $query->whereIn('consultant', $manualIds ?: [-1]);
            }
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $isNumericLike = preg_match('/^\d{4,}$/', $s) === 1;
            if ($isNumericLike) {
                // Похоже на ИНН → ищем строго по нему.
                $query->where('inn', 'ilike', "%{$s}%");
            } else {
                // Текст → ищем ТОЛЬКО по ФИО консультанта-владельца.
                // Раньше OR'или с individualEntrepreneur, что давало дубли:
                // если ИП Зарипова используют 5 партнёров, поиск «Зарипов»
                // возвращал все 5 строк, а нужно только Зарипова. По правкам
                // 2026-05-05 — только ФИО владельца ИП.
                $consultantIds = DB::table('consultant')
                    ->where('personName', 'ilike', "%{$s}%")
                    ->pluck('id');
                if ($consultantIds->isNotEmpty()) {
                    $query->whereIn('consultant', $consultantIds);
                } else {
                    // Не нашли консультанта — пустой результат, чтобы фильтр
                    // не «съезжал» на другие совпадения.
                    $query->whereRaw('1 = 0');
                }
            }
        }

        // Дедуп: один реквизит на консультанта. Приоритет — verified=true,
        // затем самая новая запись (по id DESC). Раньше у Зарипова было
        // 4 строки в списке (3 unverified + 1 verified) — теперь 1.
        // Берём id-белый список через подзапрос, чтобы пагинация и
        // count работали корректно с фильтрами выше.
        $primaryIds = (clone $query)
            ->select(DB::raw('DISTINCT ON (consultant) id'))
            ->orderBy('consultant')
            ->orderByDesc('verified')
            ->orderByDesc('id')
            ->pluck('id');

        $query2 = Requisite::whereIn('id', $primaryIds);
        $total = $query2->count();
        $this->applySorting($query2, $request, [
            'individualEntrepreneur' => '"individualEntrepreneur"',
            'inn' => 'inn',
            'verified' => 'verified',
            'createdAt' => '"createdAt"',
            // Дата поступления на проверку = последнее изменение реквизита.
            'submittedAt' => '"dateChange"',
        ], 'id', 'desc');
        $rows = $query2
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        // Batch load consultant names + флаг приостановки выплат (для подсветки)
        $consultantIds = $rows->pluck('consultant')->filter()->unique();
        $consultantNames = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('personName', 'id')
            : collect();
        $suspendedMap = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('payments_suspended', 'id')
            : collect();
        // Партнёры с активным запросом на смену реквизитов (сами подали).
        $pendingChangeSet = $consultantIds->isNotEmpty()
            ? DB::table('bank_requisite_change_requests')->where('status', 'pending')
                ->whereIn('consultant', $consultantIds)->distinct()->pluck('consultant')->flip()
            : collect();

        // Batch load bank requisites
        $reqIds = $rows->pluck('id')->filter()->unique();
        $bankReqs = $reqIds->isNotEmpty()
            ? BankRequisite::whereIn('requisites', $reqIds)->whereNull('deletedAt')->get()->keyBy('requisites')
            : collect();

        $requisites = $rows->map(function ($r) use ($consultantNames, $bankReqs, $suspendedMap, $pendingChangeSet) {
                $bankReq = $bankReqs[$r->id] ?? null;

                // Резолвим verificationStatus для UI: verified / pending / rejected.
                // «Отклонено» — только когда есть причина отказа (rejection_reason),
                // т.к. status=2 ставится и при обычном сохранении («на проверке»).
                $verificationStatus = 'pending';
                if ($r->verified) {
                    $verificationStatus = 'verified';
                } elseif (filled($r->rejection_reason)) {
                    $verificationStatus = 'rejected';
                }

                // Дата поступления на проверку = последняя отправка реквизитов
                // (dateChange); для старых записей без dateChange — createdAt.
                $submittedAt = $r->dateChange
                    ?: ($r->createdAt ? \Illuminate\Support\Carbon::parse($r->createdAt) : null);
                // Просрочка считается только пока реквизиты «на проверке».
                $overdue = $verificationStatus === 'pending'
                    && \App\Support\RequisiteSla::isOverdue($submittedAt);

                return [
                    'id' => $r->id,
                    'consultant' => $r->consultant,
                    'consultantId' => $r->consultant,
                    'consultantName' => $r->consultant ? ($consultantNames[$r->consultant] ?? null) : null,
                    'partnerName' => $r->consultant ? ($consultantNames[$r->consultant] ?? null) : null,
                    'individualEntrepreneur' => $r->individualEntrepreneur,
                    'inn' => $r->inn,
                    // Полные поля ИП — диалог верификации читает их из строки
                    // списка. Раньше не отдавались → ОГРН/Адрес/Email/Телефон и
                    // банк показывались прочерками даже при заполненных данных.
                    'ogrn' => $r->ogrn,
                    'address' => $r->address,
                    'email' => $r->email,
                    'phone' => $r->phone,
                    'taxRegime' => $r->tax_regime,
                    'bankName' => $bankReq?->bankName,
                    'bankBik' => $bankReq?->bankBik,
                    'accountNumber' => $bankReq?->accountNumber,
                    'correspondentAccount' => $bankReq?->correspondentAccount,
                    'beneficiaryName' => $bankReq?->beneficiaryName,
                    'verified' => (bool) $r->verified,
                    'verificationStatus' => $verificationStatus,
                    'rejectionReason' => $r->rejection_reason,
                    'hasBankRequisites' => $bankReq !== null,
                    'bankVerified' => $bankReq?->verified ?? false,
                    'submittedAt' => $submittedAt?->toIso8601String(),
                    'overdue' => $overdue,
                    'paymentsSuspended' => (bool) ($suspendedMap[$r->consultant] ?? false),
                    // Источник приостановки: 'request' — партнёр сам подал запрос
                    // на смену; 'manual' — Катя проставила вручную; null — нет.
                    'suspendSource' => $pendingChangeSet->has($r->consultant)
                        ? 'request'
                        : (($suspendedMap[$r->consultant] ?? false) ? 'manual' : null),
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
        $req = DB::table('requisites')->where('id', $id)->whereNull('deletedAt')->first();
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
        $req = DB::table('requisites')->where('id', $id)->whereNull('deletedAt')->first();
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
        $req = DB::table('requisites')->where('id', $id)->whereNull('deletedAt')->first();
        if (! $req) {
            return response()->json(['message' => 'Реквизиты не найдены'], 404);
        }
        if (! $req->inn) {
            return response()->json(['message' => 'ИНН не заполнен'], 422);
        }

        $cleanInn = preg_replace('/\D/', '', (string) $req->inn);

        // Кэшируем DaData-ответ на 1 час, чтобы повторные клики не упирались в throttle.
        $result = \Illuminate\Support\Facades\Cache::remember(
            "dadata:inn:{$cleanInn}",
            3600,
            fn () => app(\App\Services\DadataService::class)->findByInn($cleanInn),
        );

        // «Проверить ИНН»: на СОВПАДЕНИИ ФИО НЕ верифицируем автоматически —
        // решает сотрудник вручную. Но на НЕсовпадении ФИО — снимаем
        // верификацию (safety): verified=false, status=2 (rejected), и
        // помечаем строку красным в списке (2026-06-03).
        $autoRejected = false;
        $rejectReason = null;
        $rejectReasonText = null;
        if (! empty($result['found']) && $req->consultant) {
            $webUserId = DB::table('consultant')->where('id', $req->consultant)->value('webUser');
            if ($webUserId) {
                $user = DB::table('WebUser')->where('id', $webUserId)->first([
                    'firstName', 'lastName', 'patronymic',
                ]);
                if ($user) {
                    $dadata = app(\App\Services\DadataService::class);
                    $result['fioCheck'] = $dadata->compareFio(
                        $result['fio'],
                        $user->lastName,
                        $user->firstName,
                        $user->patronymic,
                    );

                    if (! ($result['fioCheck']['match'] ?? false)) {
                        DB::table('requisites')->where('id', $id)->update([
                            'verified' => false,
                            'status' => 2,
                            'dateChange' => now(),
                        ]);
                        $autoRejected = true;
                        $rejectReason = 'fio';
                        $rejectReasonText = sprintf(
                            'ИП оформлено не на ваше имя. По ИНН в ЕГРИП: «%s», в вашем профиле: «%s». Партнёром ДС может быть только ИП, оформленное на ваше имя.',
                            $result['fioCheck']['actual'] ?? '—',
                            $result['fioCheck']['expected'] ?? '—',
                        );
                    }
                }
            }
        }

        // Налоговый режим: после DaData сразу дёргаем Checko (он бесплатно
        // отдаёт спецрежим в Налоги.ОсобРежим — УСН/ПСН/…, чего нет в free
        // DaData). Фоллбэк — taxSystemLabel из DaData (обычно пуст). Кэш 1ч.
        $taxRegime = $result['taxSystemLabel'] ?? null;
        $checko = app(\App\Services\CheckoService::class);
        if ($checko->isConfigured()) {
            $checkoData = \Illuminate\Support\Facades\Cache::remember(
                "checko:inn:{$cleanInn}",
                3600,
                fn () => $checko->findByInn($cleanInn),
            );
            if (! empty($checkoData['found']) && ! empty($checkoData['taxSystemLabel'])) {
                $taxRegime = $checkoData['taxSystemLabel'];
            }
        }
        $result['taxRegime'] = $taxRegime;

        // Сохраняем найденный режим в реквизит — чтобы он отображался в карточке
        // и списке без повторного запроса к Checko.
        if (! empty($taxRegime)) {
            DB::table('requisites')->where('id', $id)->update(['tax_regime' => $taxRegime]);
        }

        // Партнёр обязан быть ИП на УСН. Если режим определён и НЕ УСН —
        // снимаем верификацию (как при расхождении ФИО) и метим красным.
        // Если режим не определён (null) — не трогаем, решает оператор.
        // ВАЖНО: проверяем УСН как ОТДЕЛЬНЫЙ токен — «АУСН» (автоматизированная
        // УСН) НЕ подходит, хотя содержит подстроку «УСН». «УСН», «УСН (доходы)»,
        // «УСН, ПСН» — подходят.
        $taxIsUsn = null;
        if ($taxRegime) {
            $tokens = preg_split('/[^А-ЯЁ]+/u', mb_strtoupper($taxRegime)) ?: [];
            $taxIsUsn = in_array('УСН', $tokens, true);
        }
        $result['taxIsUsn'] = $taxIsUsn;
        if ($taxIsUsn === false) {
            DB::table('requisites')->where('id', $id)->update([
                'verified' => false,
                'status' => 2,
                'dateChange' => now(),
            ]);
            $autoRejected = true;
            $rejectReason = $rejectReason ?? 'tax';
            // ФИО-причина приоритетнее (если уже выставлена — не перетираем).
            $rejectReasonText = $rejectReasonText ?? sprintf(
                'Режим налогообложения «%s» не подходит. Партнёром ДС может быть только ИП на УСН.',
                $taxRegime,
            );
        }

        // Сохраняем причину отказа — её увидит партнёр в плашке «отказано в
        // верификации» на всех страницах (UserResource/RequisiteResource).
        if ($autoRejected && $rejectReasonText) {
            DB::table('requisites')->where('id', $id)->update(['rejection_reason' => $rejectReasonText]);
        }

        // autoVerified всегда false (авто-верификация отключена). autoRejected
        // = true при расхождении ФИО ИЛИ не-УСН режиме (верификация снята).
        $result['autoVerified'] = false;
        $result['autoRejected'] = $autoRejected;
        $result['autoRejectReason'] = $rejectReason;
        $result['rejectionReason'] = $rejectReasonText;
        return response()->json($result);
    }

    /**
     * Синхронизация банковской строки + платёжного гейта со статусом
     * верификации ИП-реквизита. Без этого при подтверждении ИП банковская
     * строка остаётся verified=false → у партнёра «Банковские реквизиты —
     * На проверке» при уже подтверждённом ИП (баг 2026-06-05). На verify —
     * банк подтверждаем и снимаем гейт (statusRequisites=3); на reject —
     * откатываем банк в «на проверке» и закрываем гейт (statusRequisites=2).
     */
    private function syncRequisiteVerification(Requisite $requisite, bool $verified): void
    {
        DB::table('bankrequisites')
            ->where('requisites', $requisite->id)
            ->whereNull('deletedAt')
            ->update(['verified' => $verified]);

        if ($requisite->consultant) {
            DB::table('consultant')
                ->where('id', $requisite->consultant)
                ->update(['statusRequisites' => $verified ? 3 : 2]);
        }
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
            $requisite->rejection_reason = null; // снимаем причину отказа
            $requisite->dateChange = now();
            $requisite->save();
            // Подтверждаем и банковскую строку + снимаем платёжный гейт.
            $this->syncRequisiteVerification($requisite, true);

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
        // Текст сотрудника = причина отказа для партнёрской плашки. Если пусто —
        // дефолт. Partner увидит её на всех страницах + во вкладке реквизитов.
        $requisite->rejection_reason = $request->input('comment')
            ?: 'Реквизиты отклонены финменеджером. Проверьте данные и отправьте повторно.';
        $requisite->dateChange = now();
        $requisite->save();
        // Откатываем банковскую строку в «на проверке» + закрываем гейт.
        $this->syncRequisiteVerification($requisite, false);

        // Отправка комментария через коммуникацию (legacy-таблица без серийного id).
        if ($request->filled('comment')) {
            DB::transaction(function () use ($requisite, $request) {
                DB::table('platformCommunication')->insert([
                    'id' => LegacyId::next('platformCommunication'),
                    'consultant' => $requisite->consultant,
                    'category' => 1, // Верификация реквизитов
                    'message' => $request->comment,
                    'date' => now(),
                    'direction' => 'ds2p',
                    'read' => false,
                ]);
            });
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
        // Документы обязательного флоу акцепта (Согласие, Политика, Оферта,
        // ПЭП) — Стандарты/Фото исключены через in_acceptance_flow с 2026-06-02.
        $docsQuery = DB::table('agreementPartnersDocuments')->orderBy('number');
        if (Schema::hasColumn('agreementPartnersDocuments', 'in_acceptance_flow')) {
            $docsQuery->where('in_acceptance_flow', true);
        }
        $allDocs = $docsQuery->get(['id', 'name', 'link', 'number']);
        $totalDocs = $allDocs->count() ?: 4;

        $query = DB::table('consultant')->whereNull('dateDeleted');

        if ($request->filled('search')) {
            $query->where('personName', 'ilike', '%' . $request->search . '%');
        }

        // Фильтр по статусу партнёра (consultant.activity): 1 Активен,
        // 3 Терминирован, 4 Зарегистрирован, 5 Исключён. Legacy activity=2
        // трактуется как «Активен», поэтому при выборе 1 добавляем и 2.
        if ($request->filled('partner_status')) {
            $statuses = array_map('intval', (array) $request->input('partner_status'));
            if (in_array(1, $statuses, true)) {
                $statuses[] = 2;
            }
            $query->whereIn('activity', $statuses);
        }

        // Фильтр по виду документа per spec ✅Акцепт документов §1:
        // показываем только консультантов, которые акцептовали (или НЕ
        // акцептовали — в зависимости от accepted) этот конкретный документ.
        $acceptedFilter = $request->input('accepted'); // 'true' | 'false' | null
        if ($request->filled('document_type')) {
            $docId = (int) $request->input('document_type');
            $signedIds = DB::table('partnerAcceptance')
                ->where('documentType', $docId)
                ->where('accepted', true)
                ->pluck('consultant')
                ->unique()
                ->values()
                ->all();

            if ($acceptedFilter === 'false') {
                // Не акцептовавшие именно этот документ.
                if ($signedIds) {
                    $query->whereNotIn('id', $signedIds);
                }
            } else {
                // По умолчанию или accepted=true — показываем именно подписавших.
                $query->whereIn('id', $signedIds ?: [-1]);
            }
        } elseif ($acceptedFilter === 'true' || $acceptedFilter === 'false') {
            // Без выбранного документа фильтр работает по «все 5 подписаны»
            // (true) или «есть хотя бы 1 не подписанный» (false).
            $signedCounts = DB::table('partnerAcceptance')
                ->where('accepted', true)
                ->select('consultant', DB::raw('COUNT(DISTINCT "documentType") as cnt'))
                ->groupBy('consultant')
                ->pluck('cnt', 'consultant');
            $fullyAccepted = $signedCounts->filter(fn ($c) => $c >= $totalDocs)->keys()->all();
            if ($acceptedFilter === 'true') {
                $query->whereIn('id', $fullyAccepted ?: [-1]);
            } else {
                $query->whereNotIn('id', $fullyAccepted);
            }
        }

        $total = $query->count();
        $rows = $query->orderBy('personName')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get(['id', 'personName', 'acceptance']);

        $consultantIds = $rows->pluck('id')->all();

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

        // Filters «Акцептовано/документ» теперь применяются на SQL-уровне выше
        // (через whereIn/whereNotIn по consultantIds) — пост-агрегатная
        // фильтрация удалена, чтобы пагинация и total работали корректно.

        return response()->json([
            'data' => $data,
            'total' => $total,
            'documents' => $allDocs,
        ]);
    }

    /** Менеджер контрактов */
    public function contracts(Request $request): JsonResponse
    {
        $query = DB::table('contract as c')
            ->leftJoin('program as pr', 'c.program', '=', 'pr.id')
            ->whereNull('c.deletedAt');

        if ($request->filled('search')) {
            $s = $request->search;
            // Generic search = partner (consultant) name + contract number.
            // Client has a dedicated `client_name` filter — keep it out of the OR.
            $query->where(function ($q) use ($s) {
                $q->where('c.consultantName', 'ilike', "%{$s}%")
                  ->orWhere('c.number', 'ilike', "%{$s}%");
            });
        }
        // Точный фильтр по клиенту (id) — используется при переходе из списка
        // клиентов по клику на счётчик контрактов. Надёжнее, чем по ФИО
        // (нет коллизий тёзок). contract.client → client.id.
        if ($request->filled('client')) {
            $query->where('c.client', $request->client);
        }
        if ($request->filled('client_name')) {
            $query->where('c.clientName', 'ilike', '%' . $request->client_name . '%');
        }
        if ($request->filled('consultant_name')) {
            $query->where('c.consultantName', 'ilike', '%' . $request->consultant_name . '%');
        }
        if ($request->filled('status')) $query->whereIn('c.status', (array) $request->input('status'));
        if ($request->filled('number')) $query->where('c.number', 'ilike', '%' . $request->number . '%');
        if ($request->filled('comment')) $query->where('c.comment', 'ilike', '%' . $request->comment . '%');
        // Продукт: историчесские контракты (Directual) хранят productName,
        // а не FK. Резолвим имя и матчим по productName — та же схема что
        // для программы ниже. Отрицательный id → catalog-only продукт.
        if ($request->filled('product')) {
            $productId = (int) $request->product;
            $productName = $productId < 0
                ? DB::table('products_catalog')->where('id', -$productId)->value('name')
                : DB::table('product')->where('id', $productId)->value('name');
            if ($productName) {
                $query->where('c.productName', $productName);
            } else {
                $query->where('c.product', $productId);
            }
        }
        // Программа: дропдаун дедуплицирован (один id-представитель на
        // имя), поэтому фильтр матчит по contract.programName, чтобы
        // выбор «Жизнь+» поднимал ВСЕ варианты этой программы. Если
        // имя не разрезолвилось (id невалидный) — fallback на FK.
        // Отрицательный id → catalog-only программа (нет legacy-строки).
        if ($request->filled('program')) {
            $programId = (int) $request->program;
            $programName = $programId < 0
                ? DB::table('programs_catalog')->where('id', -$programId)->value('name')
                : DB::table('program')->where('id', $programId)->value('name');
            if ($programName) {
                $query->where('c.programName', $programName);
            } else {
                $query->where('c.program', $programId);
            }
        }
        if ($request->filled('setup')) $query->where('c.setup', $request->setup);
        if ($request->filled('supplier')) {
            $sup = '%' . $request->supplier . '%';
            // Для Insmart-договоров program.providerName хранит страховщика-
            // партнёра (Зетта/Пари/...), а UI отдаёт «Insmart». Чтобы фильтр
            // «Поставщик = Insmart» работал, дополнительно матчим по
            // contract.productName на ins+mart (см. SupplierResolver).
            $query->where(function ($w) use ($sup, $request) {
                $w->where('pr.providerName', 'ilike', $sup)
                  ->orWhere('pr.vendorName', 'ilike', $sup);
                if (preg_match('/ins+mart/i', (string) $request->supplier)) {
                    $w->orWhere('c.productName', 'ilike', '%insmart%')
                      ->orWhere('c.productName', 'ilike', '%inssmart%');
                }
            });
        }
        if ($request->filled('created_from')) $query->where('c.createDate', '>=', $request->created_from);
        if ($request->filled('created_to')) $query->where('c.createDate', '<=', $request->created_to . ' 23:59:59');
        if ($request->filled('opened_from')) $query->where('c.openDate', '>=', $request->opened_from);
        if ($request->filled('opened_to')) $query->where('c.openDate', '<=', $request->opened_to . ' 23:59:59');
        if ($request->filled('closed_from')) $query->where('c.closeDate', '>=', $request->closed_from);
        if ($request->filled('closed_to')) $query->where('c.closeDate', '<=', $request->closed_to . ' 23:59:59');
        // Прогноз активации — date-only колонка (без времени).
        if ($request->filled('forecast_from')) $query->where('c.activation_forecast', '>=', $request->forecast_from);
        if ($request->filled('forecast_to')) $query->where('c.activation_forecast', '<=', $request->forecast_to);

        $total = $query->count();

        // Итоговая сумма по контрактам (по текущим фильтрам, до пагинации) —
        // Алла сверяет ею корректность заливки из «Паруса». Просто сумма по
        // колонке ammount, без разбивки/конвертации по курсу. clone — чтобы
        // агрегат не задел основной запрос.
        $amountSum = (float) (clone $query)->sum('c.ammount');

        $this->applySorting($query, $request, [
            'number' => 'c.number',
            'clientName' => 'c."clientName"',
            'consultantName' => 'c."consultantName"',
            'productName' => 'c."productName"',
            'programName' => 'c."programName"',
            'ammount' => 'c.ammount',
            'amount' => 'c.ammount',
            'createDate' => 'c."createDate"',
            'openDate' => 'c."openDate"',
            'closeDate' => 'c."closeDate"',
            'status' => 'c.status',
            'activationForecast' => 'c.activation_forecast',
        ], 'c.id', 'desc');
        $rows = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->select([
                'c.id', 'c.number', 'c.clientName', 'c.consultant', 'c.consultantName',
                'c.productName', 'c.programName', 'c.status', 'c.ammount', 'c.currency',
                'c.openDate', 'c.createDate', 'c.createdAt', 'c.comment',
                'c.counterpartyContractId', 'c.activation_forecast',
                DB::raw('COALESCE(NULLIF(pr."vendorName",\'\'), pr."providerName") as "supplierName"'),
            ])
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
                'counterpartyContractId' => $c->counterpartyContractId,
                'clientName' => $c->clientName,
                'consultant' => $c->consultant ?? null,
                'consultantName' => $c->consultantName,
                'productName' => $c->productName,
                'programName' => $c->programName,
                'supplierName' => \App\Support\SupplierResolver::resolve($c->productName, $c->supplierName),
                // Реальный страховщик-партнёр для Insmart-продуктов
                // (для тултипа / детальной формы — UI может его игнорировать).
                'supplierSubName' => \App\Support\SupplierResolver::subProvider($c->productName, $c->supplierName),
                'comment' => $c->comment,
                'statusName' => $c->status ? ($contractStatuses[$c->status] ?? null) : null,
                'ammount' => $c->ammount,
                'currencySymbol' => $c->currency ? ($currencies[$c->currency] ?? null) : null,
                'openDate' => $c->openDate,
                // Y-m-d, иначе date-only уезжает на день назад под МСК.
                'activationForecast' => $c->activation_forecast ? substr((string) $c->activation_forecast, 0, 10) : null,
            ]);

        return response()->json(['data' => $contracts, 'total' => $total, 'amountSum' => $amountSum]);
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
     *
     * Данные собираются из ДВУХ источников: legacy-таблиц (`product`, `program`,
     * `program.providerName`) и нового каталога (`products_catalog`,
     * `programs_catalog`, `programs_catalog.vendor`). Объединяем по обоим,
     * чтобы фильтры показывали как историческите, так и новые позиции.
     */
    public function contractFormData(): JsonResponse
    {
        // ── Поставщики ─────────────────────────────────────────────────────
        // Источник 1a: legacy program.providerName (провайдер/страховщик).
        // Источник 1b: legacy program.vendorName (канал дистрибуции: RG.HT, Inssmart).
        // Для отображения в списке используем COALESCE(vendorName, providerName):
        // vendorName = «кто продаёт» (поставщик-канал), providerName = «кто оказывает услугу».
        $supRows = DB::table('program as pr')
            ->leftJoin('product as p', 'p.id', '=', 'pr.product')
            ->whereNull('pr.dateDeleted')
            ->where(function ($q) {
                $q->whereNotNull('pr.providerName')->orWhereNotNull('pr.vendorName');
            })
            ->distinct()
            ->get(['pr.providerName', 'pr.vendorName', 'p.name as productName']);
        $supSet = [];
        $hasInsmart = false;
        foreach ($supRows as $r) {
            // Канал дистрибуции (vendorName) имеет приоритет как «поставщик»
            $effectiveName = ($r->vendorName !== null && $r->vendorName !== '')
                ? $r->vendorName
                : $r->providerName;
            if (\App\Support\SupplierResolver::isInsmartProduct($r->productName)
                || preg_match('/ins+mart/i', (string) $effectiveName)) {
                $hasInsmart = true;
            } elseif ($effectiveName !== null && $effectiveName !== '') {
                $supSet[$effectiveName] = true;
            }
        }
        // Источник 2: programs_catalog.vendor (новый каталог).
        DB::table('programs_catalog')
            ->whereNotNull('vendor')
            ->where('vendor', '!=', '')
            ->distinct()
            ->pluck('vendor')
            ->each(fn ($v) => $supSet[$v] = true);

        $suppliers = array_keys($supSet);
        sort($suppliers, SORT_NATURAL | SORT_FLAG_CASE);
        if ($hasInsmart) array_unshift($suppliers, 'Insmart');

        // ── Программы ──────────────────────────────────────────────────────
        // Источник 1: legacy program (все исторические программы).
        $legacyPrograms = DB::table('program')
            ->whereNull('dateDeleted')
            ->orderBy('name')
            ->get(['id', 'name', 'product as productId', 'providerName']);

        // Источник 2: programs_catalog без legacy_program_id (19 программ,
        // которые есть только в новом каталоге и не попали в sync).
        // productId = products_catalog.legacy_product_id (чтобы фильтр
        // «по продукту» в ContractManager работал по тому же FK-пространству).
        $catalogOnlyPrograms = DB::table('programs_catalog as pg')
            ->join('products_catalog as pc', 'pc.id', '=', 'pg.product_id')
            ->whereNull('pg.legacy_program_id')
            ->where('pg.active', true)
            ->orderBy('pg.name')
            ->select(
                DB::raw('-(pg.id) as id'),       // отрицательный id → фронт видит его как «нет в legacy»
                'pg.name',
                'pc.legacy_product_id as productId',
                'pg.vendor as providerName'
            )
            ->get();

        $programs = $legacyPrograms->concat($catalogOnlyPrograms)->sortBy('name')->values();

        // ── Продукты ───────────────────────────────────────────────────────
        // Источник 1: все active legacy products. Для продуктов, у которых
        // есть запись в products_catalog, подставляем имя из каталога
        // (каталог хранит «правильное» отображаемое имя, legacy может быть
        // устаревшим). catalogId пробрасывается на фронт для загрузки
        // программ при создании контракта.
        $catalogMap = DB::table('products_catalog')
            ->whereNotNull('legacy_product_id')
            ->where('active', true)
            ->get(['id as catalog_id', 'legacy_product_id', 'name as catalog_name'])
            ->keyBy('legacy_product_id');

        // Дополнительно включаем legacy-продукты, которые сами помечены
        // active=false, но на них ссылается АКТИВНАЯ запись каталога
        // (рассинхрон active между legacy и catalog). Иначе такой продукт
        // выпадает из списка целиком: из источника 1 — по active=false,
        // из источника 2 — потому что legacy_product_id у него заполнен.
        // id остаётся реальным legacy product.id → FK contract.product валиден.
        $catalogLegacyIds = $catalogMap->keys()->all();

        $legacyProducts = DB::table('product')
            ->where(function ($q) use ($catalogLegacyIds) {
                $q->where('active', true);
                if (! empty($catalogLegacyIds)) {
                    $q->orWhereIn('id', $catalogLegacyIds);
                }
            })
            ->orderBy('name')
            ->select('id', 'name',
                DB::raw('COALESCE(has_property, false) AS "hasProperty"'),
                DB::raw('COALESCE(has_term, false) AS "hasTerm"'),
                DB::raw('COALESCE(has_year_kv, false) AS "hasYearKv"'),
            )
            ->get()
            ->map(function ($p) use ($catalogMap) {
                $cat = $catalogMap->get($p->id);
                return [
                    'id'          => $p->id,
                    'name'        => $cat ? $cat->catalog_name : $p->name,
                    'catalogId'   => $cat ? (int) $cat->catalog_id : null,
                    'hasProperty' => $p->hasProperty,
                    'hasTerm'     => $p->hasTerm,
                    'hasYearKv'   => $p->hasYearKv,
                ];
            });

        // Источник 2: catalog-only продукты (без legacy_product_id — 1 шт).
        $catalogOnlyProducts = DB::table('products_catalog')
            ->whereNull('legacy_product_id')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id as catalog_id', 'name'])
            ->map(fn ($p) => [
                'id'          => -(int)$p->catalog_id,   // отрицательный → нет в legacy
                'name'        => $p->name,
                'catalogId'   => (int) $p->catalog_id,
                'hasProperty' => false,
                'hasTerm'     => false,
                'hasYearKv'   => false,
            ]);

        $products = $legacyProducts->concat($catalogOnlyProducts)->sortBy('name')->values();

        return response()->json([
            'statuses' => DB::table('contractStatus')->orderBy('id')->get(['id', 'name']),
            'currencies' => DB::table('currency')->where('selectable', true)->orderBy('id')
                ->get()->map(fn ($c) => ['id' => $c->id, 'symbol' => $c->symbol, 'name' => $c->nameRu]),
            'countries' => DB::table('country')->orderBy('countryNameRu')
                ->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->countryNameRu]),
            'riskProfiles' => DB::table('riskProfile')->orderBy('id')
                ->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'setups' => DB::table('setup as s')
                ->leftJoin('consultant as c', 'c.id', '=', 's.consultant')
                ->whereIn('s.setup', self::ALLOWED_SETUP_CODES)
                ->orderBy('s.setup')
                ->select('s.id', 's.setup', 'c.personName')
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => trim($s->setup . ' ' . ($s->personName ?? '')),
                ]),
            'suppliers' => $suppliers,
            'programs'  => $programs,
            'products'  => $products,
        ]);
    }

    /**
     * Создать контракт (per spec ✅Менеджер контрактов §3 «Сохранить контракт»).
     * Партнёр подтягивается автоматически из выбранного клиента.
     */
    /**
     * GET /admin/contracts/check-number?number=Ш38&excludeId=N
     *
     * Лёгкий probe для формы создания/редактирования: фронт показывает
     * предупреждение до того, как юзер нажмёт «Сохранить». Регистронезависимо,
     * soft-deleted игнорируем. excludeId — id редактируемого контракта.
     */
    public function checkContractNumber(Request $request): JsonResponse
    {
        $number = trim((string) $request->input('number', ''));
        if ($number === '') {
            return response()->json(['exists' => false]);
        }
        $excludeId = (int) $request->input('excludeId', 0);

        // ILIKE надёжнее, чем LOWER() — особенно для кириллицы
        // («Ш38»/«ш38»). TRIM обоих сторон, потому что в legacy-данных
        // встречаются номера с trailing-пробелом ("Ш38 ").
        $query = DB::table('contract')
            ->whereRaw('TRIM("number") ILIKE ?', [$number])
            ->whereNull('deletedAt');
        if ($excludeId > 0) {
            $query->where('id', '!=', $excludeId);
        }

        $existing = $query->select('id', 'number', 'clientName', 'consultantName', 'createDate', 'productName')
            ->orderBy('id')
            ->first();

        return response()->json([
            'exists' => (bool) $existing,
            'existing' => $existing ? [
                'id' => $existing->id,
                'number' => $existing->number,
                'clientName' => $existing->clientName,
                'consultantName' => $existing->consultantName,
                'createDate' => $existing->createDate,
                'productName' => $existing->productName,
            ] : null,
        ]);
    }

    /**
     * Если номер контракта уже занят ЖИВЫМ контрактом — вернуть 422 с данными
     * существующего контракта (id/клиент/партнёр/дата/продукт), чтобы форма
     * показала его и предложила «Открыть». Единый источник дубль-проверки для
     * создания и редактирования. $excludeId — сам редактируемый контракт.
     * null — дубля нет. ILIKE+TRIM: кириллица и legacy trailing-пробел.
     */
    private function contractNumberConflict(?string $number, int $excludeId = 0): ?JsonResponse
    {
        $number = trim((string) $number);
        if ($number === '') {
            return null;
        }
        $query = DB::table('contract')
            ->whereRaw('TRIM("number") ILIKE ?', [$number])
            ->whereNull('deletedAt');
        if ($excludeId > 0) {
            $query->where('id', '!=', $excludeId);
        }
        $existing = $query
            ->select('id', 'number', 'clientName', 'consultantName', 'createDate', 'productName')
            ->orderBy('id')
            ->first();
        if (! $existing) {
            return null;
        }

        // Текст ошибки включает ФИО клиента и продукт существующего контракта —
        // оператор сразу видит, что это за контракт, не открывая его (просьба
        // владельца). Части подставляем только если они заполнены.
        $msg = "Контракт с номером «{$existing->number}» уже существует в системе";
        $details = [];
        if (! empty($existing->clientName)) {
            $details[] = "клиент: {$existing->clientName}";
        }
        if (! empty($existing->productName)) {
            $details[] = "продукт: {$existing->productName}";
        }
        if ($details) {
            $msg .= ' ('.implode(', ', $details).')';
        }

        return response()->json([
            'message' => $msg,
            'errors' => ['number' => [$msg]],
            'existing' => [
                'id' => $existing->id,
                'number' => $existing->number,
                'clientName' => $existing->clientName,
                'consultantName' => $existing->consultantName,
                'createDate' => $existing->createDate,
                'productName' => $existing->productName,
            ],
        ], 422);
    }

    public function storeContract(Request $request): JsonResponse
    {
        // Жёсткий гард дубля номера ДО остальной валидации: возвращаем сам
        // существующий контракт, а не только текст (просьба владельца).
        if ($resp = $this->contractNumberConflict($request->input('number'))) {
            return $resp;
        }

        $data = $request->validate([
            'number' => ['required', 'string', 'max:255'],
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
            'term' => 'nullable|integer|min:0|max:100',
            'comment' => 'nullable|string|max:2000',
            // Обязательно для всех статусов кроме «Активирован» (id=1)
            'activation_forecast' => 'nullable|date',
        ]);

        // Статусы, для которых прогноз активации не нужен и очищается:
        // 1 Активирован, 6 Закрыто нереализовано, 8 Закрыто, 9 Возврат, 10 Лапсирован.
        // Терминальные статусы (8 Закрыто / 9 Возврат) не требуют прогноз активации.
        $noForecastStatuses = [1, 6, 8, 9, 10];

        // activation_forecast обязателен только для статусов вне этого набора
        if (! in_array((int) ($data['status'] ?? 0), $noForecastStatuses, true) && empty($data['activation_forecast'])) {
            return response()->json([
                'message' => 'Проверьте заполнение полей',
                'errors' => ['activation_forecast' => ['Укажите прогноз активации контракта']],
            ], 422);
        }

        // Партнёр и его данные подтягиваются из клиента
        $client = DB::table('client')->where('id', $data['client'])->first();
        $consultantId = $client?->consultant;
        $consultantName = $consultantId
            ? DB::table('consultant')->where('id', $consultantId)->value('personName')
            : null;

        $product = DB::table('product')->where('id', $data['product'])->first();
        $program = DB::table('program')->where('id', $data['program'])->first();

        $id = DB::transaction(function () use ($data, $client, $consultantId, $consultantName, $product, $program, $request, $noForecastStatuses) {
            \App\Support\LegacyId::syncSequence('contract'); // защита от duplicate contract_pkey (лаг сиквенса)
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
                'term' => $data['term'] ?? null,
                'comment' => $data['comment'] ?? null,
                // Статусы без прогноза (Активирован/Закрыто нереализовано/Лапсирован) — очищаем
                'activation_forecast' => in_array((int) $data['status'], $noForecastStatuses, true) ? null : ($data['activation_forecast'] ?? null),
                // Дата активации фиксируется, если контракт сразу создаётся «Активированным».
                'activated_at' => (int) $data['status'] === 1 ? now()->toDateString() : null,
                'createdAt' => now(),
                'changedAt' => now(),
            ]);
        });

        // Прогноз начисления — системное поле (см. AccrualForecastService).
        app(\App\Services\AccrualForecastService::class)->recomputeForContract($id);

        return response()->json(['message' => 'Контракт создан', 'id' => $id], 201);
    }

    /**
     * Обновить контракт. Закрытые периоды защищены через freeze.
     */
    public function updateContract(Request $request, int $id): JsonResponse
    {
        $contract = \App\Models\Contract::find($id);
        if (! $contract) return response()->json(['message' => 'Контракт не найден'], 404);

        // Тот же гард, что при создании: смена номера на уже занятый другим
        // живым контрактом → 422 с данными этого контракта (исключаем сам $id).
        if ($request->has('number') && ($resp = $this->contractNumberConflict($request->input('number'), $id))) {
            return $resp;
        }

        $data = $request->validate([
            'number' => ['sometimes', 'string', 'max:255'],
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
            'term' => 'nullable|integer|min:0|max:100',
            'comment' => 'nullable|string|max:2000',
            'activation_forecast' => 'nullable|date',
        ]);

        // Статусы без прогноза: 1 Активирован, 6 Закрыто нереализовано, 8 Закрыто,
        // 9 Возврат, 10 Лапсирован. Терминальные (8/9) прогноз активации не требуют.
        $noForecastStatuses = [1, 6, 8, 9, 10];
        $newStatus = $data['status'] ?? (int) $contract->status;

        // Прогноз нужен только при переводе в статус вне набора «без прогноза»
        if (! in_array((int) $newStatus, $noForecastStatuses, true)
            && array_key_exists('activation_forecast', $data) && empty($data['activation_forecast'])) {
            return response()->json([
                'message' => 'Проверьте заполнение полей',
                'errors' => ['activation_forecast' => ['Укажите прогноз активации контракта']],
            ], 422);
        }
        // При переводе в Активирован/Закрыто нереализовано/Лапсирован — очищаем прогноз
        if (in_array((int) $newStatus, $noForecastStatuses, true)) {
            $data['activation_forecast'] = null;
        }
        // Фиксируем дату активации при первом переходе в «Активирован» (id=1) —
        // точка отсчёта прогноза начисления (см. AccrualForecastService).
        if ((int) $newStatus === 1 && empty($contract->activated_at)) {
            $data['activated_at'] = now()->toDateString();
        }

        DB::transaction(function () use ($data, $contract) {
            // Денормализация имён при изменении FK (для совместимости с прежними запросами)
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
            // Eloquent + LogsActivity — каждое изменение полей попадает в activity_log,
            // что и подтягивает «История изменений контракта» (per spec §4).
            $contract->fill($data)->save();
        });

        // Прогноз начисления — системное поле, пересчитываем после смены статуса.
        app(\App\Services\AccrualForecastService::class)->recomputeForContract($contract->id);

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
     * Поиск дублей контрактов по номеру. Группируем ЖИВЫЕ контракты по
     * нормализованному номеру (lower+btrim) и возвращаем группы size>1.
     *
     * mode=number         — группировка только по номеру (ловит и «разные
     *                       клиенты под одним номером», напр. Inssmart-хэши);
     * mode=number_client  — по номеру И клиенту (строгие дубли одной сделки).
     *
     * Для каждого контракта: клиент/партнёр/продукт/сумма/статус/дата + число
     * транзакций (txCount — важно перед объединением/удалением). Флаг
     * sameClient на группе: false → это могут быть РАЗНЫЕ сделки (не удалять
     * бездумно — кейс Inssmart D7856F60).
     */
    public function contractDuplicates(Request $request): JsonResponse
    {
        $mode = $request->input('mode') === 'number_client' ? 'number_client' : 'number';
        $groupExpr = $mode === 'number_client'
            ? "lower(btrim(number)) || '||' || lower(btrim(coalesce(\"clientName\",'')))"
            : 'lower(btrim(number))';

        $keys = DB::table('contract')
            ->whereNull('deletedAt')
            ->whereRaw("btrim(coalesce(number,'')) <> ''")
            ->selectRaw("$groupExpr AS gkey")
            ->groupByRaw($groupExpr)
            ->havingRaw('count(*) > 1')
            ->pluck('gkey');

        if ($keys->isEmpty()) {
            return response()->json(['groups' => [], 'mode' => $mode]);
        }

        $rows = DB::table('contract as c')
            ->leftJoin('contractStatus as s', 's.id', '=', 'c.status')
            ->whereNull('c.deletedAt')
            ->whereRaw("$groupExpr IN (" . implode(',', array_fill(0, $keys->count(), '?')) . ')', $keys->all())
            ->orderByRaw("$groupExpr, c.id")
            ->get([
                DB::raw("$groupExpr as gkey"),
                'c.id', 'c.number', 'c.client', 'c.clientName', 'c.consultant', 'c.consultantName',
                'c.product', 'c.productName', 'c.program', 'c.programName',
                'c.ammount', 'c.currency', 'c.status', 's.name as statusName',
                'c.createDate',
            ]);

        // Число транзакций по каждому контракту одним запросом (без N+1).
        $ids = $rows->pluck('id')->all();
        $txCounts = DB::table('transaction')
            ->whereIn('contract', $ids)
            ->whereNull('deletedAt')
            ->selectRaw('contract, count(*) as cnt')
            ->groupBy('contract')
            ->pluck('cnt', 'contract');

        $groups = [];
        foreach ($rows as $r) {
            $r->txCount = (int) ($txCounts[$r->id] ?? 0);
            $groups[$r->gkey][] = $r;
        }

        $result = [];
        foreach ($groups as $items) {
            $clients = collect($items)->pluck('client')->unique()->filter()->values();

            // «Полное совпадение» — это дубли ОДНОЙ сделки: у всех членов группы
            // совпадают ключевые поля (клиент+продукт+программа+сумма+валюта).
            // Тогда правило: оставляем контракт с транзакциями, остальные —
            // схлопываем. Если поля различаются — это РАЗНЫЕ данные/сделки под
            // одним номером, их НЕ трогаем (оставляем как есть).
            $identity = collect($items)
                ->map(fn ($c) => implode('|', [
                    $c->client, $c->product, $c->program,
                    // сумму нормализуем в число, чтобы 1000 и 1000.00 совпали
                    (string) (float) $c->ammount, $c->currency,
                ]))
                ->unique()
                ->values();
            $fullMatch = $identity->count() === 1;

            // Рекомендуемый канонический для полного совпадения — тот, где есть
            // транзакции (больше всего), при равенстве — младший id.
            $withTx = collect($items)->filter(fn ($c) => $c->txCount > 0)->count();
            $canonical = collect($items)
                ->sort(fn ($a, $b) => ($b->txCount <=> $a->txCount) ?: ($a->id <=> $b->id))
                ->first();

            $result[] = [
                'number' => $items[0]->number,
                'count' => count($items),
                'sameClient' => $clients->count() <= 1,
                'fullMatch' => $fullMatch,
                'withTxCount' => $withTx,
                'recommendedCanonical' => $canonical?->id,
                'totalTx' => collect($items)->sum('txCount'),
                'contracts' => $items,
            ];
        }
        // Сначала «полное совпадение» (можно схлопнуть) — они безопаснее и понятнее,
        // затем группы с транзакциями/разными клиентами (рискованнее — оставить).
        usort($result, fn ($a, $b) => ($b['fullMatch'] <=> $a['fullMatch'])
            ?: ($b['totalTx'] <=> $a['totalTx'])
            ?: ($a['sameClient'] <=> $b['sameClient']));

        return response()->json(['groups' => $result, 'mode' => $mode]);
    }

    /**
     * Массовый soft-delete выбранных дубль-контрактов. Обратимо (deletedAt).
     * Если у контракта есть живые транзакции — по умолчанию НЕ удаляем (вернём
     * в blocked), чтобы не оторвать деньги; для таких используйте «Объединить».
     * force=true — удалить всё равно (осознанное решение оператора).
     */
    public function deleteContractDuplicates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'force' => ['nullable', 'boolean'],
        ]);
        $force = (bool) ($data['force'] ?? false);

        $withTx = DB::table('transaction')
            ->whereIn('contract', $data['ids'])
            ->whereNull('deletedAt')
            ->selectRaw('contract, count(*) as cnt')
            ->groupBy('contract')
            ->pluck('cnt', 'contract');

        $toDelete = [];
        $blocked = [];
        foreach ($data['ids'] as $id) {
            if (! $force && ($withTx[$id] ?? 0) > 0) {
                $blocked[] = ['id' => $id, 'txCount' => (int) $withTx[$id]];
            } else {
                $toDelete[] = $id;
            }
        }

        if ($toDelete) {
            DB::table('contract')->whereIn('id', $toDelete)->whereNull('deletedAt')
                ->update(['deletedAt' => now()]);
        }

        return response()->json([
            'deleted' => $toDelete,
            'blocked' => $blocked,
            'message' => count($toDelete) . ' удалено'
                . ($blocked ? ', ' . count($blocked) . ' пропущено (есть транзакции — объедините)' : ''),
        ]);
    }

    /**
     * Объединить дубли в канонический контракт: транзакции всех mergeIds
     * репойнтятся на canonical, сами mergeIds soft-удаляются, запускается
     * RecomputeTransferChainJob(canonical) — пересчёт комиссий по открытым
     * периодам (прямой партнёр = canonical.consultant). Историческое/закрытое
     * calculateForTransaction пропустит сам.
     */
    public function mergeContractDuplicates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'canonical' => ['required', 'integer'],
            'mergeIds' => ['required', 'array', 'min:1'],
            'mergeIds.*' => ['integer'],
        ]);
        $canonicalId = (int) $data['canonical'];
        $mergeIds = array_values(array_unique(array_map('intval', $data['mergeIds'])));
        $mergeIds = array_values(array_filter($mergeIds, fn ($x) => $x !== $canonicalId));

        if (! $mergeIds) {
            return response()->json(['message' => 'Нечего объединять'], 422);
        }

        $canonical = DB::table('contract')->where('id', $canonicalId)->whereNull('deletedAt')->first();
        if (! $canonical) {
            return response()->json(['message' => 'Канонический контракт не найден'], 422);
        }
        $liveMerge = DB::table('contract')->whereIn('id', $mergeIds)->whereNull('deletedAt')->pluck('id')->all();
        if (! $liveMerge) {
            return response()->json(['message' => 'Объединяемые контракты не найдены'], 422);
        }

        $movedTx = DB::transaction(function () use ($canonicalId, $liveMerge) {
            $moved = DB::table('transaction')
                ->whereIn('contract', $liveMerge)
                ->whereNull('deletedAt')
                ->update(['contract' => $canonicalId, 'changedAt' => now()]);

            DB::table('contract')->whereIn('id', $liveMerge)->update(['deletedAt' => now()]);

            return $moved;
        });

        // Пересчёт цепочки канонического контракта (открытые периоды).
        \App\Jobs\RecomputeTransferChainJob::dispatch('contract', $canonicalId);

        return response()->json([
            'message' => 'Объединено: ' . count($liveMerge) . ' контракт(ов), перенесено транзакций: ' . $movedTx
                . '. Пересчёт комиссий за открытые периоды запущен.',
            'canonical' => $canonicalId,
            'merged' => $liveMerge,
            'movedTransactions' => $movedTx,
        ]);
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
        // tableConfig['subjectColumn'] и *Name-колонки в legacy-таблицах
        // changeConsultant*Log — все camelCase, обязательно в кавычках.
        $this->applySorting($query, $request, [
            'dateCreated' => '"dateCreated"',
            'subjectName' => '"' . $tableConfig['subjectColumn'] . '"',
            'oldName'     => $tab === 'partner' ? '"inviterOldName"' : '"consultantOldName"',
            'newName'     => $tab === 'partner' ? '"inviterNewName"' : '"consultantNewName"',
        ], '"dateCreated"', 'desc');

        $rows = $query
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
     * Поиск консультантов (ФК) для диалога «Внести перестановку».
     * Отдаёт {id, name} по ilike-совпадению personName.
     */
    public function transferConsultants(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('search', ''));
        $query = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->select('id', 'personName');
        if (mb_strlen($q) >= 2) {
            $query->where('personName', 'ilike', '%' . $q . '%');
        }
        $rows = $query->orderBy('personName')->limit(30)->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->personName]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Поиск субъекта перестановки для диалога «Перезакрепить …».
     * type=client → клиенты по ФИО; type=contract → контракты по номеру.
     * Отдаёт {id, name}.
     */
    public function transferSubjects(Request $request): JsonResponse
    {
        $type = (string) $request->input('type');
        $q = trim((string) $request->input('search', ''));

        if ($type === 'client') {
            $query = DB::table('client')->whereNull('dateDeleted')->select('id', 'personName');
            if (mb_strlen($q) >= 2) {
                $query->where('personName', 'ilike', '%' . $q . '%');
            }
            $rows = $query->orderBy('personName')->limit(30)->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->personName ?: ('Клиент #' . $c->id)]);

            return response()->json(['data' => $rows]);
        }

        if ($type === 'contract') {
            $query = DB::table('contract')->whereNull('deletedAt')->select('id', 'number', 'clientName');
            if (mb_strlen($q) >= 2) {
                $query->where('number', 'ilike', '%' . $q . '%');
            }
            $rows = $query->orderByDesc('id')->limit(30)->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => trim(($c->number ?: ('#' . $c->id)) . ($c->clientName ? ' — ' . $c->clientName : '')),
                ]);

            return response()->json(['data' => $rows]);
        }

        return response()->json(['data' => []]);
    }

    /**
     * Внести перестановку наставника вручную (кнопка в «Истории перестановок»).
     * Меняет consultant.inviter (+денорм inviterName) и пишет запись-событие в
     * changeConsultantInviterLog тем же форматом, что и авто-перестановки.
     */
    public function createTransfer(Request $request): JsonResponse
    {
        return match ($request->input('subject', 'partner')) {
            'partner'  => $this->createPartnerTransfer($request),
            'client'   => $this->createClientTransfer($request),
            'contract' => $this->createContractTransfer($request),
            default    => response()->json(['message' => 'Неизвестный тип перестановки'], 422),
        };
    }

    /**
     * Ручная смена наставника ФК (партнёр) + запись в changeConsultantInviterLog.
     */
    private function createPartnerTransfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'consultant' => ['required', 'integer'],
            'newInviter' => ['required', 'integer', 'different:consultant'],
        ]);

        $consultant = DB::table('consultant')->where('id', $data['consultant'])->whereNull('dateDeleted')->first();
        $newInviter = DB::table('consultant')->where('id', $data['newInviter'])->whereNull('dateDeleted')->first();
        if (! $consultant) {
            return response()->json(['message' => 'ФК не найден'], 422);
        }
        if (! $newInviter) {
            return response()->json(['message' => 'Новый наставник не найден'], 422);
        }
        if ((int) $newInviter->activity === \App\Enums\PartnerActivity::Terminated->value) {
            return response()->json(['message' => 'Нельзя назначить наставником терминированного ФК — у него не может быть активной ветки ниже'], 422);
        }
        if ((int) $consultant->inviter === (int) $newInviter->id) {
            return response()->json(['message' => 'У этого ФК уже такой наставник'], 422);
        }

        DB::transaction(function () use ($consultant, $newInviter, $request) {
            DB::table('consultant')->where('id', $consultant->id)->update([
                'inviter'     => $newInviter->id,
                'inviterName' => $newInviter->personName,
            ]);

            DB::table('changeConsultantInviterLog')->insert([
                'id'             => LegacyId::next('changeConsultantInviterLog'),
                'dateCreated'    => now(),
                'webUser'        => $request->user()?->id,
                'consultant'     => $consultant->id,
                'consultantName' => $consultant->personName,
                'inviterOld'     => $consultant->inviter,
                'inviterOldName' => $consultant->inviterName,
                'inviterNew'     => $newInviter->id,
                'inviterNewName' => $newInviter->personName,
                'triggeredBy'    => 'Внесено вручную',
            ]);
        });

        // Пересчёт комиссионной цепочки за открытые периоды (даунлайн партнёра):
        // смена наставника меняет аплайн у всех транзакций поддерева.
        \App\Jobs\RecomputeTransferChainJob::dispatch('partner', (int) $consultant->id);

        return response()->json(['message' => 'Перестановка внесена, записана в историю; пересчёт комиссий за открытые периоды запущен']);
    }

    /**
     * Ручное перезакрепление клиента на другого консультанта + запись
     * в changeConsultantClientLog (тем же форматом, что авто-перестановки).
     * NB: денорм client.consultantName обновляется; по коммиту диспатчится
     * RecomputeTransferChainJob (открытые периоды). Комиссия идёт по
     * contract.consultant, а не client.consultant — см. оговорку в job.
     */
    private function createClientTransfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_id'     => ['required', 'integer'],
            'new_consultant' => ['required', 'integer'],
        ]);

        $client  = DB::table('client')->where('id', $data['subject_id'])->whereNull('dateDeleted')->first();
        $newCons = DB::table('consultant')->where('id', $data['new_consultant'])->whereNull('dateDeleted')->first();
        if (! $client) {
            return response()->json(['message' => 'Клиент не найден'], 422);
        }
        if (! $newCons) {
            return response()->json(['message' => 'Новый консультант не найден'], 422);
        }
        if ((int) $newCons->activity === \App\Enums\PartnerActivity::Terminated->value) {
            return response()->json(['message' => 'Нельзя закрепить клиента за терминированным ФК'], 422);
        }
        if ((int) $client->consultant === (int) $newCons->id) {
            return response()->json(['message' => 'Клиент уже закреплён за этим консультантом'], 422);
        }

        DB::transaction(function () use ($client, $newCons, $request) {
            DB::table('client')->where('id', $client->id)->update([
                'consultant'     => $newCons->id,
                'consultantName' => $newCons->personName,
            ]);

            DB::table('changeConsultantClientLog')->insert([
                'id'                => LegacyId::next('changeConsultantClientLog'),
                'dateCreated'       => now(),
                'webUser'           => $request->user()?->id,
                'client'            => $client->id,
                'clientName'        => $client->personName,
                'consultantOld'     => $client->consultant,
                'consultantOldName' => $client->consultantName,
                'consultantNew'     => $newCons->id,
                'consultantNewName' => $newCons->personName,
                'triggeredBy'       => 'Внесено вручную',
            ]);
        });

        // Пересчёт комиссий контрактов клиента за открытые периоды. NB: комиссия
        // идёт по contract.consultant, а не client.consultant, поэтому реальное
        // изменение цепочки будет только если контракты клиента переназначены.
        \App\Jobs\RecomputeTransferChainJob::dispatch('client', (int) $client->id);

        return response()->json(['message' => 'Клиент перезакреплён и записан в историю; пересчёт комиссий за открытые периоды запущен']);
    }

    /**
     * Ручное перезакрепление контракта на другого консультанта + запись
     * в changeConsultantContractLog. Обновляется владелец (contract.consultant
     * + денорм consultantName), по коммиту диспатчится RecomputeTransferChainJob
     * — пересчёт комиссий этого контракта за открытые периоды.
     */
    private function createContractTransfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_id'     => ['required', 'integer'],
            'new_consultant' => ['required', 'integer'],
        ]);

        $contract = DB::table('contract')->where('id', $data['subject_id'])->whereNull('deletedAt')->first();
        $newCons  = DB::table('consultant')->where('id', $data['new_consultant'])->whereNull('dateDeleted')->first();
        if (! $contract) {
            return response()->json(['message' => 'Контракт не найден'], 422);
        }
        if (! $newCons) {
            return response()->json(['message' => 'Новый консультант не найден'], 422);
        }
        if ((int) $newCons->activity === \App\Enums\PartnerActivity::Terminated->value) {
            return response()->json(['message' => 'Нельзя закрепить контракт за терминированным ФК'], 422);
        }
        if ((int) $contract->consultant === (int) $newCons->id) {
            return response()->json(['message' => 'Контракт уже закреплён за этим консультантом'], 422);
        }

        DB::transaction(function () use ($contract, $newCons, $request) {
            DB::table('contract')->where('id', $contract->id)->update([
                'consultant'     => $newCons->id,
                'consultantName' => $newCons->personName,
            ]);

            DB::table('changeConsultantContractLog')->insert([
                'id'                => LegacyId::next('changeConsultantContractLog'),
                'dateCreated'       => now(),
                'webUser'           => $request->user()?->id,
                'contract'          => $contract->id,
                'contractNumber'    => $contract->number,
                'consultantOld'     => $contract->consultant,
                'consultantOldName' => $contract->consultantName,
                'consultantNew'     => $newCons->id,
                'consultantNewName' => $newCons->personName,
                'triggeredBy'       => 'Внесено вручную',
            ]);
        });

        // Пересчёт комиссий этого контракта за открытые периоды: сменился
        // прямой партнёр (chainOrder=1) → перестраивается вся цепочка.
        \App\Jobs\RecomputeTransferChainJob::dispatch('contract', (int) $contract->id);

        return response()->json(['message' => 'Контракт перезакреплён и записан в историю; пересчёт комиссий за открытые периоды запущен']);
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
                // Синхронизируем банковскую строку + платёжный гейт.
                $this->syncRequisiteVerification($r, $data['action'] === 'verify');

                if ($data['action'] === 'reject' && ! empty($data['comment'])) {
                    DB::transaction(function () use ($r, $data) {
                        DB::table('platformCommunication')->insert([
                            'id' => LegacyId::next('platformCommunication'),
                            'consultant' => $r->consultant,
                            'category' => 1,
                            'message' => $data['comment'],
                            'date' => now(),
                            'direction' => 'ds2p',
                            'read' => false,
                        ]);
                    });
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
