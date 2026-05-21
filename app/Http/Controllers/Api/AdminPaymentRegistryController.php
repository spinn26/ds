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

        // Сумма прочих начислений из new-таблицы — для тоталов снизу/сверху.
        $extraTotal = (float) $extraByCons->sum();

        // Dashboard totals (same filters minus pagination limit).
        $totals = [
            'rows' => $rows->count(),
            'balance' => (float) $rows->sum('balance'),
            // Spec ✅Реестр выплат §1.2: «Начислено за транзакции до уменьшения по отрыву»
            'accruedBeforeGap' => (float) $rows->sum('accruedTransactional')
                + (float) $rows->sum('withheldForGap'),
            'accruedTransactional' => (float) $rows->sum('accruedTransactional'),
            'accruedNonTransactional' => (float) $rows->sum('accruedNonTransactional') + $extraTotal,
            'accruedPool' => (float) $rows->sum('accruedPool'),
            'accruedTotal' => (float) $rows->sum('accruedTotal') + $extraTotal,
            'totalPayable' => (float) $rows->sum('totalPayable') + $extraTotal,
            'payed' => (float) $rows->sum('payed'),
            'remaining' => (float) $rows->sum('remaining') + $extraTotal,
            'withheldForGap' => (float) $rows->sum('withheldForGap'),
            'withheldForCommissions' => (float) $rows->sum('withheldForCommissions'),
        ];

        $items = $rows->map(function ($r) use ($verified, $activityNames, $extraByCons) {
            $extra = (float) ($extraByCons[$r->consultant] ?? 0);
            $other = (float) ($r->accruedNonTransactional ?? 0) + $extra;
            $accruedTotal = (float) ($r->accruedTotal ?? 0) + $extra;
            $totalPayable = (float) ($r->totalPayable ?? 0) + $extra;
            $remaining = (float) ($r->remaining ?? 0) + $extra;
            return [
                'id' => $r->id,
                'consultantId' => $r->consultant,
                'personName' => $r->consultantPersonName ?? $r->personName ?? '—',
                'activityId' => $r->activityId,
                'activityName' => $r->activityId ? ($activityNames[$r->activityId] ?? null) : null,
                'status' => $r->status,
                'balance' => (float) ($r->balance ?? 0),
                'accrued' => (float) ($r->accruedTransactional ?? 0),
                'other' => $other,
                'pool' => (float) ($r->accruedPool ?? 0),
                'accruedTotal' => $accruedTotal,
                'totalPayable' => $totalPayable,
                'payed' => (float) ($r->payed ?? 0),
                'remaining' => $remaining,
                'withheldForGap' => (float) ($r->withheldForGap ?? 0),
                'withheldForCommissions' => (float) ($r->withheldForCommissions ?? 0),
                'verifiedRequisites' => isset($verified[$r->consultant]),
            ];
        });

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
