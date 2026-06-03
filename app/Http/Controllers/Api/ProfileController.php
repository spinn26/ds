<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Profile\ChangePasswordRequest;
use App\Http\Requests\Api\Profile\UpdateBankRequisitesRequest;
use App\Http\Requests\Api\Profile\UpdateProfileRequest;
use App\Http\Requests\Api\Profile\UpdateRequisitesRequest;
use App\Http\Requests\Api\Profile\UploadAvatarRequest;
use App\Http\Resources\AgreementDocumentResource;
use App\Http\Resources\BankRequisiteResource;
use App\Http\Resources\RequisiteResource;
use App\Support\LegacyId;
use App\Models\AgreementDocument;
use App\Models\BankRequisite;
use App\Models\Consultant;
use App\Models\Requisite;
use App\Services\PartnerAcceptanceService;
use App\Services\PartnerStatusService;
use App\Services\DadataService;
use App\Services\CheckoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function __construct(
        private readonly PartnerStatusService $statusService,
    ) {}

    /**
     * Профиль партнёра — все 3 подраздела.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        $country = $user->taxResidency
            ? DB::table('country')->where('id', $user->taxResidency)->value('countryNameRu')
            : null;

        $city = $user->city
            ? DB::table('city')->where('id', $user->city)->value('cityNameRu')
            : null;

        $statusInfo = $consultant ? $this->statusService->getStatusInfo($consultant) : null;
        $signedDocuments = $this->getSignedDocuments($consultant);

        $requisite = $consultant
            ? Requisite::where('consultant', $consultant->id)->active()->first()
            : null;
        $bankReq = $requisite
            ? BankRequisite::where('requisites', $requisite->id)->active()->first()
            : null;

        $acceptance = $this->getAcceptanceStatus($consultant, $requisite);

        $referralInfo = $consultant ? $this->getReferralInfo($consultant) : null;

        // Текущий остаток по комиссионным для верхней строки кабинета —
        // `remaining` последней (по id) строки леджера consultantBalance.
        // Та же величина, что в шапке отчёта начислений (FinanceController).
        // Положительное → к выплате партнёру, отрицательное → переплата/долг.
        $commissionBalance = $consultant
            ? round((float) (DB::table('consultantBalance')
                ->where('consultant', $consultant->id)
                ->orderByDesc('id')
                ->value('remaining') ?? 0), 2)
            : null;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'firstName' => $user->firstName,
                'lastName' => $user->lastName,
                'patronymic' => $user->patronymic,
                'avatarUrl' => $user->avatar ? '/storage/' . $user->avatar : null,
                'phone' => $user->phone,
                'nicTG' => $user->nicTG,
                'telegram' => $user->nicTG,
                'gender' => $user->gender,
                'birthDate' => $user->birthDate,
                'country' => $country,
                'city' => $city,
                'position' => $user->position ?? null,
                'role' => $user->role,
            ],
            'location' => [
                'taxResidency' => $country,
                'city' => $city,
            ],
            'consultant' => $consultant ? [
                'id' => $consultant->id,
                'personName' => $consultant->personName,
                'participantCode' => $consultant->participantCode,
                'active' => $consultant->active,
                'dateCreated' => $consultant->dateCreated,
                'inviterName' => $consultant->inviterName,
            ] : null,
            'statusInfo' => $statusInfo,
            'commissionBalance' => $commissionBalance,
            'signedDocuments' => $signedDocuments,
            'acceptance' => $acceptance,
            'requisites' => $requisite ? RequisiteResource::make($requisite) : null,
            'bankRequisites' => $bankReq ? BankRequisiteResource::make($bankReq) : null,
            'referral' => $referralInfo,
        ]);
    }

    /**
     * Обновление персональных данных.
     *
     * Per spec ✅Профиль §1:
     *  - Партнёру: ФИО заблокировано (ТП), редактируемы только контактные поля.
     *  - Сотруднику: ФИО редактируемо + появляется поле «Должность».
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->phone = $request->input('phone', $user->phone);
        $user->nicTG = $request->input('telegram', $request->input('nicTG', $user->nicTG));
        $user->gender = $request->input('gender', $user->gender);
        $user->birthDate = $request->input('birthDate', $user->birthDate);

        if ($request->filled('email')) {
            $user->email = $request->input('email');
        }

        if ($request->has('country')) {
            $countryName = $request->input('country');
            $user->taxResidency = $countryName
                ? DB::table('country')->where('countryNameRu', $countryName)->value('id')
                : null;
        }

        if ($request->has('city')) {
            $cityName = $request->input('city');
            if ($cityName) {
                $cityId = DB::table('city')->where('cityNameRu', $cityName)->value('id');
                if (! $cityId) {
                    // Legacy city без серийного id — генерим вручную.
                    $cityId = DB::transaction(function () use ($cityName) {
                        $id = LegacyId::next('city');
                        DB::table('city')->insert(['id' => $id, 'cityNameRu' => $cityName]);
                        return $id;
                    });
                }
                $user->city = $cityId;
            } else {
                $user->city = null;
            }
        }

        // Сотрудник может редактировать ФИО + Должность.
        if ($user->isStaff()) {
            if ($request->filled('firstName'))  $user->firstName  = $request->input('firstName');
            if ($request->filled('lastName'))   $user->lastName   = $request->input('lastName');
            if ($request->has('patronymic'))    $user->patronymic = $request->input('patronymic');
            if ($request->has('position'))      $user->position   = $request->input('position');
        }

        $user->saveQuietly();

        return response()->json(['message' => 'Профиль обновлён']);
    }

    /**
     * Загрузка аватара.
     */
    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $user = $request->user();
        $path = $request->file('avatar')->store('avatars', 'public');

        $user->avatar = $path;
        $user->saveQuietly();

        return response()->json([
            'message' => 'Аватар обновлён',
            'avatarUrl' => '/storage/' . $path,
        ]);
    }

    /**
     * Сохранение онбординг-анкеты партнёра (10 вопросов).
     * Заполняется один раз после регистрации; без неё остальные разделы
     * остаются заблокированными (фронт).
     */
    public function saveQuestionnaire(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workField' => ['nullable', 'string', 'max:255'],
            'salesExperience' => ['required', 'string', 'in:none,<1,1-3,3+'],
            'financeExperience' => ['nullable', 'string', 'max:4000'],
            'hasPotentialClients' => ['required', 'string', 'in:yes,partly,no'],
            'potentialClientsCount' => ['nullable', 'string', 'in:<10,10-30,30-100,100+'],
            'currentIncome' => ['nullable', 'string', 'max:128'],
            'weeklyHours' => ['required', 'string', 'in:<10,10-20,20-40,full-time'],
            'incomeFactors' => ['nullable', 'string', 'max:4000'],
        ]);

        $user = $request->user();
        $user->workField = $data['workField'] ?? null;
        $user->salesExperience = $data['salesExperience'];
        $user->financeExperience = $data['financeExperience'] ?? null;
        $user->hasPotentialClients = $data['hasPotentialClients'];
        $user->potentialClientsCount = $data['potentialClientsCount'] ?? null;
        $user->currentIncome = $data['currentIncome'] ?? null;
        $user->weeklyHours = $data['weeklyHours'];
        $user->incomeFactors = $data['incomeFactors'] ?? null;
        $user->questionnaireCompletedAt = now();

        // Onboarding done → grant consultant role so the cabinet menu opens.
        // Product visibility is still gated per-course downstream.
        $roles = array_filter(array_map('trim', explode(',', (string) $user->role)));
        if (! in_array('consultant', $roles, true)) {
            $roles[] = 'consultant';
            $user->role = implode(',', $roles);
        }

        $user->saveQuietly();

        return response()->json([
            'message' => 'Анкета сохранена',
            'questionnaireCompleted' => true,
            'role' => $user->role,
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->validatePassword($request->input('current_password'))) {
            return response()->json(['message' => 'Текущий пароль неверен'], 422);
        }

        $user->password = Hash::make($request->input('password'));
        $user->saveQuietly();

        return response()->json(['message' => 'Пароль изменён']);
    }

    /**
     * Сохранение/обновление реквизитов ИП.
     *
     * Данные ИП (наименование/ОГРНИП/адрес/дата регистрации) подтягиваются
     * из ЕГРИП по введённому ИНН на СЕРВЕРЕ — не доверяем клиентской форме
     * (фронт лишь подсказывает их в onBlur). Это тот же data-fill, что в
     * попапе на витрине продуктов (ProductController::setupRequisites).
     *
     * Авто-верификация (как в админском checkRequisiteInn): если запись —
     * действующий ИП (12 цифр, status ACTIVE) и ФИО из ЕГРИП совпадает с
     * профилем, реквизиты подтверждаются автоматически. Финальный verified
     * выставляется только когда заполнены И реквизиты ИП, И банковские
     * (см. applyDadataVerification).
     */
    public function updateRequisites(UpdateRequisitesRequest $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        // После верификации реквизиты редактировать нельзя (2026-06-03).
        $existing = Requisite::where('consultant', $consultant->id)->active()->first();
        if ($existing && $existing->verified) {
            return response()->json([
                'message' => 'Реквизиты подтверждены и не могут быть изменены. Для изменения обратитесь в поддержку.',
            ], 422);
        }

        $innClean = preg_replace('/\D/', '', (string) $request->input('inn'));
        if (strlen($innClean) !== 10 && strlen($innClean) !== 12) {
            return response()->json([
                'message' => 'ИНН должен быть 10 цифр (для ООО) или 12 цифр (для ИП).',
            ], 422);
        }

        // ЕГРИП/ЕГРЮЛ через DaData. Кэш на 1 час — общий ключ с админским
        // checkRequisiteInn, чтобы не упираться в throttle на повторных сейвах.
        // Мягко к настройке: если DaData не сконфигурирована — не блокируем
        // ввод (всё уйдёт на ручную проверку).
        $dadata = app(DadataService::class);
        $fns = null;
        if ($dadata->isConfigured()) {
            $fns = Cache::remember("dadata:inn:{$innClean}", 3600, fn () => $dadata->findByInn($innClean));
            if (empty($fns['found'])) {
                return response()->json([
                    'message' => $fns['error'] ?? 'Не удалось найти ИНН в ЕГРИП/ЕГРЮЛ.',
                ], 422);
            }
            if (($fns['status'] ?? null) === 'LIQUIDATED') {
                return response()->json([
                    'message' => 'По данным ФНС, этот ИНН ликвидирован. Используйте действующий ИНН.',
                ], 422);
            }
        }

        $requisite = Requisite::where('consultant', $consultant->id)
            ->active()
            ->first();

        // ЕГРИП — источник истины для наименования/ОГРНИП/адреса/даты; клиентский
        // ввод используем только как фоллбэк, если DaData ничего не вернула.
        $data = [
            'consultant' => $consultant->id,
            'individualEntrepreneur' => ! empty($fns['name'])
                ? mb_substr($fns['name'], 0, 255)
                : $request->input('individualEntrepreneur'),
            'inn' => $innClean,
            'ogrn' => $fns['ogrn'] ?? $request->input('ogrn'),
            'address' => ! empty($fns['address'])
                ? mb_substr($fns['address'], 0, 500)
                : $request->input('address'),
            'registrationDate' => $fns['registrationDate'] ?? $request->input('registrationDate'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            // Реквизиты ВСЕГДА уходят на ручную верификацию (авто-верификация
            // отключена 2026-06-03). setRequisitesPending ниже фиксирует статус.
            'verified' => false,
            'status' => 2,
            // Пересохранение = партнёр исправляет → снимаем прежнюю причину
            // отказа (плашка «отказано» погаснет, статус снова «на проверке»).
            'rejection_reason' => null,
            'dateChange' => now(),
            'person' => $user->id,
            'webUser' => $user->id,
        ];

        // Налоговый режим — приоритетно из Checko (бесплатно отдаёт УСН/ПСН/…),
        // фоллбэк на DaData (на free-тарифе режим почти всегда пуст). Справочно,
        // на верификацию не влияет.
        $taxRegime = $this->resolveTaxRegime($innClean);
        if ($taxRegime) {
            $data['tax_regime'] = $taxRegime;
        }

        DB::transaction(function () use (&$requisite, $data, $consultant, $user) {
            if ($requisite) {
                $requisite->update($data);
            } else {
                $requisite = Requisite::create($data);
            }

            $this->setRequisitesPending($consultant, $requisite, $user);
        });

        $requisite = Requisite::where('consultant', $consultant->id)->active()->first();

        return response()->json([
            'message' => 'Реквизиты сохранены. Ожидайте проверки документов финменеджером.',
            'verified' => false,
            'requisites' => RequisiteResource::make($requisite),
        ]);
    }

    /**
     * Полнота реквизитов — обязательное условие верификации (2026-06-03).
     * Должны быть заполнены ВСЕ поля карточки: наименование/ИНН/ОГРН/адрес/
     * email/телефон ИП + банк/БИК/расчётный/корр.счёт. Получатель проставляем
     * сами из ЕГРИП на верификации, поэтому в предусловие не входит.
     */
    private function requisitesComplete(Requisite $requisite, ?BankRequisite $bank): bool
    {
        $filled = fn ($v) => $v !== null && trim((string) $v) !== '';

        $reqOk = $filled($requisite->individualEntrepreneur)
            && $filled($requisite->inn)
            && $filled($requisite->ogrn)
            && $filled($requisite->address)
            && $filled($requisite->email)
            && $filled($requisite->phone);

        if (! $reqOk || ! $bank) {
            return false;
        }

        return $filled($bank->bankName)
            && $filled($bank->bankBik)
            && $filled($bank->accountNumber)
            && $filled($bank->correspondentAccount);
    }

    /**
     * Налоговый режим по ИНН: приоритетно Checko (бесплатно отдаёт спецрежим),
     * фоллбэк DaData (finance.tax_system, на free-тарифе обычно пуст). Возвращает
     * человекочитаемую метку («УСН» / «УСН, ПСН») или null. Результаты кэшируем
     * на час (общие ключи с остальными вызовами этих сервисов).
     */
    private function resolveTaxRegime(string $inn): ?string
    {
        $clean = preg_replace('/\D/', '', $inn);
        if ($clean === '') {
            return null;
        }

        $checko = app(CheckoService::class);
        if ($checko->isConfigured()) {
            $c = Cache::remember("checko:inn:{$clean}", 3600, fn () => $checko->findByInn($clean));
            if (! empty($c['found']) && ! empty($c['taxSystemLabel'])) {
                return $c['taxSystemLabel'];
            }
        }

        $dadata = app(DadataService::class);
        if ($dadata->isConfigured()) {
            $fns = Cache::remember("dadata:inn:{$clean}", 3600, fn () => $dadata->findByInn($clean));
            if (! empty($fns['found']) && ! empty($fns['taxSystemLabel'])) {
                return $fns['taxSystemLabel'];
            }
        }

        return null;
    }

    /**
     * Фиксируем реквизиты в статусе «на проверке».
     *
     * Авто-верификация по ЕГРИП ОТКЛЮЧЕНА (2026-06-03): даже при полном
     * совпадении ФИО реквизиты всегда уходят на РУЧНУЮ верификацию
     * финменеджеру. Здесь дополнительно подтягиваем налоговый режим из
     * DaData (finance.tax_system) — справочно, на саму верификацию не влияет.
     *
     * Вызывается внутри DB::transaction вызывающим методом.
     */
    private function setRequisitesPending(Consultant $consultant, Requisite $requisite, $user): void
    {
        // Налоговый режим — если ещё не записан: Checko (приоритет) → DaData.
        if (empty($requisite->tax_regime) && $requisite->inn) {
            $taxRegime = $this->resolveTaxRegime((string) $requisite->inn);
            if ($taxRegime) {
                $requisite->tax_regime = $taxRegime;
            }
        }

        $requisite->verified = false;
        $requisite->status = 2;
        $requisite->dateChange = now();
        $requisite->save();

        $bank = BankRequisite::where('requisites', $requisite->id)->active()->first();
        if ($bank) {
            $bank->verified = false;
            $bank->dateChange = now();
            $bank->save();
        }

        // Гейт продуктов/выплат: до ручной верификации statusRequisites < 3.
        $consultant->statusRequisites = 2;
        $consultant->save();
    }

    /**
     * Сохранение/обновление банковских реквизитов.
     */
    public function updateBankRequisites(UpdateBankRequisitesRequest $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $requisite = Requisite::where('consultant', $consultant->id)
            ->active()
            ->first();

        if (! $requisite) {
            return response()->json(['message' => 'Сначала заполните реквизиты ИП'], 422);
        }

        // После верификации реквизиты редактировать нельзя (2026-06-03).
        if ($requisite->verified) {
            return response()->json([
                'message' => 'Реквизиты подтверждены и не могут быть изменены. Для изменения обратитесь в поддержку.',
            ], 422);
        }

        $bankReq = BankRequisite::where('requisites', $requisite->id)
            ->active()
            ->first();

        $data = [
            'requisites' => $requisite->id,
            'bankName' => $request->input('bankName'),
            'bankBik' => $request->input('bankBik'),
            'accountNumber' => $request->input('accountNumber'),
            'correspondentAccount' => $request->input('correspondentAccount'),
            'beneficiaryName' => $request->input('beneficiaryName'),
            'verified' => false,
            'status' => 2,
            'dateChange' => now(),
            'WebUser' => $user->id,
        ];

        DB::transaction(function () use ($bankReq, $data, $consultant, $requisite, $user) {
            if ($bankReq) {
                $bankReq->update($data);
            } else {
                BankRequisite::create($data);
            }

            // Банк — второй шаг флоу. Авто-верификация отключена: фиксируем
            // статус «на проверке», реквизиты уйдут на ручную верификацию.
            $this->setRequisitesPending($consultant, $requisite, $user);
        });

        $bankReq = BankRequisite::where('requisites', $requisite->id)->active()->first();

        return response()->json([
            'message' => 'Банковские реквизиты сохранены. Ожидайте проверки документов финменеджером.',
            'verified' => false,
            'bankRequisites' => BankRequisiteResource::make($bankReq),
        ]);
    }

    /**
     * Список документов для акцепта.
     */
    public function agreementDocuments(): JsonResponse
    {
        $docs = AgreementDocument::inFlow()->orderBy('number')->get();

        return response()->json(AgreementDocumentResource::collection($docs));
    }

    /**
     * Партнёр принимает ВСЕ документы обязательного флоу одним окном при входе.
     *
     * Решение 2026-06-02: единое блокирующее окно акцепта при входе, БЕЗ гейта
     * на верификацию реквизитов. Подписываются все in_acceptance_flow документы
     * (дедуп уже подписанных) + consultant.acceptance=true.
     */
    public function acceptOffer(Request $request, PartnerAcceptanceService $acceptance): JsonResponse
    {
        $consultant = Consultant::where('webUser', $request->user()->id)->first();
        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $acceptance->acceptAllFlowDocuments($consultant, $request);

        return response()->json([
            'message' => 'Документы приняты',
            'documentsAccepted' => true,
        ]);
    }

    /**
     * City suggestions for the profile form (plain list of Russian names).
     */
    /**
     * Подсказки городов. Источник — внешний сервис, НЕ таблица `city`
     * (она засорена мусором: «-», email и т.п., т.к. раньше любое свободно
     * введённое значение писалось туда новой строкой).
     *
     * Приоритет источников:
     *   1. GeoService — города ВСЕГО МИРА: Google Places (если задан ключ),
     *      иначе Photon/OSM (без ключа). Основной источник.
     *   2. DaData — сетевой фолбэк (только РФ), если мировой источник вернул
     *      пусто (например, недоступен).
     *   3. Статический список крупных городов РФ — крайний фолбэк.
     * Возвращает массив [{ title, value, region, country }].
     */
    public function cities(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        // Мировой источник (Google → Photon). Photon доступен всегда, так что
        // это основной путь; пустой ответ означает сбой/короткий запрос.
        $items = app(\App\Services\GeoService::class)->suggestCity($q);
        if (! empty($items)) {
            return response()->json($items);
        }

        $dadata = app(\App\Services\DadataService::class);
        if ($q !== '' && $dadata->isConfigured()) {
            return response()->json($dadata->suggestCity($q));
        }

        // Фоллбэк, если DaData не настроена: статический список крупных
        // городов (тоже НЕ из таблицы `city`), с фильтром по вводу.
        $common = ['Москва', 'Санкт-Петербург', 'Новосибирск', 'Екатеринбург', 'Казань',
            'Нижний Новгород', 'Краснодар', 'Самара', 'Ростов-на-Дону', 'Уфа',
            'Красноярск', 'Воронеж', 'Пермь', 'Волгоград'];
        $items = array_map(fn ($c) => ['title' => $c, 'value' => $c, 'region' => null, 'country' => 'Россия'], $common);
        if ($q !== '') {
            $items = array_values(array_filter($items, fn ($i) => mb_stripos($i['value'], $q) === 0));
        }

        return response()->json($items);
    }

    /**
     * Справочник стран для выпадашки профиля. Источник — таблица `country`
     * (чистая, 251 страна), а НЕ хардкод на фронте. Популярные (СНГ + частые)
     * выносятся наверх в фиксированном порядке, остальные — по алфавиту.
     * Возвращает плоский массив названий (countryNameRu) — тот же контракт,
     * что ждёт v-autocomplete и маппинг countryNameRu→id в update().
     */
    public function countries(): JsonResponse
    {
        $all = DB::table('country')
            ->whereNotNull('countryNameRu')
            ->where('countryNameRu', '!=', '')
            ->orderBy('countryNameRu')
            ->pluck('countryNameRu')
            ->all();

        // Названия должны совпадать с countryNameRu в таблице (напр. «Киргизия»,
        // не «Кыргызстан»). Отсутствующие просто пропускаются через in_array.
        $priority = ['Россия', 'Казахстан', 'Беларусь', 'Узбекистан', 'Киргизия',
            'Таджикистан', 'Армения', 'Грузия', 'Азербайджан', 'Молдавия', 'Украина'];

        $top = array_values(array_filter($priority, fn ($n) => in_array($n, $all, true)));
        $rest = array_values(array_filter($all, fn ($n) => ! in_array($n, $top, true)));

        return response()->json(array_merge($top, $rest));
    }

    // --- Private helpers ---

    /**
     * Returns a flat list of agreement documents annotated with the
     * partner's latest acceptance timestamp per document. Profile.vue
     * iterates this array directly.
     *
     * @return array<int, array{id:int,title:string,url:?string,signedAt:?string}>
     */
    private function getSignedDocuments(?Consultant $consultant): array
    {
        $documents = AgreementDocument::inFlow()->orderBy('number')->get();

        $latestPerDoc = $consultant
            ? DB::table('partnerAcceptance')
                ->where('consultant', $consultant->id)
                ->where('accepted', true)
                ->selectRaw('"documentType" as document_id, MAX("dateAccepted") as last_date')
                ->groupBy('documentType')
                ->pluck('last_date', 'document_id')
            : collect();

        return $documents->map(function ($d) use ($latestPerDoc) {
            $signedAt = $latestPerDoc[$d->id] ?? null;
            return [
                'id' => $d->id,
                'title' => $d->name,
                'url' => $d->link,
                'signedAt' => $signedAt ? (string) $signedAt : null,
            ];
        })->values()->all();
    }

    /**
     * Compact acceptance status for the cabinet's blocking dialog.
     * `needsOfferSignature` lights up once requisites are verified but
     * the partner has not yet ticked the Оферта.
     */
    private function getAcceptanceStatus(?Consultant $consultant, ?Requisite $requisite): array
    {
        if (! $consultant) {
            return [
                'requisitesVerified' => false,
                'offerAccepted' => false,
                'needsOfferSignature' => false,
            ];
        }

        $verified = ((int) $consultant->statusRequisites) === 3
            || ($requisite && (bool) $requisite->verified);
        $offerAccepted = (bool) $consultant->acceptance;

        return [
            'requisitesVerified' => $verified,
            'offerAccepted' => $offerAccepted,
            'needsOfferSignature' => $verified && ! $offerAccepted,
        ];
    }

    private function getReferralInfo(Consultant $consultant): array
    {
        $canInvite = $consultant->canInvite();
        $referralCode = $consultant->participantCode;

        return [
            'canInvite' => $canInvite,
            'referralCode' => $canInvite ? $referralCode : null,
            'referralLink' => $canInvite && $referralCode
                ? url("/register?ref={$referralCode}")
                : null,
        ];
    }
}
