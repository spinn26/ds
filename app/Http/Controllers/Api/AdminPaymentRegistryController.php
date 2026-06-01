<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
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
    /** GET /admin/payment-registry?year=&month=&search=&status=&activity=&nonZero= */
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
            'search' => 'nullable|string|max:200',
            'status' => 'nullable|string',
            'activity' => 'nullable|integer',
            'nonZero' => 'nullable|boolean',
            'withDetachment' => 'nullable|boolean',
            'withOp' => 'nullable|boolean',
        ]);

        $year = (int) $params['year'];
        $month = (int) $params['month'];
        $dm = sprintf('%04d-%02d', $year, $month);

        $q = DB::table('consultantBalance as b')
            ->leftJoin('consultant as c', 'c.id', '=', 'b.consultant')
            ->where(function ($q) use ($dm, $year, $month) {
                $q->where('b.dateMonth', $dm)
                  ->orWhere(function ($qq) use ($year, $month) {
                      $qq->where('b.dateYear', (string) $year)
                         ->where('b.dateMonth', sprintf('%02d', $month));
                  });
            })
            ->select([
                'b.id',
                'b.consultant',
                'b.consultantPersonName',
                'b.status',
                'b.balance',
                'b.accruedTransactional',
                'b.accruedNonTransactional',
                'b.accruedPool',
                'b.accruedTotal',
                'b.totalPayable',
                'b.payed',
                'b.remaining',
                'b.withheldForGap',
                'b.withheldForCommissions',
                'c.activity as activityId',
                'c.personName',
            ]);

        if ($params['search'] ?? false) {
            $s = '%' . mb_strtolower($params['search']) . '%';
            $q->whereRaw('LOWER(b."consultantPersonName") LIKE ?', [$s]);
        }
        if ($params['status'] ?? false) {
            $q->where('b.status', $params['status']);
        }
        if ($params['activity'] ?? false) {
            $q->where('c.activity', (int) $params['activity']);
        }
        if (! empty($params['nonZero'])) {
            $q->where(function ($qq) {
                $qq->where('b.accruedTotal', '!=', 0)
                   ->orWhere('b.totalPayable', '!=', 0)
                   ->orWhere('b.balance', '!=', 0);
            });
        }
        // Фильтр «ФК с отрывом»: ищем партнёров, у которых в commission
        // за выбранный месяц есть строки с reduction > 0 (правило отрыва
        // 70% сработало). Раньше фильтр опирался на consultantBalance
        // .withheldForGap, но это поле в legacy-данных почти всегда NULL
        // (только 22 строки из 36810). reduction живёт прямо в commission
        // и заполнен корректно (7687 строк).
        // Используем денормализованные dateYear/dateMonth (есть индекс
        // commission_dateyear_datemonth_idx), а не whereYear/whereMonth —
        // последние делают seq-scan по всему commission (533k строк).
        $dm = sprintf('%04d-%02d', $year, $month);
        if (! empty($params['withDetachment'])) {
            $q->whereIn('b.consultant', function ($sub) use ($dm) {
                $sub->select('consultant')->from('commission')
                    ->where('reduction', '>', 0)
                    ->whereNull('deletedAt')
                    ->where('dateMonth', $dm);
            });
        }
        // Аналогично для ОП — опираемся на withheldForCommission в commission
        // (это per-row penalty за невыполнение плана продаж).
        if (! empty($params['withOp'])) {
            $q->whereIn('b.consultant', function ($sub) use ($dm) {
                $sub->select('consultant')->from('commission')
                    ->where('withheldForCommission', '>', 0)
                    ->whereNull('deletedAt')
                    ->where('dateMonth', $dm);
            });
        }

        $rows = $q->orderByDesc('b.totalPayable')->limit(2000)->get();

        // Прочие начисления (other_accruals) — отдельная таблица для ручных
        // бонусов/штрафов, заведённых через /manage/charges. consultantBalance
        // её не агрегирует (это denormalized легаси-снимок Directual), так что
        // тянем сумму по месяцу батчем и складываем в колонку «Прочее».
        $periodFrom = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $periodTo = \Carbon\Carbon::parse($periodFrom)->endOfMonth()->format('Y-m-d 23:59:59');
        $extraByCons = DB::table('other_accruals')
            ->whereBetween('accrual_date', [$periodFrom, $periodTo])
            ->select('consultant', DB::raw('SUM(COALESCE(amount, 0)) as extra'))
            ->groupBy('consultant')
            ->pluck('extra', 'consultant');

        // Live SUM по commission и poolLog за месяц — на случай если
        // consultantBalance.accruedTransactional/accruedPool ещё не пересчитан
        // (Богданова: после ручной фиксации транзакции commission создан,
        // но в Реестре выплат «Начислено: 0», т.к. снимок ночной).
        // Если live-сумма больше снимка — используем её (новые транзакции),
        // иначе оставляем снимок (там может быть учтена логика отрыва и т.п.).
        $liveAccruedByCons = DB::table('commission')
            ->where('dateMonth', $dm)
            ->whereNull('deletedAt')
            ->select('consultant', DB::raw('SUM(COALESCE("amountRUB", 0)) as accrued'))
            ->groupBy('consultant')
            ->pluck('accrued', 'consultant');

        $livePoolByCons = DB::table('poolLog')
            ->whereBetween('date', [$periodFrom, $periodTo])
            ->select('consultant', DB::raw('SUM(COALESCE("poolBonus", 0)) as pool'))
            ->groupBy('consultant')
            ->pluck('pool', 'consultant');

        // Batch-load requisite verification for every partner in the result.
        $consultantIds = $rows->pluck('consultant')->filter()->unique()->values()->all();
        $verified = [];
        if ($consultantIds) {
            $verified = DB::table('requisites')
                ->whereIn('consultant', $consultantIds)
                ->whereNull('deletedAt')
                ->where('verified', true)
                ->pluck('consultant', 'consultant')
                ->toArray();
        }

        // Activity name lookup for partner-status filter UI.
        $activityNames = DB::table('directory_of_activities')->pluck('name', 'id');

        $items = $rows->map(function ($r) use ($verified, $activityNames, $extraByCons, $liveAccruedByCons, $livePoolByCons) {
            // accrued = max(снимок, live SUM commission за месяц). Прирост от
            // ручной фиксации транзакции (commission уже есть, снимок ещё нет)
            // подхватывается тут же.
            $snapshotAccrued = (float) ($r->accruedTransactional ?? 0);
            $liveAccrued = (float) ($liveAccruedByCons[$r->consultant] ?? 0);
            $accrued = max($snapshotAccrued, $liveAccrued);

            $snapshotPool = (float) ($r->accruedPool ?? 0);
            $livePool = (float) ($livePoolByCons[$r->consultant] ?? 0);
            $pool = max($snapshotPool, $livePool);

            $extra = (float) ($extraByCons[$r->consultant] ?? 0);
            $other = (float) ($r->accruedNonTransactional ?? 0) + $extra;
            $accruedTotal = $accrued + $other + $pool;
            $balance = (float) ($r->balance ?? 0);
            $totalPayable = $balance + $accruedTotal;
            $payed = (float) ($r->payed ?? 0);
            $remaining = $totalPayable - $payed;
            return [
                'id' => $r->id,
                'consultantId' => $r->consultant,
                'personName' => $r->consultantPersonName ?? $r->personName ?? '—',
                'activityId' => $r->activityId,
                'activityName' => $r->activityId ? ($activityNames[$r->activityId] ?? null) : null,
                'status' => $r->status,
                'balance' => $balance,
                'accrued' => $accrued,
                'other' => $other,
                'pool' => $pool,
                'accruedTotal' => $accruedTotal,
                'totalPayable' => $totalPayable,
                'payed' => $payed,
                'remaining' => $remaining,
                'withheldForGap' => (float) ($r->withheldForGap ?? 0),
                'withheldForCommissions' => (float) ($r->withheldForCommissions ?? 0),
                'verifiedRequisites' => isset($verified[$r->consultant]),
            ];
        });

        // Totals агрегируем из items (которые уже содержат live-корректировки),
        // а не из исходных rows — иначе цифры в шапке расходятся со строками.
        $totals = [
            'rows' => $items->count(),
            'balance' => (float) $items->sum('balance'),
            'accruedBeforeGap' => (float) $items->sum('accrued') + (float) $rows->sum('withheldForGap'),
            'accruedTransactional' => (float) $items->sum('accrued'),
            'accruedNonTransactional' => (float) $items->sum('other'),
            'accruedPool' => (float) $items->sum('pool'),
            'accruedTotal' => (float) $items->sum('accruedTotal'),
            'totalPayable' => (float) $items->sum('totalPayable'),
            'payed' => (float) $items->sum('payed'),
            'remaining' => (float) $items->sum('remaining'),
            'withheldForGap' => (float) $rows->sum('withheldForGap'),
            'withheldForCommissions' => (float) $rows->sum('withheldForCommissions'),
        ];

        return response()->json([
            'year' => $year,
            'month' => $month,
            'items' => $items,
            'totals' => $totals,
            'activityOptions' => $activityNames->map(fn ($name, $id) => ['title' => $name, 'value' => $id])->values(),
        ]);
    }

    /** GET /admin/payment-registry/{id}/requisites — для попапа реквизитов в строке. */
    public function requisites(int $id): JsonResponse
    {
        $balance = DB::table('consultantBalance')->where('id', $id)->first();
        if (! $balance) {
            return response()->json(['message' => 'Запись не найдена'], 404);
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
    public function updatePayment(Request $request, int $paymentId): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|integer|in:1,2,3',
            'comment' => 'nullable|string|max:500',
        ]);

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
    public function addPayment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'comment' => 'nullable|string|max:500',
        ]);

        $balance = DB::table('consultantBalance')->where('id', $id)->first();
        if (! $balance) {
            return response()->json(['message' => 'Запись не найдена'], 404);
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
