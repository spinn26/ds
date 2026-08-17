<?php

namespace App\Http\Controllers\Api;

use App\Services\PaymentRegistryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Api\Admin\AddPaymentRequest;
use App\Http\Requests\Api\Admin\UpdatePaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Реестр выплат (спека ✅Реестр выплат.md).
 *
 * Читает готовый агрегат из consultantBalance — легаси-таблица, в которой
 * за каждый месяц для каждого партнёра лежит уже посчитанный бланс:
 *   balance | accruedTransactional | accruedNonTransactional | accruedPool
 *   | accruedTotal | totalPayable | payed | remaining | status.
 *
 * Мы просто отдаём эту свёртку + обогащаем флагом verifiedRequisites
 * (для зелёной/красной иконки у ФИО в таблице).
 */
class AdminPaymentRegistryController extends Controller
{
        public function __construct(
        private readonly PaymentRegistryService $registry,
    ) {}

/** GET /admin/payment-registry?year=&month=&search=&status=&activity=&nonZero= */
    public function index(Request $request): JsonResponse
    {
        // Фильтры, сборка строк и итоги — в PaymentRegistryService.
        // Метод занимал 266 строк.
        return response()->json($this->registry->build($request));
    }

    /**
     * POST /admin/payment-registry/recalc — пересобрать снимок за месяц.
     *
     * Кнопка «Пересчитать» раньше только перезагружала таблицу (фронтовый
     * `load()`), то есть перечитывала тот же снимок — отсюда «нажал, ничего не
     * изменилось». Реестр по решению 2026-06-05 читает деньги ТОЛЬКО из
     * `consultantBalance`, а его после финализации/правок комиссий кто-то
     * должен пересобрать. Теперь это делает сама кнопка.
     *
     * Пересборка идемпотентна: начисления ← `commission`, пул ← `poolLog`.
     * Исторические месяцы (< HISTORICAL_CUTOFF) метод не трогает.
     */
    public function recalc(Request $request, \App\Services\CommissionCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);
        $ym = sprintf('%04d-%02d', $data['year'], $data['month']);

        if (\App\Services\CommissionCalculator::isHistorical($ym)) {
            return response()->json([
                'message' => "Период {$ym} исторический — снимок неизменен.",
            ], 422);
        }

        // Тот же lock-неймспейс, что у финализации: она пересобирает снимок
        // тем же методом, и одновременный запуск переписывал бы одни строки.
        $lock = \Illuminate\Support\Facades\Cache::lock("finalize:apply:{$ym}", 300);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Пересчёт за этот месяц уже выполняется. Подождите минуту.',
            ], 423);
        }

        $before = $this->monthTotals($ym);
        try {
            $result = $calculator->resyncMonth($ym);
        } finally {
            $lock->release();
        }
        $after = $this->monthTotals($ym);

        return response()->json([
            'message' => sprintf(
                'Пересчитано партнёров: %d. Начислено: %s → %s ₽, пул: %s ₽.',
                $result['consultants'],
                number_format($before['accrued'], 2, '.', ' '),
                number_format($after['accrued'], 2, '.', ' '),
                number_format($after['pool'], 2, '.', ' '),
            ),
            'consultants' => $result['consultants'],
            'accruedBefore' => $before['accrued'],
            'accruedAfter' => $after['accrued'],
            'poolAfter' => $after['pool'],
        ]);
    }

    /** @return array{accrued: float, pool: float} */
    private function monthTotals(string $ym): array
    {
        $r = DB::table('consultantBalance')
            ->where('dateMonth', $ym)
            ->selectRaw('COALESCE(SUM(COALESCE("accruedTransactional",0) + COALESCE("accruedNonTransactional",0)),0) AS accrued,
                         COALESCE(SUM(COALESCE("accruedPool",0)),0) AS pool')
            ->first();

        return ['accrued' => (float) ($r->accrued ?? 0), 'pool' => (float) ($r->pool ?? 0)];
    }

    /** GET /admin/payment-registry/{id}/requisites — для попапа реквизитов в строке. */
    public function requisites(int $id): JsonResponse
    {
        $balance = DB::table('consultantBalance')->where('id', $id)->first();
        if (! $balance) {
            return response()->json(['message' => 'Запись не найдена'], 404);
        }

        // Suspended partner → hide requisites entirely (server-side gate).
        if (DB::table('consultant')->where('id', $balance->consultant)->value('payments_suspended')) {
            return response()->json([
                'message' => 'Выплаты по партнёру приостановлены — реквизиты скрыты',
                'suspended' => true,
            ], 403);
        }

        $req = DB::table('requisites')
            ->where('consultant', $balance->consultant)
            ->whereNull('deletedAt')
            ->orderByDesc('verified')
            ->first();
        if (! $req) {
            return response()->json(['message' => 'Реквизиты не найдены', 'verified' => false], 404);
        }
        $bank = DB::table('bankrequisites')->where('requisites', $req->id)->first();

        return response()->json([
            'verified' => (bool) $req->verified,
            'individualEntrepreneur' => $req->individualEntrepreneur,
            'inn' => $req->inn,
            'ogrn' => $req->ogrn,
            'address' => $req->address,
            'accountNumber' => $bank->accountNumber ?? null,
            'correspondentAccount' => $bank->correspondentAccount ?? null,
            'bankBik' => $bank->bankBik ?? null,
            'bankName' => $bank->bankName ?? null,
        ]);
    }

    /** GET /admin/payment-registry/{balanceId}/payments — список платежей по строке. */
    public function listPayments(int $balanceId): JsonResponse
    {
        $balance = DB::table('consultantBalance')->where('id', $balanceId)->first();
        if (! $balance) {
            return response()->json(['message' => 'Запись не найдена'], 404);
        }

        $payments = DB::table('consultantPayment as p')
            ->leftJoin('WebUser as u', 'u.id', '=', 'p.webUser')
            ->where('p.consultantBalance', $balanceId)
            ->orderByDesc('p.paymentDate')
            ->orderByDesc('p.id')
            ->get([
                'p.id', 'p.amount', 'p.paymentDate', 'p.status', 'p.comment',
                DB::raw('TRIM(CONCAT(u."firstName", \' \', u."lastName")) as "createdBy"'),
            ]);

        $statuses = DB::table('consultantPaymentStatus')->pluck('title', 'id');

        return response()->json([
            'items' => $payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'paymentDate' => $p->paymentDate,
                'status' => $p->status,
                'statusName' => $statuses[$p->status] ?? null,
                'comment' => $p->comment,
                'createdBy' => trim((string) $p->createdBy) ?: null,
            ]),
            'statuses' => $statuses->map(fn ($title, $id) => ['value' => (int) $id, 'title' => $title])->values(),
        ]);
    }

    /**
     * PATCH /admin/payment-registry/payments/{paymentId}
     * Изменить статус / сумму / комментарий платежа + пересчёт балансa.
     */
    public function updatePayment(UpdatePaymentRequest $request, int $paymentId): JsonResponse
    {
        $data = $request->validated();

        $payment = DB::table('consultantPayment')->where('id', $paymentId)->first();
        if (! $payment) {
            return response()->json(['message' => 'Платёж не найден'], 404);
        }

        DB::transaction(function () use ($payment, $data) {
            $update = [];
            if (array_key_exists('amount', $data) && $data['amount'] !== null) $update['amount'] = $data['amount'];
            if (array_key_exists('status', $data) && $data['status'] !== null) $update['status'] = $data['status'];
            if (array_key_exists('comment', $data)) $update['comment'] = $data['comment'];
            if ($update) {
                DB::table('consultantPayment')->where('id', $payment->id)->update($update);
            }
            $this->recalcBalance((int) $payment->consultantBalance);
        });

        return response()->json(['message' => 'Платёж обновлён']);
    }

    /**
     * DELETE /admin/payment-registry/payments/{paymentId}
     * Удалить платёж + пересчёт балансa. Hard delete — в схеме нет deletedAt.
     */
    public function deletePayment(int $paymentId): JsonResponse
    {
        $payment = DB::table('consultantPayment')->where('id', $paymentId)->first();
        if (! $payment) {
            return response()->json(['message' => 'Платёж не найден'], 404);
        }

        DB::transaction(function () use ($payment) {
            DB::table('consultantPayment')->where('id', $payment->id)->delete();
            $this->recalcBalance((int) $payment->consultantBalance);
        });

        return response()->json(['message' => 'Платёж удалён']);
    }

    /**
     * Пересчёт consultantBalance.payed/remaining/status из текущих платежей.
     * Учитываем только status IN (1, 2): «Платёж отправлен», «Оплачено».
     * Статус 3 «Отказ» не уменьшает остаток.
     */
    private function recalcBalance(int $balanceId): void
    {
        $balance = DB::table('consultantBalance')->where('id', $balanceId)->first();
        if (! $balance) return;

        $paid = (float) DB::table('consultantPayment')
            ->where('consultantBalance', $balanceId)
            ->whereIn('status', [1, 2])
            ->sum('amount');

        $totalPayable = (float) ($balance->totalPayable ?? 0);
        $remaining = $totalPayable - $paid;
        $newStatus = $paid <= 0
            ? 'В обработке'
            : ($remaining <= 0 ? 'Оплачено полностью' : 'Частично оплачено');

        DB::table('consultantBalance')->where('id', $balanceId)->update([
            'payed' => $paid,
            'remaining' => $remaining,
            'status' => $newStatus,
        ]);
    }

    /** POST /admin/payment-registry/{id}/payments — добавить платёж. */
    public function addPayment(AddPaymentRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        $balance = DB::table('consultantBalance')->where('id', $id)->first();
        if (! $balance) {
            return response()->json(['message' => 'Запись не найдена'], 404);
        }

        // Hard gate: no payouts while the partner is suspended (UI also hides the
        // button, but block here too so the API can't be called around it).
        if (DB::table('consultant')->where('id', $balance->consultant)->value('payments_suspended')) {
            return response()->json([
                'message' => 'Выплаты по партнёру приостановлены — снимите приостановку перед оплатой',
            ], 422);
        }

        DB::transaction(function () use ($id, $balance, $data, $request) {
            DB::table('consultantPayment')->insert([
                'consultantBalance' => $id,
                'amount' => $data['amount'],
                'paymentDate' => now(),
                'status' => 1,   // «Оплачено» (этап А, см. спеку)
                'comment' => $data['comment'] ?? null,
                'webUser' => $request->user()->id,
            ]);

            $newPayed = (float) ($balance->payed ?? 0) + (float) $data['amount'];
            $newRemaining = (float) ($balance->totalPayable ?? 0) - $newPayed;
            $newStatus = $newRemaining <= 0 ? 'Оплачено полностью' : 'Частично оплачено';

            DB::table('consultantBalance')->where('id', $id)->update([
                'payed' => $newPayed,
                'remaining' => $newRemaining,
                'status' => $newStatus,
            ]);
        });

        // Notify the consultant directly (their WebUser id is on the balance row).
        $webUserId = DB::table('consultant')
            ->where('id', $balance->consultant)
            ->value('webUser');

        if ($webUserId) {
            NotificationController::create(
                (int) $webUserId,
                'payment',
                'Выплата зафиксирована',
                sprintf('Сумма: %s ₽%s', number_format((float) $data['amount'], 2, '.', ' '),
                    !empty($data['comment']) ? ' · ' . $data['comment'] : ''),
                '/payments',
            );
        }

        return response()->json(['message' => 'Платёж зафиксирован']);
    }
}
