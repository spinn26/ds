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
use App\Services\PartnerListingService;
use App\Services\PartnerStatusesListingService;
use App\Services\RequisitesListingService;
use App\Services\ContractHistoryService;
use App\Services\ContractFormDataService;
use App\Services\ContractsListingService;
use App\Services\PartnerChangeLogService;
use App\Services\PartnerUpdateService;
use App\Services\PartnerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminDataController extends Controller
{
    use PaginatesRequests;
    use AppliesSorting;


    public function __construct(
        private readonly PartnerStatusService $statusService,
        private readonly PartnerListingService $partnerListing,
        private readonly PartnerStatusesListingService $partnerStatuses,
        private readonly RequisitesListingService $requisitesListing,
        private readonly ContractsListingService $contractsListing,
        private readonly ContractHistoryService $contractHistory,
        private readonly PartnerChangeLogService $partnerChangeLog,
        private readonly ContractFormDataService $contractFormData,
        private readonly PartnerUpdateService $partnerUpdate,
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
        // Фильтры и сборка строк живут в PartnerListingService: метод занимал
        // 146 строк и мешал в одну кучу разбор запроса, семь фильтров, три
        // пакетные подгрузки и маппинг из двадцати полей. Контроллеру
        // оставлено своё — запрос, сортировка, пагинация, ответ.
        //
        // `filled` остаётся здесь: «пустое значение = фильтр не применён» —
        // это правило HTTP-слоя, сервис получает уже только заполненное.
        $filters = [];
        foreach (PartnerListingService::FILTERS as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->input($key);
            }
        }

        $query = $this->partnerListing->query($filters);

        // total считаем ДО пагинации и по отфильтрованному запросу.
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

        return response()->json([
            'data' => $this->partnerListing->present($rows),
            'total' => $total,
        ]);
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
        // Без логина части ФИО брать неоткуда, кроме денорм-имени.
        $nameParts = preg_split('/\s+/u', trim((string) $consultant->personName)) ?: [];

        // Сводка для карточки партнёра в списке: квалификация, групповой объём
        // и остаток к выплате. В списке (/admin/partners) этих полей нет и быть
        // не должно — их пришлось бы считать на 1968 строк ради трёх чисел,
        // которые видны только в открытой карточке.
        $balance = DB::table('consultantBalance')
            ->where('consultant', $consultant->id)
            ->where('dateMonth', 'like', '____-__')
            ->orderByDesc('dateMonth')
            ->first(['dateMonth', 'remaining']);

        // levelNew заполнен не везде (у части строк он NULL), поэтому
        // спускаемся к номинальному и расчётному уровню.
        $qual = DB::table('qualificationLog')
            ->where('consultant', $consultant->id)
            ->whereNull('dateDeleted')
            ->orderByDesc('date')
            ->first(['levelNew', 'nominalLevel', 'calculationLevel', 'groupVolume', 'date']);

        return response()->json([
            'snapshot' => [
                'month' => $balance?->dateMonth,
                'level' => $qual->levelNew ?? $qual->nominalLevel ?? $qual->calculationLevel ?? null,
                'groupVolume' => $qual?->groupVolume !== null ? (float) $qual->groupVolume : null,
                'remaining' => $balance?->remaining !== null ? (float) $balance->remaining : null,
                'personalVolume' => round((float) ($consultant->personalVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($consultant->groupVolumeCumulative ?? 0), 2),
                'contracts' => DB::table('contract')
                    ->where('consultant', $consultant->id)
                    ->whereNull('deletedAt')
                    ->count(),
                'clients' => DB::table('client')
                    ->where('partner_consultant_id', $consultant->id)
                    ->whereNull('dateDeleted')
                    ->count(),
                // dateLastActivity в базе пустой у всех — живой признак только
                // last_seen_at, его и показываем как «последний вход».
                'lastSeenAt' => $webUser->last_seen_at ?? null,
            ],
            'consultant' => [
                'id' => $consultant->id,
                'personName' => $consultant->personName,
                'participantCode' => $consultant->participantCode,
                'inviter' => $consultant->inviter,
                'inviterName' => $consultant->inviterName,
                'activityId' => $consultant->activity?->value,
                'activityName' => $consultant->activityLabel(),
                'active' => $consultant->active,
                'terminationCount' => (int) ($consultant->terminationCount ?? 0),
                // Самовосстановление: сколько попыток потрачено и не закрыта ли
                // дверь администратором (кнопки в карточке партнёра).
                'reinstatementCount' => (int) ($consultant->reinstatement_count ?? 0),
                'reinstateLimit' => \App\Enums\PartnerActivity::selfReinstateLimit(),
                'reinstateBlocked' => (bool) ($consultant->reinstate_blocked ?? false),
                // Форма предупреждает о сбросе верификации до сохранения ФИО:
                // 3 = реквизиты подтверждены, гейт продуктов/выплат открыт.
                'requisitesVerified' => ((int) ($consultant->statusRequisites ?? 0)) === 3,
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
            ] : [
                // Партнёр без логина (893 импортированных ФК): WebUser'а нет,
                // контакты живут в собственных колонках consultant. Отдаём их
                // в том же ключе, иначе форма показывает пустые поля и
                // сохранение молча ничего не меняет.
                'id' => null,
                'firstName' => $nameParts[1] ?? null,
                'lastName' => $nameParts[0] ?? null,
                'patronymic' => $nameParts[2] ?? null,
                'email' => $consultant->email,
                'phone' => $consultant->phone,
                'nicTG' => null,
                'gender' => null,
                'birthDate' => $consultant->birthDate
                    ? substr((string) $consultant->birthDate, 0, 10)
                    : null,
                'role' => null,
                'isBlocked' => false,
            ],
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
            // Живые логины, регистронезависимо — см. App\Rules\UniqueLiveEmail.
            // Прежний `unique:WebUser,email` спотыкался о soft-deleted сирот и
            // пропускал дубли, отличающиеся только регистром.
            'email' => ['nullable', 'email', 'max:255', new \App\Rules\UniqueLiveEmail],
            // Формат телефона проверяем и здесь: карточку заводят руками, а
            // фронтовая проверка (PhoneInput) в API-запрос не попадает.
            'phone' => ['nullable', 'string', 'max:64', new \App\Rules\ValidPhone],
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
     * Редактирование партнёра: обновляем Consultant и связанный WebUser.
     * Все поля опциональны — обновляются только присланные.
     *
     * Смена ФИО снимает верификацию реквизитов (спека «Верификация реквизитов
     * Партнёра», Контур 3) — сообщаем об этом отдельно, иначе сотрудник узнает
     * о закрытом платёжном гейте только от партнёра.
     */
    public function updatePartner(Request $request, int $id): JsonResponse
    {
        // Валидация и сохранение — в PartnerUpdateService (215 строк).
        $result = $this->partnerUpdate->update($request, $id);

        return response()->json([
            'message' => $result['requisitesReset']
                ? 'Обновлён. Смена ФИО сняла верификацию реквизитов — партнёру открыт повторный ввод и отправка.'
                : 'Обновлён',
            'id' => $result['id'],
            'requisitesReset' => $result['requisitesReset'],
        ]);
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
            // Как и в карточке: пачкой терминировать/исключать без причины
            // нельзя — иначе массовое действие остаётся дырой в обход гарда.
            'reason' => [
                \Illuminate\Validation\Rule::requiredIf(fn () => in_array(
                    $request->input('action'), ['terminate', 'exclude'], true
                )),
                'nullable', 'string', 'min:3', 'max:500',
            ],
            'inviter' => ['nullable', 'integer', 'exists:consultant,id'],
        ]);

        $ok = 0;
        $fail = 0;
        $errors = [];
        $transferredIds = []; // для пересчёта цепочек после смены наставника

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
                        // Смена наставника и запись в Историю перестановок —
                        // одно неделимое действие. Раньше save() и insert шли
                        // по отдельности: падение вставки лога (например,
                        // коллизия id из LegacyId::next) оставляло структуру
                        // ПЕРЕСТРОЕННОЙ, но без записи в истории и без
                        // попадания в $transferredIds — значит и без пересчёта
                        // комиссий по новой цепочке. Ровно этот разрыв уже
                        // ловили на форме смены наставника.
                        DB::transaction(function () use ($c, $data, $request, &$transferredIds) {
                            $prevInvId = $c->inviter;
                            $prevInvName = $c->inviterName;
                            $c->inviter = $data['inviter'];
                            $c->inviterName = DB::table('consultant')
                                ->where('id', $data['inviter'])->value('personName');
                            $c->save();

                            if ((int) $prevInvId === (int) $data['inviter']) {
                                return;
                            }

                            DB::table('changeConsultantInviterLog')->insert([
                                'id'             => LegacyId::next('changeConsultantInviterLog'),
                                'dateCreated'    => now(),
                                'webUser'        => $request->user()?->id,
                                'consultant'     => $c->id,
                                'consultantName' => $c->personName,
                                'inviterOld'     => $prevInvId,
                                'inviterOldName' => $prevInvName,
                                'inviterNew'     => (int) $data['inviter'],
                                'inviterNewName' => $c->inviterName,
                                'triggeredBy'    => 'Массовое действие',
                            ]);
                            $transferredIds[] = $c->id;
                        });
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

        foreach ($transferredIds as $tid) {
            \App\Jobs\RecomputeTransferChainJob::dispatch('partner', (int) $tid);
        }

        return response()->json([
            'message' => "Выполнено: {$ok}, не удалось: {$fail}",
            'ok' => $ok,
            'fail' => $fail,
            'errors' => array_slice($errors, 0, 10),
        ]);
    }

    /**
     * Смена статуса активности партнёра.
     *
     * Гейт — `permission:statuses,full` на роуте (см. routes/api.php): раньше
     * стоял жёсткий hasAnyRole(['admin']), из-за чего руководитель по расчётам
     * со statuses=full в своей группе кнопку видел, а получал 403. admin
     * получает full на все секции через PermissionResolverService, так что
     * прежний доступ не сузился.
     */
    public function changePartnerStatus(Request $request, int $id): JsonResponse
    {
        $consultant = Consultant::findOrFail($id);

        $request->validate([
            'action' => 'required|in:activate,terminate,exclude,re-register,restore-termination,block-reinstate,unblock-reinstate',
            // Причина ОБЯЗАТЕЛЬНА для решений, которые двигают деньги и портфель
            // (терминация/исключение/ручная активация/отмена терминации): через
            // полгода на вопрос «почему его терминировали» отвечает только этот
            // комментарий в chageConsultanStatusLog. Гард дублирует UI —
            // прямой POST без причины тоже не пройдёт.
            'reason' => [
                \Illuminate\Validation\Rule::requiredIf(fn () => in_array(
                    $request->input('action'),
                    ['activate', 'terminate', 'exclude', 'restore-termination', 'block-reinstate'],
                    true
                )),
                'nullable', 'string', 'min:3', 'max:500',
            ],
        ], [
            'reason.required' => 'Укажите причину — она попадёт в историю статусов партнёра.',
            'reason.min' => 'Причина слишком короткая — опишите решение по существу.',
        ]);

        // Отмена ошибочной терминации отдаёт сводку по возвращённому портфелю.
        if ($request->action === 'restore-termination') {
            $res = $this->statusService->restoreFromTermination($consultant, $request->reason ?? '');
            $msg = sprintf('Статус: %s. Возвращено контрактов: %d, клиентов: %d.',
                $res['status'], $res['contracts'], $res['clients']);
            if ($res['skipped']) {
                $msg .= ' Требуют ручной проверки: '.count($res['skipped']).'.';
            }

            return response()->json(['message' => $msg, 'details' => $res]);
        }

        $result = match ($request->action) {
            // Активация из карточки — ручное решение админа: форсируем без
            // гейта по статусу/ЛП (аудит пишется). Строгий activate() остаётся
            // для авто-активации по порогу ЛП и bulk-операций.
            'activate' => $this->statusService->forceActivate($consultant, $request->reason ?? '') ? 'Активирован' : 'Партнёр уже активен',
            'terminate' => $this->statusService->terminate($consultant, $request->reason ?? '')->label(),
            'exclude' => tap('Исключён', fn () => $this->statusService->exclude($consultant, $request->reason ?? '')),
            're-register' => $this->statusService->reRegister($consultant) ? 'Перерегистрирован' : 'Не удалось перерегистрировать',
            // Запрет/разрешение САМОвосстановления. Статус партнёра не трогаем:
            // это про то, может ли он вернуться сам из окна при входе. Причина
            // уходит в activity_log вместе с изменением поля.
            'block-reinstate' => tap('Самовосстановление запрещено', function () use ($consultant, $request) {
                $consultant->reinstate_blocked = true;
                $consultant->save();
                activity('partner_status')->performedOn($consultant)
                    ->causedBy(auth()->user())
                    ->withProperties(['reason' => $request->reason])
                    ->log('Запрет самовосстановления');
            }),
            'unblock-reinstate' => tap('Самовосстановление разрешено', function () use ($consultant, $request) {
                $consultant->reinstate_blocked = false;
                $consultant->save();
                activity('partner_status')->performedOn($consultant)
                    ->causedBy(auth()->user())
                    ->withProperties(['reason' => $request->reason])
                    ->log('Снят запрет самовосстановления');
            }),
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
        // Сборка ленты — в PartnerChangeLogService (метод занимал 136 строк).
        return response()->json(['data' => $this->partnerChangeLog->forPartner($id)]);
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
        // Одна смена статуса пишется ДВУМЯ строками: `partner_status` (from/to
        // + причина, см. PartnerStatusService::logStatusChange) и модельная
        // `Consultant updated` (attributes.activity, БЕЗ причины). Раньше
        // читалась только вторая — комментарий сотрудника в попап не попадал.
        // Теперь берём обе формы, а модельный дубль той же смены отбрасываем.
        $statusEvents = [];
        foreach ($rows as $r) {
            if ($r->log_name === 'partner_status') {
                $p = json_decode($r->properties ?: '{}', true);
                $statusEvents[] = [
                    'ts' => strtotime((string) $r->created_at),
                    'from' => $p['from'] ?? null,
                    'to' => $p['to'] ?? null,
                ];
            }
        }

        foreach ($rows as $r) {
            $props = json_decode($r->properties ?: '{}', true);

            if ($r->log_name === 'partner_status') {
                $oldA = $props['from'] ?? null;
                $newA = $props['to'] ?? null;
            } else {
                $oldA = $props['old']['activity'] ?? null;
                $newA = $props['attributes']['activity'] ?? null;
                // Дубль события, у которого уже есть строка с причиной.
                $ts = strtotime((string) $r->created_at);
                foreach ($statusEvents as $ev) {
                    if ((int) $ev['from'] === (int) $oldA && (int) $ev['to'] === (int) $newA
                        && abs($ev['ts'] - $ts) <= 5) {
                        continue 2;
                    }
                }
            }

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
        // Сборка ленты — в ContractHistoryService (метод занимал 145 строк).
        return response()->json(['data' => $this->contractHistory->forContract($id)]);
    }

    /** Статусы партнёров — сводка + детальный список */
    public function partnerStatuses(Request $request): JsonResponse
    {
        // Сводка, фильтры и сборка строк — в PartnerStatusesListingService.
        // Метод занимал 138 строк: десять фильтров (восемь из них — границы
        // диапазонов по четырём разным колонкам дат), три пакетные подгрузки
        // и рекурсивный batch-SUM по ЛП от даты активации.
        $filters = [];
        foreach (PartnerStatusesListingService::FILTERS as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->input($key);
            }
        }

        $query = $this->partnerStatuses->query($filters);
        $total = $query->count();

        // camelCase колонки квотируем — см. partners() выше.
        $this->applySorting($query, $request, [
            'personName'            => '"personName"',
            'activityName'          => 'activity',
            'dateCreated'           => '"dateCreated"',
            'dateActivity'          => '"dateActivity"',
            // Колонка таблицы называется willTerminate и рисуется вычисляемым
            // дедлайном — сортируем тем же выражением. Прежний ключ
            // dateDeterministicPlan фронт не присылал вовсе (такой колонки в
            // таблице нет), и сортировка по «Будет терминирован» молча
            // сваливалась на сортировку по ФИО.
            'willTerminate'         => \App\Support\TerminationDeadline::sql(),
            'dateDeterministic'     => '"dateDeterministic"',
            'personalVolume'        => '"personalVolume"',
        ], '"personName"', 'asc');

        $rows = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        return response()->json([
            'summary' => $this->partnerStatuses->summary(),
            'data' => $this->partnerStatuses->present($rows),
            'total' => $total,
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

        // Сверяем по имени самой карточки: person из поиска убрана (13.08.2026).
        // Части ФИО там больше не ведутся, а её указатель мог вести на другого
        // человека — подсказка показывала постороннего как «возможный дубль».
        $query = DB::table('client as c')
            ->leftJoin('consultant as cn', 'cn.id', '=', 'c.consultant')
            ->whereNull('c.dateDeleted')
            ->whereRaw('lower(btrim("c"."personName")) LIKE ?', [$lastName.' '.$firstName.'%'])
            ->select([
                'c.id', 'c.personName', 'c.email', 'c.phone', 'c.city',
                'c.birthDate', 'c.consultant as consultantId',
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
        // Раньше здесь был цикл с ДВУМЯ запросами на уровень (строка
        // консультанта + название квалификации): десять уровней стоили два
        // десятка round-trip. Теперь вся цепочка с названиями приходит одним
        // рекурсивным запросом, стоимость не зависит от глубины.
        $chain = array_map(fn ($r) => [
            'id' => $r->id,
            'personName' => $r->personName,
            'level' => $r->level,
            'depth' => (int) $r->depth,
        ], app(\App\Services\ConsultantTreeService::class)->chainFrom($id, 20));

        return response()->json(['chain' => $chain]);
    }

    /**
     * POST /admin/clients — создать клиента per spec ✅Клиенты §3.
     *
     * Антидубль-подсказка есть на фронте, но она была только предупреждением —
     * сервер создавал карточку в любом случае, отсюда часть дублей. Теперь
     * совпадение по ФИО блокируется, а осознанное создание однофамильца
     * требует явного `force` (фронт спрашивает подтверждение).
     */
    public function storeClient(Request $request): JsonResponse
    {
        $data = $request->validate(self::clientValidationRules(), self::clientValidationMessages());

        $personName = trim("{$data['lastName']} {$data['firstName']}" . (! empty($data['patronymic']) ? ' ' . $data['patronymic'] : ''));

        if (! $request->boolean('force')) {
            $existing = $this->findLiveClientsByName($personName);
            if ($existing->isNotEmpty()) {
                return response()->json([
                    'message' => 'Клиент с таким ФИО уже есть. Откройте существующую карточку или подтвердите создание однофамильца.',
                    'code' => 'duplicate_client',
                    'existing' => $existing,
                ], 422);
            }
        }

        $clientId = DB::transaction(function () use ($data, $personName) {
            // person больше не заводим (13.08.2026): карточка владеет своими
            // данными, а лишняя запись-контакт только плодила расхождения —
            // именно из-за них клиент показывал чужие почту и телефон.
            \App\Support\LegacyId::syncSequence('client');
            return DB::table('client')->insertGetId([
                'personName' => $personName,
                'consultant' => $data['consultant'],
                'comment' => $data['comment'] ?? null,
                // Клиент владеет своими контактами (не зависит от person-FK).
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'birthDate' => $data['birthDate'] ?? null,
                'city' => $data['city'] ?? null,
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

        // В person больше не пишем (13.08.2026): данные перенесены в карточку,
        // и запись во вторую копию только плодила расхождения. До этого правка
        // клиента могла затереть контакт ДРУГОГО человека — у части карточек
        // client.person указывает на постороннего после переномерации person
        // при консолидации Directual.
        DB::transaction(function () use ($client, $data, $personName) {
            DB::table('client')->where('id', $client->id)->update([
                'personName' => $personName,
                'consultant' => $data['consultant'],
                'comment' => $data['comment'] ?? null,
                // Клиент владеет своими контактами — источник истины карточки.
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'birthDate' => $data['birthDate'] ?? null,
                'city' => $data['city'] ?? null,
                'dateChanged' => now(),
            ]);

            // ФИО клиента денормализовано в contract.clientName (историческая
            // схема). Без синхронизации исправление опечатки в карточке не
            // отражается в контрактах — там остаётся старое имя (жалоба Л.Л.).
            // Обновляем только те контракты, где имя СОВПАДАЛО со старым именем
            // карточки — это настоящее переименование. Контракты с иным именем
            // НЕ трогаем: у части клиентов contract.client указывает на чужую
            // карточку (осколки дедупа), и слепая замена затёрла бы верное имя
            // чужим. Такие случаи чинятся отдельно (перепривязкой client).
            if ($personName !== $client->personName) {
                DB::table('contract')
                    ->where('client', $client->id)
                    ->where('clientName', $client->personName)
                    ->update(['clientName' => $personName]);
            }
        });

        Audit::log('update', 'client', $id, [
            'consultant' => $data['consultant'],
        ]);

        return response()->json(['message' => 'Клиент обновлён']);
    }

    /**
     * Контракты с неверной привязкой client: ФИО в контракте (clientName) не
     * совпадает с именем привязанной ЖИВОЙ карточки (наследие дедуп-склеек,
     * когда contract.client уехал на чужого). Для каждого — кандидаты: живые
     * карточки с personName = clientName. Однозначные (ровно 1 кандидат)
     * перепривязываются в один клик; неоднозначные/без совпадения — вручную.
     */
    public function clientMismatches(Request $request): JsonResponse
    {
        // Контракт НЕ привязан к карточке ПРАВИЛЬНОГО человека. «Правильно» =
        // привязан к карточке (живой ИЛИ удалённой) с тем же ФИО — тогда это
        // верный человек, просто карточка в архиве (старые контракты 2020–2025);
        // это НЕ ошибка привязки, из выборки исключаем. Ловим реальные ошибки:
        // (wrong) чужая живая карточка; (deleted-diff) удалённая карточка чужого;
        // (broken) FK на несуществующую; (none) client = NULL.
        $rows = DB::table('contract as ct')
            ->leftJoin('client as cur', 'cur.id', '=', 'ct.client')
            ->whereNull('ct.deletedAt')
            ->whereNotNull('ct.clientName')
            ->whereRaw("btrim(ct.\"clientName\") <> ''")
            ->whereRaw('NOT (cur.id IS NOT NULL AND btrim(lower(ct."clientName")) = btrim(lower(cur."personName")))')
            ->orderByDesc('ct.id')
            ->get([
                'ct.id', 'ct.number', 'ct.clientName', 'ct.consultantName', 'ct.productName',
                'ct.client as currentClientId', 'cur.personName as currentClientName',
                DB::raw('CASE
                    WHEN ct.client IS NULL THEN \'none\'
                    WHEN cur.id IS NULL THEN \'broken\'
                    WHEN cur."dateDeleted" IS NOT NULL THEN \'deleted\'
                    ELSE \'wrong\' END AS current_status'),
            ]);

        // Кандидаты батчем: живые карточки с ФИО = clientName (case-insensitive).
        $names = $rows->pluck('clientName')->map(fn ($n) => mb_strtolower(trim((string) $n)))->unique()->values();
        $candByName = collect();
        if ($names->isNotEmpty()) {
            $ph = implode(',', array_fill(0, $names->count(), '?'));
            $candidates = DB::table('client')
                ->whereNull('dateDeleted')
                ->whereRaw("btrim(lower(\"personName\")) IN ($ph)", $names->all())
                ->get(['id', 'personName', 'email', 'phone']);
            $candByName = $candidates->groupBy(fn ($c) => mb_strtolower(trim((string) $c->personName)));
        }

        $data = $rows->map(function ($r) use ($candByName) {
            $key = mb_strtolower(trim((string) $r->clientName));
            $cands = ($candByName->get($key) ?? collect())->map(fn ($c) => [
                'id' => $c->id, 'personName' => $c->personName,
                'email' => $c->email, 'phone' => $c->phone,
            ])->values();

            return [
                'id' => $r->id,
                'number' => $r->number,
                'clientName' => $r->clientName,
                'consultantName' => $r->consultantName,
                'productName' => $r->productName,
                'currentClientId' => $r->currentClientId,
                'currentClientName' => $r->currentClientName,
                'currentStatus' => $r->current_status,
                'candidates' => $cands,
                'candidateCount' => $cands->count(),
            ];
        });

        return response()->json([
            'data' => $data->values(),
            'total' => $data->count(),
            'unique' => $data->where('candidateCount', 1)->count(),
            'ambiguous' => $data->filter(fn ($d) => $d['candidateCount'] > 1)->count(),
            'noMatch' => $data->where('candidateCount', 0)->count(),
        ]);
    }

    /**
     * Перепривязать контракт к выбранной живой карточке клиента + выровнять
     * clientName. Деньги не затрагивает (комиссии по консультанту, не клиенту).
     */
    public function relinkContractClient(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['client' => ['required', 'integer']]);

        $contract = DB::table('contract')->where('id', $id)->first();
        if (! $contract) {
            return response()->json(['message' => 'Контракт не найден'], 404);
        }
        $client = DB::table('client')->where('id', $data['client'])->whereNull('dateDeleted')->first();
        if (! $client) {
            return response()->json(['message' => 'Карточка клиента не найдена или удалена'], 422);
        }

        DB::table('contract')->where('id', $contract->id)->update([
            'client' => $client->id,
            'clientName' => $client->personName,
        ]);

        Audit::log('relink-client', 'contract', $id, [
            'from' => $contract->client, 'to' => $client->id,
        ]);

        return response()->json(['message' => 'Контракт перепривязан к клиенту']);
    }

    /**
     * Завести карточку клиента из ФИО контракта и привязать. Для контрактов,
     * где верного клиента как записи нет вовсе (инвест-сделки Axevil/РАНКС/…
     * заводились только именем на контракте, а client-FK уехал на чужого).
     * Переиспользуем существующую person с тем же ФИО (её контакты), иначе
     * создаём новую. Выравниваем сиквенсы (защита от duplicate _pkey).
     */
    public function createClientFromContract(Request $request, int $id): JsonResponse
    {
        $contract = DB::table('contract')->where('id', $id)->first();
        if (! $contract) {
            return response()->json(['message' => 'Контракт не найден'], 404);
        }
        $fio = trim((string) ($contract->clientName ?? ''));
        if ($fio === '') {
            return response()->json(['message' => 'У контракта не заполнено ФИО клиента'], 422);
        }
        $norm = mb_strtolower($fio);

        // Уже есть живая карточка с таким ФИО — не плодим дубль.
        $exists = DB::table('client')->whereNull('dateDeleted')
            ->whereRaw('btrim(lower("personName")) = ?', [$norm])->exists();
        if ($exists) {
            return response()->json(['message' => 'Живая карточка с таким ФИО уже есть — используйте перепривязку'], 422);
        }

        $parts = preg_split('/\s+/', $fio);
        $lastName = $parts[0] ?? $fio;
        $firstName = $parts[1] ?? '';
        $patronymic = count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : null;

        $clientId = DB::transaction(function () use ($contract, $fio) {
            // person не заводим и не переиспользуем (13.08.2026): карточка
            // владеет своими данными. Контакты у контракта взять неоткуда —
            // оператор заполнит их в карточке.
            \App\Support\LegacyId::syncSequence('client');
            $newClientId = DB::table('client')->insertGetId([
                'personName' => $fio,
                'consultant' => $contract->consultant,
                'dateCreated' => now(),
            ]);

            DB::table('contract')->where('id', $contract->id)->update([
                'client' => $newClientId,
                'clientName' => $fio,
            ]);

            return $newClientId;
        });

        Audit::log('create-client-from-contract', 'contract', $id, ['client' => $clientId]);

        return response()->json(['message' => 'Карточка клиента заведена и привязана', 'clientId' => $clientId]);
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

        // Batch count contracts per client
        $clientIds = $rows->pluck('id')->filter()->unique();
        $contractCounts = $clientIds->isNotEmpty()
            ? DB::table('contract')->whereIn('client', $clientIds)->whereNull('deletedAt')
                ->select('client', DB::raw('count(*) as cnt'))
                ->groupBy('client')
                ->pluck('cnt', 'client')
            : collect();

        // Признак «клиент является партнёром» — по явной связи
        // client.partner_consultant_id (заполняет clients:link-partners).
        // Прежний способ — совпадение client.person = consultant.person —
        // снят: id person разошлись при консолидации Directual, часть пар вела
        // на другого человека, а у большинства партнёров person пуст и признак
        // не определялся вовсе. Живость партнёра всё равно проверяем.
        $partnerIds = $rows->pluck('partner_consultant_id')->filter()->unique();
        $livePartners = $partnerIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $partnerIds)->whereNull('dateDeleted')
                ->pluck('id')->flip()
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

        $clients = $rows->map(function ($c) use ($contractCounts, $livePartners, $consultantInfo) {
                $cInfo = $c->consultant ? ($consultantInfo[$c->consultant] ?? null) : null;

                return [
                    'id' => $c->id,
                    'dsId' => $c->idDs,
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
                    'isPartner' => $c->partner_consultant_id
                        ? isset($livePartners[$c->partner_consultant_id])
                        : false,
                    'partnerConsultantId' => $c->partner_consultant_id,
                    'comment' => $c->comment,
                    // Клиент ВЛАДЕЕТ контактами — фолбэк на person убран
                    // (2026-08-12). Он подставлял чужие почту/телефон, когда
                    // client.person указывал на другого человека: после
                    // переномерации person при консолидации Directual таких
                    // карточек было 176, и оператор видел в карточке партнёра
                    // контакты постороннего клиента.
                    // Перед снятием контакты перенесены в сами карточки
                    // (135 из Directual + 127 из корректно привязанных person);
                    // на фолбэке оставались только 12 карточек, и все — с чужой
                    // привязкой, то есть показывали заведомо не те данные.
                    'email' => $c->email ?? null,
                    'phone' => $c->phone ?? null,
                    'birthDate' => $c->birthDate ?? null,
                    'city' => $c->city ?? null,
                ];
            });

        return response()->json(['data' => $clients, 'total' => $total]);
    }

    /** Реквизиты — список для верификации */
    public function requisites(Request $request): JsonResponse
    {
        // Фильтры, дедуп и сборка строк — в RequisitesListingService.
        // Метод занимал 195 строк: пять фильтров (три из них ходят в соседние
        // таблицы), дедуп через DISTINCT ON и четыре пакетные подгрузки.
        $filters = [];
        foreach (RequisitesListingService::FILTERS as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->input($key);
            }
        }

        // ⚠ Сначала фильтры, потом дедуп — порядок влияет на выдачу.
        $query = $this->requisitesListing->deduplicate(
            $this->requisitesListing->query($filters)
        );
        $total = $query->count();

        $this->applySorting($query, $request, [
            'individualEntrepreneur' => '"individualEntrepreneur"',
            'inn' => 'inn',
            'verified' => 'verified',
            'createdAt' => '"createdAt"',
            // Дата поступления на проверку = последнее изменение реквизита.
            'submittedAt' => '"dateChange"',
        ], 'id', 'desc');

        $rows = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        return response()->json([
            'data' => $this->requisitesListing->present($rows),
            'total' => $total,
        ]);
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
        // Фильтры и сборка строк — в ContractsListingService.
        // Метод занимал 156 строк при девятнадцати фильтрах.
        $filters = [];
        foreach (ContractsListingService::FILTERS as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->input($key);
            }
        }

        $query = $this->contractsListing->query($filters);

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
                DB::raw(\App\Support\SupplierResolver::sqlProviderExpr('pr', null) . ' as "supplierName"'),
            ])
            ->get();

        return response()->json([
            'data' => $this->contractsListing->present($rows),
            'total' => $total,
            'amountSum' => round($amountSum, 2),
        ]);
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
        // Сборка справочников — в ContractFormDataService (159 строк).
        return response()->json($this->contractFormData->build());
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
     * Обновить контракт.
     *
     * Контракты — открытый реестр: правка НЕ блокируется закрытыми периодами
     * (требование бизнеса «контракты всегда редактируемы»). Уже посчитанные
     * комиссии закрытых месяцев при этом не меняются — CommissionCalculator
     * сам отказывается пересчитывать frozen/historical периоды, а updateContract
     * пересчёт комиссий не запускает вовсе (только AccrualForecastService).
     */
    public function updateContract(Request $request, int $id): JsonResponse
    {
        $contract = \App\Models\Contract::find($id);
        if (! $contract) return response()->json(['message' => 'Контракт не найден'], 404);

        // Прямой партнёр до правки — смена клиента ниже может сменить владельца
        // (data['consultant'] = client.consultant), а это двигает комиссионную
        // цепочку. Запоминаем, чтобы пересчитать её за открытые периоды.
        $oldConsultant = (int) $contract->consultant;

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

        // Сменился прямой партнёр (через смену клиента) → перестраиваем
        // комиссионную цепочку за ОТКРЫТЫЕ периоды, как в модуле перестановок
        // (createContractTransfer). Закрытые/исторические месяцы
        // calculateForTransaction пропустит сам. Без этого правка ФК в
        // редакторе контракта оставляла цепочку на старом партнёре.
        if ((int) $contract->consultant !== $oldConsultant) {
            \App\Jobs\RecomputeTransferChainJob::dispatch('contract', (int) $contract->id);
        }

        return response()->json(['message' => 'Контракт обновлён']);
    }

    /**
     * Soft-delete контракта (per spec §3 «Удалить контракт» с предупреждением).
     */
    public function deleteContract(int $id): JsonResponse
    {
        $row = DB::table('contract')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Контракт не найден'], 404);

        // Контракты редактируемы/удаляемы всегда — закрытые периоды их не держат
        // (требование бизнеса). Комиссии закрытых месяцев остаются нетронутыми.
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
     * Все таблицы, ссылающиеся на client (FK), — при слиянии переносим ВСЕ.
     * Список получен из information_schema, менять только вместе со схемой.
     * Плюральные имена колонок (consultant.clients, exportLogClients.clients) —
     * легаси, это обычные одиночные FK.
     */
    private const CLIENT_FK_MAP = [
        'WebUser' => 'client',
        'changeConsultantClientLog' => 'client',
        'clientFamily' => 'client',
        'clientGoal' => 'client',
        'clientsCapital' => 'client',
        'clientsIndicators' => 'client',
        'consultant' => 'clients',
        'contract' => 'client',
        'dataPermutationTrigger' => 'client',
        'exportLogClients' => 'clients',
        'getInsmartOrderWebHookData' => 'client',
        'indicatorsHistory' => 'client',
        'meeting' => 'client',
        'notification' => 'client',
    ];

    /**
     * Живые клиенты с таким же ФИО. Нормализуем регистр и схлопываем двойные
     * пробелы с обеих сторон — иначе «Масс Анна  Вячеславовна» и
     * «Масс Анна Вячеславовна» считаются разными людьми (реальный случай).
     */
    private function findLiveClientsByName(string $personName)
    {
        $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $personName)));
        if ($norm === '') {
            return collect();
        }

        return DB::table('client as c')
            ->leftJoin('consultant as cn', 'cn.id', '=', 'c.consultant')
            ->whereNull('c.dateDeleted')
            ->whereRaw("btrim(lower(regexp_replace(c.\"personName\", '\\s+', ' ', 'g'))) = ?", [$norm])
            ->select(['c.id', 'c.personName', 'c.email', 'c.phone', 'cn.personName as consultantName'])
            ->orderBy('c.id')
            ->limit(10)
            ->get();
    }

    /** Ключ группы дублей — отсортированные id через дефис. */
    private function dupGroupKey(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return implode('-', $ids);
    }

    /**
     * Дубли клиентов: группы живых клиентов с совпадающим ФИО / email / телефоном.
     *
     * Уверенность считаем по данным, а не на глаз:
     *  - общий person + совпадающие контакты → «слить»;
     *  - вторая карточка заведена самим партнёром (consultant = клиент) → «на себя»;
     *  - разные person без общих контактов → «проверить».
     * Группы, помеченные оператором как «не дубли», не показываются.
     */
    public function clientDuplicates(Request $request): JsonResponse
    {
        $by = $request->input('by', 'name'); // name | email | phone
        // Контакты — из самой карточки: фолбэк на person снят (13.08.2026),
        // контакты перенесены в client, а чужая привязка подставляла в группу
        // номер постороннего и слепляла разных людей в «дубль».
        $emailExpr = "lower(btrim(coalesce(nullif(c.email, ''), '')))";
        // Телефон — ПОСЛЕДНИЕ 10 ЦИФР (канон App\Support\Phone): иначе «+7 904…»
        // и «8 904…» это разные группы, и половина дублей по номеру не видна.
        $phoneExpr = "right(regexp_replace(coalesce(nullif(c.phone, ''), ''), '[^0-9]', '', 'g'), 10)";
        $expr = match ($by) {
            'email' => $emailExpr,
            'phone' => $phoneExpr,
            default => "lower(btrim(c.\"personName\"))",
        };
        // Пустые значения не группируем: иначе все безконтактные слипнутся в одну «группу».
        $minLen = $by === 'phone' ? 10 : 3;

        // Контакты берём из самой карточки: фолбэк на person снят (13.08.2026),
        // он подставлял в группировку по телефону чужой номер и слеплял в
        // «дубли» разных людей.
        $rows = DB::table('client as c')
            ->leftJoin('consultant as cn', 'cn.id', '=', 'c.consultant')
            ->whereNull('c.dateDeleted')
            ->whereRaw("length($expr) >= ?", [$minLen])
            ->whereRaw("$expr IN (
                SELECT $expr FROM client c
                WHERE c.\"dateDeleted\" IS NULL AND length($expr) >= ?
                GROUP BY 1 HAVING count(*) > 1
            )", [$minLen])
            ->selectRaw("$expr as grp")
            ->addSelect([
                'c.id', 'c.personName',
                'c.dateCreated', 'c.consultant',
                'cn.personName as consultantName',
            ])
            ->selectRaw("nullif(c.email, '') as email")
            ->selectRaw("nullif(c.phone, '') as phone")
            ->orderByRaw("$expr, c.id")
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['groups' => [], 'total' => 0]);
        }

        // Контракты одним батчем (иначе N+1 на сотне клиентов).
        $counts = DB::table('contract')
            ->whereIn('client', $rows->pluck('id'))
            ->whereNull('deletedAt')
            ->selectRaw('client, count(*) as n')
            ->groupBy('client')
            ->pluck('n', 'client');

        $ignored = DB::table('client_duplicate_ignores')->pluck('group_key')->flip();

        $groups = [];
        foreach ($rows->groupBy('grp') as $items) {
            $ids = $items->pluck('id')->map(fn ($i) => (int) $i)->all();
            $key = $this->dupGroupKey($ids);
            if ($ignored->has($key)) continue;

            $clients = $items->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->personName,
                'consultantName' => $r->consultantName,
                'contracts' => (int) ($counts[$r->id] ?? 0),
                'email' => $r->email,
                'phone' => $r->phone,
                'createdAt' => $r->dateCreated,
                // Партнёр завёл карточку на самого себя.
                'self' => $r->consultantName
                    && mb_strtolower(trim($r->consultantName)) === mb_strtolower(trim((string) $r->personName)),
            ])->values()->all();

            // Признак «общая person» убран (13.08.2026): он считался сильным
            // доказательством одного человека, а на деле одна запись держала
            // карточки РАЗНЫХ людей — после переномерации при консолидации
            // Directual. Остаётся совпадение контактов, оно проверяемо.
            $emails = array_values(array_filter(array_map(fn ($c) => mb_strtolower(trim((string) $c['email'])), $clients)));
            $phones = array_values(array_filter(array_map(fn ($c) => preg_replace('/[^0-9]/', '', (string) $c['phone']), $clients)));
            $sharedContact = (count($emails) > 1 && count(array_unique($emails)) === 1)
                || (count($phones) > 1 && count(array_unique($phones)) === 1);

            $confidence = 'check';
            if ($sharedContact) $confidence = 'merge';
            elseif (array_filter(array_column($clients, 'self'))) $confidence = 'self';

            // Кандидат «оставить» — больше всего контрактов, при равенстве самый старый.
            usort($clients, fn ($a, $b) => [$b['contracts'], -$a['id']] <=> [$a['contracts'], -$b['id']]);
            $suggested = $clients[0]['id'];

            $groups[] = [
                'key' => $key,
                'name' => $items->first()->personName,
                'confidence' => $confidence,
                'sharedContact' => $sharedContact,
                'suggestedKeep' => $suggested,
                'clients' => $clients,
            ];
        }

        // Сначала самые «дорогие» группы: где больше контрактов на кону.
        usort($groups, fn ($a, $b) => array_sum(array_column($b['clients'], 'contracts'))
            <=> array_sum(array_column($a['clients'], 'contracts')));

        return response()->json(['groups' => $groups, 'total' => count($groups)]);
    }

    /**
     * Слияние дублей клиентов: переносим ВСЕ FK-ссылки на канонического клиента
     * и мягко удаляем остальные карточки. Контракты переезжают вместе со всем
     * остальным, поэтому операция необратима в один клик — фронт спрашивает
     * подтверждение и показывает, сколько контрактов переедет.
     */
    public function mergeClientDuplicates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'canonical' => ['required', 'integer'],
            'mergeIds' => ['required', 'array', 'min:1'],
            'mergeIds.*' => ['integer'],
        ]);

        $canonicalId = (int) $data['canonical'];
        $mergeIds = array_values(array_filter(
            array_unique(array_map('intval', $data['mergeIds'])),
            fn ($x) => $x !== $canonicalId
        ));
        if (! $mergeIds) {
            return response()->json(['message' => 'Нечего объединять'], 422);
        }

        $canonical = DB::table('client')->where('id', $canonicalId)->whereNull('dateDeleted')->first();
        if (! $canonical) {
            return response()->json(['message' => 'Основной клиент не найден или удалён'], 422);
        }
        $live = DB::table('client')->whereIn('id', $mergeIds)->whereNull('dateDeleted')->pluck('id')
            ->map(fn ($i) => (int) $i)->all();
        if (! $live) {
            return response()->json(['message' => 'Объединяемые клиенты не найдены'], 422);
        }

        $moved = DB::transaction(function () use ($canonicalId, $live, $canonical) {
            $stats = [];
            foreach (self::CLIENT_FK_MAP as $table => $column) {
                // consultant.clients — денормализованная ссылка, её тоже чиним.
                $n = DB::table($table)->whereIn($column, $live)->update([$column => $canonicalId]);
                if ($n > 0) $stats[$table] = $n;
            }

            // Контакты: если у канонической карточки поле пустое — подтягиваем из дубля,
            // чтобы слияние не теряло почту/телефон.
            $donor = DB::table('client')->whereIn('id', $live)
                ->where(function ($q) { $q->whereNotNull('email')->orWhereNotNull('phone'); })
                ->orderBy('id')->first();
            if ($donor) {
                $patch = [];
                foreach (['email', 'phone', 'birthDate', 'city'] as $f) {
                    if (empty($canonical->$f) && ! empty($donor->$f)) $patch[$f] = $donor->$f;
                }
                if ($patch) DB::table('client')->where('id', $canonicalId)->update($patch);
            }

            DB::table('client')->whereIn('id', $live)->update(['dateDeleted' => now()]);

            return $stats;
        });

        \App\Support\Audit::log('client_duplicates_merge', 'client', (string) $canonicalId, [
            'merged' => $live,
            'moved' => $moved,
        ]);

        $movedContracts = $moved['contract'] ?? 0;

        return response()->json([
            'message' => 'Объединено карточек: ' . count($live)
                . ($movedContracts ? ', перенесено контрактов: ' . $movedContracts : ''),
            'canonical' => $canonicalId,
            'merged' => $live,
            'moved' => $moved,
        ]);
    }

    /** Пометить группу как «не дубли» — больше не показывать (однофамильцы). */
    public function ignoreClientDuplicates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $key = $this->dupGroupKey($data['ids']);
        DB::table('client_duplicate_ignores')->updateOrInsert(
            ['group_key' => $key],
            [
                'client_ids' => implode(',', $data['ids']),
                'reason' => $data['reason'] ?? null,
                'created_by' => $request->user()?->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['message' => 'Группа помечена как «не дубли»', 'key' => $key]);
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
