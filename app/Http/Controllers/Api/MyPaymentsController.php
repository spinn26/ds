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

        // Сальдо = remaining прошлого периода (как в AdminPaymentRegistryController)
        $incoming = (float) (DB::table('consultantBalance')
            ->where('consultant', $consultant->id)
            ->where('dateMonth', '<', $dm)
            ->orderByDesc('dateMonth')
            ->value('remaining') ?? 0);

        // Ручные начисления (other_accruals) — читаем live, не из снимка
        $periodFrom = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $periodTo   = \Carbon\Carbon::parse($periodFrom)->endOfMonth()->format('Y-m-d 23:59:59');
        $extra = (float) (DB::table('other_accruals')
            ->where('consultant', $consultant->id)
            ->whereBetween('accrual_date', [$periodFrom, $periodTo])
            ->sum('amount') ?? 0);

        // Только снимок (как в AdminPaymentRegistryController — live-пересчёт убран 2026-06-05)
        $accrued      = (float) ($balance?->accruedTransactional ?? 0);
        $pool         = (float) ($balance?->accruedPool ?? 0);
        $other        = (float) ($balance?->accruedNonTransactional ?? 0) + $extra;
        $accruedTotal = $accrued + $other + $pool;
        $totalPayable = $incoming + $accruedTotal;
        $payed        = (float) ($balance?->payed ?? 0);
        $remaining    = $totalPayable - $payed;

        // Краткая история по всем периодам (последние 12), для графика/таблицы
        $historyRows = DB::table('consultantBalance')
            ->where('consultant', $consultant->id)
            ->orderByDesc('dateMonth')
            ->limit(12)
            ->get(['dateMonth', 'accruedTransactional', 'accruedPool', 'accruedNonTransactional', 'payed', 'remaining', 'status']);

        $history = $historyRows->map(fn ($r) => [
            'dateMonth' => $r->dateMonth,
            'accrued'   => (float) ($r->accruedTransactional ?? 0),
            'pool'      => (float) ($r->accruedPool ?? 0),
            'other'     => (float) ($r->accruedNonTransactional ?? 0),
            'payed'     => (float) ($r->payed ?? 0),
            'remaining' => (float) ($r->remaining ?? 0),
            'status'    => $r->status,
        ])->values();

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
