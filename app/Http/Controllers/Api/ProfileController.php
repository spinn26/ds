<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Controller;
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

        // --- Подраздел 1: Информация о партнёре ---
        $statusInfo = $consultant ? $this->statusService->getStatusInfo($consultant) : null;

        // Подписанные документы
        $signedDocuments = $this->getSignedDocuments($consultant);

        // --- Подраздел 2: Реквизиты ---
        $requisites = $consultant ? $this->getRequisites($consultant) : null;
        $bankRequisites = $consultant ? $this->getBankRequisites($consultant) : null;

        // --- Подраздел 3: Реферальные ссылки ---
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
            'requisites' => $requisites,
            'bankRequisites' => $bankRequisites,
            'referral' => $referralInfo,
        ]);
    }

    /**
     * Обновление персональных данных.
     * ФИО заблокировано — изменение только через ТП.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'phone' => 'nullable|string|max:50',
            'nicTG' => 'nullable|string|max:100',
            'gender' => 'nullable|string',
            'birthDate' => 'nullable|date',
        ]);

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
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|max:5120', // max 5MB
        ]);

        $user = $request->user();
        $path = $request->file('avatar')->store('avatars', 'public');

        $user->avatar = $path;
        $user->saveQuietly();

        return response()->json([
            'message' => 'Аватар обновлён',
            'avatarUrl' => '/storage/' . $path,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password) && $user->password !== md5($request->current_password)) {
            return response()->json(['message' => 'Текущий пароль неверен'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->saveQuietly();

        return response()->json(['message' => 'Пароль изменён']);
    }

    /**
     * Сохранение/обновление реквизитов ИП.
     */
    public function updateRequisites(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $request->validate([
            'individualEntrepreneur' => 'required|string|max:255',
            'inn' => 'required|string|max:20',
            'ogrn' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'registrationDate' => 'nullable|date',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

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
            'verified' => false, // сброс верификации при изменении
            'status' => 2, // consultant — ожидает проверки
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
            'requisites' => $this->formatRequisite($requisite),
        ]);
    }

    /**
     * Сохранение/обновление банковских реквизитов.
     */
    public function updateBankRequisites(Request $request): JsonResponse
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

        $request->validate([
            'bankName' => 'required|string|max:255',
            'bankBik' => 'required|string|max:20',
            'accountNumber' => 'required|string|max:30',
            'correspondentAccount' => 'nullable|string|max:30',
            'beneficiaryName' => 'required|string|max:255',
        ]);

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
            'bankRequisites' => $this->formatBankRequisite($bankReq),
        ]);
    }

    /**
     * Список документов для акцепта.
     */
    public function agreementDocuments(): JsonResponse
    {
        $docs = AgreementDocument::orderBy('number')->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'link' => $d->link,
                'number' => $d->number,
            ]);

        return response()->json($docs);
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

    private function getRequisites(Consultant $consultant): ?array
    {
        $requisite = Requisite::where('consultant', $consultant->id)
            ->active()
            ->first();

        return $requisite ? $this->formatRequisite($requisite) : null;
    }

    private function formatRequisite(Requisite $r): array
    {
        $statusName = DB::table('status_requisites')
            ->where('id', $r->status)
            ->value('name');

        return [
            'id' => $r->id,
            'individualEntrepreneur' => $r->individualEntrepreneur,
            'inn' => $r->inn,
            'ogrn' => $r->ogrn,
            'address' => $r->address,
            'registrationDate' => $r->registrationDate?->toDateString(),
            'email' => $r->email,
            'phone' => $r->phone,
            'verified' => $r->verified,
            'statusName' => $statusName,
        ];
    }

    private function getBankRequisites(Consultant $consultant): ?array
    {
        $requisite = Requisite::where('consultant', $consultant->id)
            ->active()
            ->first();

        if (! $requisite) {
            return null;
        }

        $bankReq = BankRequisite::where('requisites', $requisite->id)
            ->active()
            ->first();

        return $bankReq ? $this->formatBankRequisite($bankReq) : null;
    }

    private function formatBankRequisite(BankRequisite $b): array
    {
        return [
            'id' => $b->id,
            'bankName' => $b->bankName,
            'bankBik' => $b->bankBik,
            'accountNumber' => $b->accountNumber,
            'correspondentAccount' => $b->correspondentAccount,
            'beneficiaryName' => $b->beneficiaryName,
            'verified' => $b->verified,
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
