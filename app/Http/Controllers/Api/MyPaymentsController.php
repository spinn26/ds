<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Реестр выплат партнёра — read-only вид своих собственных начислений и выплат.
 * Данные те же, что показываются в admin/manage/payments, но только за
 * текущего пользователя и только на просмотр.
 */
class MyPaymentsController extends Controller
{
    /** GET /my-payments?year=&month= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();
        if (! $consultant) {
            return response()->json(['summary' => null, 'payments' => [], 'history' => []]);
        }

        $year  = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);
        $dm    = sprintf('%04d-%02d', $year, $month);

        $balance = DB::table('consultantBalance')
            ->where('consultant', $consultant->id)
            ->where(function ($q) use ($dm, $year, $month) {
                $q->where('dateMonth', $dm)
                  ->orWhere(function ($qq) use ($year, $month) {
                      $qq->where('dateYear', (string) $year)
                         ->where('dateMonth', sprintf('%02d', $month));
                  });
            })
            ->first();

        // Статусы платежей для расшифровки
        $statuses = DB::table('consultantPaymentStatus')->pluck('title', 'id');

        $payments = [];
        if ($balance) {
            $payments = DB::table('consultantPayment')
                ->where('consultantBalance', $balance->id)
                ->orderByDesc('paymentDate')
                ->orderByDesc('id')
                ->get()
                ->map(fn ($p) => [
                    'id'          => $p->id,
                    'amount'      => (float) $p->amount,
                    'paymentDate' => $p->paymentDate,
                    'status'      => $p->status,
                    'statusName'  => $statuses[$p->status] ?? null,
                    'comment'     => $p->comment,
                ])
                ->toArray();
        }

        // История по периодам + входящее сальдо выбранного месяца.
        //
        // ⚠ «Остаток» НЕ берём из снимка: consultantBalance.remaining у месяцев,
        // строку за которые создала платформа, считался без входящего сальдо
        // (в снимке balance=0) — партнёр видел остаток одного месяца вместо
        // накопленного. Считаем цепочку так же, как реестр выплат:
        //     остаток = входящее сальдо + начислено(тр. + прочее + пул) − оплачено
        // где входящее сальдо = остаток предыдущего периода. Идём от самого
        // первого периода партнёра, поэтому цепочка не зависит от того, какие
        // строки снимка успели пересчитать.
        $allRows = DB::table('consultantBalance')
            ->where('consultant', $consultant->id)
            ->where('dateMonth', 'like', '____-__')
            ->orderBy('dateMonth')
            ->get(['dateMonth', 'accruedTransactional', 'accruedPool', 'accruedNonTransactional', 'payed', 'status']);

        // Ручные начисления (other_accruals) — читаем live, не из снимка.
        $extraByMonth = DB::table('other_accruals')
            ->where('consultant', $consultant->id)
            ->selectRaw("to_char(accrual_date, 'YYYY-MM') as ym, SUM(COALESCE(amount, 0)) as extra")
            ->groupBy(DB::raw("to_char(accrual_date, 'YYYY-MM')"))
            ->pluck('extra', 'ym');

        $running  = 0.0;
        $incoming = 0.0;   // остаток последнего периода СТРОГО ДО выбранного
        $history  = $allRows->map(function ($r) use (&$running, &$incoming, $extraByMonth, $dm) {
            $accrued = (float) ($r->accruedTransactional ?? 0);
            $pool    = (float) ($r->accruedPool ?? 0);
            $other   = (float) ($r->accruedNonTransactional ?? 0)
                + (float) ($extraByMonth[$r->dateMonth] ?? 0);
            $payed   = (float) ($r->payed ?? 0);

            $running += $accrued + $pool + $other - $payed;

            if ($r->dateMonth < $dm) {
                $incoming = $running;
            }

            return [
                'dateMonth' => $r->dateMonth,
                'accrued'   => $accrued,
                'pool'      => $pool,
                'other'     => $other,
                'payed'     => $payed,
                'remaining' => round($running, 2),
                'status'    => $r->status,
            ];
        })->reverse()->take(12)->values();

        $incoming = round($incoming, 2);

        // Начисления выбранного месяца — только снимок (live-пересчёт убран
        // 2026-06-05), плюс live-чтение введённых вручную other_accruals.
        $extra        = (float) ($extraByMonth[$dm] ?? 0);
        $accrued      = (float) ($balance?->accruedTransactional ?? 0);
        $pool         = (float) ($balance?->accruedPool ?? 0);
        $other        = (float) ($balance?->accruedNonTransactional ?? 0) + $extra;
        $accruedTotal = $accrued + $other + $pool;
        $totalPayable = $incoming + $accruedTotal;
        $payed        = (float) ($balance?->payed ?? 0);
        $remaining    = round($totalPayable - $payed, 2);

        return response()->json([
            'year'    => $year,
            'month'   => $month,
            'summary' => [
                'balance'      => $incoming,
                'accrued'      => $accrued,
                'other'        => $other,
                'pool'         => $pool,
                'accruedTotal' => $accruedTotal,
                'totalPayable' => $totalPayable,
                'payed'        => $payed,
                'remaining'    => $remaining,
                'status'       => $balance?->status,
            ],
            'payments' => $payments,
            'history'  => $history,
        ]);
    }
}
