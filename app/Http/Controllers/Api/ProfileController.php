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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     */
    public function updateRequisites(UpdateRequisitesRequest $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        // DaData-проверка ИНН — та же, что в попапе ввода реквизитов на витрине
        // продуктов (ProductController::setupRequisites): ИНН должен быть найден
        // в ЕГРИП/ЕГРЮЛ и быть действующим. Мягко к настройке: если DaData не
        // сконфигурирована — не блокируем ввод (всё равно идёт ручная проверка).
        $innClean = preg_replace('/\D/', '', (string) $request->input('inn'));
        if (strlen($innClean) !== 10 && strlen($innClean) !== 12) {
            return response()->json([
                'message' => 'ИНН должен быть 10 цифр (для ООО) или 12 цифр (для ИП).',
            ], 422);
        }
        $dadata = app(\App\Services\DadataService::class);
        if ($dadata->isConfigured()) {
            $fns = $dadata->findByInn($innClean);
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

        $data = [
            'consultant' => $consultant->id,
            'individualEntrepreneur' => $request->input('individualEntrepreneur'),
            'inn' => $request->input('inn'),
            'ogrn' => $request->input('ogrn'),
            'address' => $request->input('address'),
            'registrationDate' => $request->input('registrationDate'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'verified' => false,
            'status' => 2,
            'dateChange' => now(),
            'person' => $user->id,
            'webUser' => $user->id,
        ];

        if ($requisite) {
            $requisite->update($data);
        } else {
            $requisite = Requisite::create($data);
        }

        return response()->json([
            'message' => 'Реквизиты сохранены. Ожидайте верификации.',
            'requisites' => RequisiteResource::make($requisite),
        ]);
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

        if ($bankReq) {
            $bankReq->update($data);
        } else {
            $bankReq = BankRequisite::create($data);
        }

        return response()->json([
            'message' => 'Банковские реквизиты сохранены. Ожидайте верификации.',
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
