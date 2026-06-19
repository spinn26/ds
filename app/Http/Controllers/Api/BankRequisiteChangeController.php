<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankRequisite;
use App\Models\BankRequisiteChangeRequest;
use App\Models\Consultant;
use App\Models\Requisite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Смена банковских реквизитов партнёра с доп. проверкой + приостановка выплат.
 *
 * Партнёр подаёт запрос (store) — текущие верифицированные реквизиты НЕ
 * трогаются (остаются verified, доступ к продуктам сохраняется), создаётся
 * запрос «было/стало» и автоматически ставится приостановка выплат. Катя
 * (роль calculations) на отдельной странице принимает/отклоняет, а также
 * может вручную ставить/снимать приостановку выплат.
 */
class BankRequisiteChangeController extends Controller
{
    private const STAFF_ROLE = 'calculations';

    /** Партнёр: подать запрос на смену банковских реквизитов. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bankName' => 'required|string|max:255',
            'bankBik' => 'required|string|max:20',
            'accountNumber' => 'required|string|max:40',
            'correspondentAccount' => 'nullable|string|max:40',
        ]);

        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();
        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $requisite = Requisite::where('consultant', $consultant->id)->active()->first();
        if (! $requisite) {
            return response()->json(['message' => 'Сначала заполните реквизиты ИП'], 422);
        }
        $bankReq = BankRequisite::where('requisites', $requisite->id)->active()->first();

        $req = DB::transaction(function () use ($consultant, $requisite, $bankReq, $data) {
            // Один активный запрос на партнёра — повторная подача обновляет его.
            $req = BankRequisiteChangeRequest::where('consultant', $consultant->id)
                ->where('status', 'pending')->first()
                ?? new BankRequisiteChangeRequest();

            $req->fill([
                'consultant' => $consultant->id,
                'requisite_id' => $requisite->id,
                'old_bank_name' => $bankReq?->bankName,
                'old_bank_bik' => $bankReq?->bankBik,
                'old_account_number' => $bankReq?->accountNumber,
                'old_correspondent_account' => $bankReq?->correspondentAccount,
                'new_bank_name' => $data['bankName'],
                'new_bank_bik' => $data['bankBik'],
                'new_account_number' => $data['accountNumber'],
                'new_correspondent_account' => $data['correspondentAccount'] ?? null,
                'status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'rejection_reason' => null,
            ]);
            $req->save();

            // Авто-приостановка выплат на время проверки смены реквизитов.
            $consultant->payments_suspended = true;
            $consultant->payments_suspended_at = now();
            $consultant->save();

            return $req;
        });

        NotificationController::notifyRoles(
            [self::STAFF_ROLE],
            'requisites',
            'Смена банковских реквизитов',
            'Партнёр '.($consultant->personName ?? ('#'.$consultant->id)).' запросил смену банковских реквизитов.',
            '/manage/bank-changes',
        );

        return response()->json([
            'message' => 'Запрос на смену реквизитов отправлен на проверку. Выплаты временно приостановлены до подтверждения.',
            'pending' => true,
            'id' => $req->id,
        ]);
    }

    /** Партнёр: есть ли активный запрос на смену (для UI кнопки/статуса). */
    public static function pendingForConsultant(?int $consultantId): bool
    {
        if (! $consultantId) {
            return false;
        }

        return BankRequisiteChangeRequest::where('consultant', $consultantId)
            ->where('status', 'pending')->exists();
    }

    /** Катя: список запросов на смену (по умолчанию — ожидающие). */
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');
        $query = BankRequisiteChangeRequest::query();
        if (in_array($status, ['pending', 'accepted', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $rows = $query->orderByDesc('id')
            ->offset($this->offset($request))
            ->limit($this->perPage($request))
            ->get();

        $names = DB::table('consultant')
            ->whereIn('id', $rows->pluck('consultant')->filter()->unique())
            ->pluck('personName', 'id');

        $data = $rows->map(fn ($r) => [
            'id' => $r->id,
            'consultantId' => $r->consultant,
            'partnerName' => $names[$r->consultant] ?? ('#'.$r->consultant),
            'status' => $r->status,
            'old' => [
                'bankName' => $r->old_bank_name,
                'bankBik' => $r->old_bank_bik,
                'accountNumber' => $r->old_account_number,
                'correspondentAccount' => $r->old_correspondent_account,
            ],
            'new' => [
                'bankName' => $r->new_bank_name,
                'bankBik' => $r->new_bank_bik,
                'accountNumber' => $r->new_account_number,
                'correspondentAccount' => $r->new_correspondent_account,
            ],
            'rejectionReason' => $r->rejection_reason,
            'createdAt' => optional($r->created_at)->toIso8601String(),
            'reviewedAt' => optional($r->reviewed_at)->toIso8601String(),
        ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Катя: принять запрос — применить новые банковские реквизиты. */
    public function accept(Request $request, int $id): JsonResponse
    {
        $req = BankRequisiteChangeRequest::where('id', $id)->where('status', 'pending')->first();
        if (! $req) {
            return response()->json(['message' => 'Запрос не найден или уже обработан'], 404);
        }

        DB::transaction(function () use ($req, $request) {
            $bankReq = $req->requisite_id
                ? BankRequisite::where('requisites', $req->requisite_id)->active()->first()
                : null;

            if ($bankReq) {
                // Применяем новые данные, СОХРАНЯЯ текущий статус верификации
                // (по требованию: ранее верифицированные остаются verified).
                $bankReq->bankName = $req->new_bank_name;
                $bankReq->bankBik = $req->new_bank_bik;
                $bankReq->accountNumber = $req->new_account_number;
                $bankReq->correspondentAccount = $req->new_correspondent_account;
                $bankReq->dateChange = now();
                $bankReq->save();
            }

            $req->status = 'accepted';
            $req->reviewed_by = $request->user()->id;
            $req->reviewed_at = now();
            $req->save();

            // Проверка завершена — снимаем приостановку выплат.
            $this->resumePayments($req->consultant);
        });

        $this->notifyPartner($req->consultant, 'Реквизиты обновлены',
            'Ваши новые банковские реквизиты приняты. Выплаты возобновлены.');

        return response()->json(['message' => 'Запрос принят, реквизиты обновлены.']);
    }

    /** Катя: отклонить запрос. */
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['comment' => 'nullable|string|max:1000']);
        $req = BankRequisiteChangeRequest::where('id', $id)->where('status', 'pending')->first();
        if (! $req) {
            return response()->json(['message' => 'Запрос не найден или уже обработан'], 404);
        }

        DB::transaction(function () use ($req, $request, $data) {
            $req->status = 'rejected';
            $req->reviewed_by = $request->user()->id;
            $req->reviewed_at = now();
            $req->rejection_reason = $data['comment'] ?? null;
            $req->save();

            // Запрос закрыт — прежние реквизиты в силе, выплаты возобновляем.
            $this->resumePayments($req->consultant);
        });

        $reason = $data['comment'] ?? '';
        $this->notifyPartner($req->consultant, 'Смена реквизитов отклонена',
            'Запрос на смену банковских реквизитов отклонён.'.($reason ? ' Причина: '.$reason : ''));

        return response()->json(['message' => 'Запрос отклонён.']);
    }

    /** Катя: ручная приостановка/возобновление выплат у партнёра. */
    public function suspendPayments(Request $request, int $consultant): JsonResponse
    {
        $data = $request->validate(['suspended' => 'required|boolean']);
        $c = Consultant::find($consultant);
        if (! $c) {
            return response()->json(['message' => 'Партнёр не найден'], 404);
        }
        $c->payments_suspended = $data['suspended'];
        $c->payments_suspended_at = $data['suspended'] ? now() : null;
        $c->save();

        return response()->json([
            'message' => $data['suspended'] ? 'Выплаты приостановлены.' : 'Выплаты возобновлены.',
            'paymentsSuspended' => (bool) $data['suspended'],
        ]);
    }

    private function resumePayments(int $consultantId): void
    {
        Consultant::where('id', $consultantId)->update([
            'payments_suspended' => false,
            'payments_suspended_at' => null,
        ]);
    }

    private function notifyPartner(int $consultantId, string $title, string $message): void
    {
        $webUserId = Consultant::where('id', $consultantId)->value('webUser');
        if ($webUserId) {
            NotificationController::create((int) $webUserId, 'requisites', $title, $message, '/profile?tab=requisites');
        }
    }

    private function offset(Request $request): int
    {
        return max(0, ((int) $request->input('page', 1) - 1) * $this->perPage($request));
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 25), 1), 100);
    }
}
