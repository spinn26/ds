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
use App\Models\AgreementDocument;
use App\Models\BankRequisite;
use App\Models\Consultant;
use App\Models\LogAcceptance;
use App\Models\Requisite;
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
                'gender' => $user->gender,
                'birthDate' => $user->birthDate,
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
            'requisites' => $requisite ? RequisiteResource::make($requisite) : null,
            'bankRequisites' => $bankReq ? BankRequisiteResource::make($bankReq) : null,
            'referral' => $referralInfo,
        ]);
    }

    /**
     * Обновление персональных данных.
     * ФИО заблокировано — изменение только через ТП.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->phone = $request->input('phone', $user->phone);
        $user->nicTG = $request->input('nicTG', $user->nicTG);
        $user->gender = $request->input('gender', $user->gender);
        $user->birthDate = $request->input('birthDate', $user->birthDate);
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
        $user->saveQuietly();

        return response()->json([
            'message' => 'Анкета сохранена',
            'questionnaireCompleted' => true,
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
        $docs = AgreementDocument::orderBy('number')->get();

        return response()->json(AgreementDocumentResource::collection($docs));
    }

    // --- Private helpers ---

    private function getSignedDocuments(?Consultant $consultant): array
    {
        if (! $consultant) {
            return ['accepted' => false, 'acceptedAt' => null, 'documents' => []];
        }

        $acceptance = LogAcceptance::where('consultant', $consultant->id)
            ->orderByDesc('dateAccepted')
            ->first();

        $documents = AgreementDocument::orderBy('number')->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'link' => $d->link,
            ])
            ->toArray();

        return [
            'accepted' => (bool) $consultant->acceptance,
            'acceptedAt' => $acceptance?->dateAccepted?->toIso8601String(),
            'documents' => $documents,
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
