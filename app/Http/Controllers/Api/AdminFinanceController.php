<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFinanceController extends Controller
{
    use PaginatesRequests;

    /** Транзакции */
    public function transactions(Request $request): JsonResponse
    {
        $query = DB::table('transaction')->whereNull('deletedAt');

        if ($request->filled('search')) {
            $query->where('id', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('month')) {
            $query->where('dateMonth', $request->month);
        }

        $total = $query->count();
        $rows = $query->orderByDesc('date')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        // Batch load currencies
        $currencyIds = $rows->pluck('currency')->filter()->unique();
        $currencies = $currencyIds->isNotEmpty()
            ? DB::table('currency')->whereIn('id', $currencyIds)->pluck('symbol', 'id')
            : collect();

        $data = $rows->map(fn ($t) => [
                'id' => $t->id,
                'contract' => $t->contract,
                'amount' => round((float) ($t->amount ?? 0), 2),
                'amountRUB' => round((float) ($t->amountRUB ?? 0), 2),
                'amountUSD' => round((float) ($t->amountUSD ?? 0), 2),
                'date' => $t->date,
                'currencySymbol' => $t->currency ? ($currencies[$t->currency] ?? null) : null,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Комиссии */
    public function commissions(Request $request): JsonResponse
    {
        $query = DB::table('commission')->whereNull('deletedAt');

        if ($request->filled('consultant')) {
            $query->where('consultant', $request->consultant);
        }
        if ($request->filled('month')) {
            $query->where('dateMonth', $request->month);
        }

        $total = $query->count();
        $rows = $query->orderByDesc('date')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        // Batch load consultant names
        $consultantIds = $rows->pluck('consultant')->filter()->unique();
        $consultantNames = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('personName', 'id')
            : collect();

        $data = $rows->map(fn ($c) => [
                'id' => $c->id,
                'consultant' => $c->consultant,
                'consultantName' => $c->consultant ? ($consultantNames[$c->consultant] ?? null) : null,
                'type' => $c->type,
                'amountRUB' => round((float) ($c->amountRUB ?? 0), 2),
                'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($c->groupVolume ?? 0), 2),
                'groupBonusRub' => round((float) ($c->groupBonusRub ?? 0), 2),
                'percent' => $c->percent,
                'date' => $c->date,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Пул */
    public function pool(Request $request): JsonResponse
    {
        $query = DB::table('poolLog');

        if ($request->filled('month')) {
            // filter by date range if month provided
            $start = $request->month . '-01';
            $end = date('Y-m-t', strtotime($start));
            $query->whereBetween('date', [$start, $end]);
        }

        $total = $query->count();
        $data = $query->orderByDesc('date')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Квалификации */
    public function qualifications(Request $request): JsonResponse
    {
        $query = DB::table('qualificationLog')
            ->whereNull('dateDeleted');

        if ($request->filled('search')) {
            $query->where('consultantPersonName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('month')) {
            $start = $request->month . '-01';
            $end = date('Y-m-t', strtotime($start));
            $query->whereBetween('date', [$start, $end]);
        }

        $total = $query->count();
        $data = $query->orderByDesc('date')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'consultant' => $q->consultant,
                'consultantName' => $q->consultantPersonName,
                'personalVolume' => round((float) ($q->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($q->groupVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($q->groupVolumeCumulative ?? 0), 2),
                'nominalLevel' => $q->nominalLevel,
                'calculationLevel' => $q->calculationLevel,
                'levelNew' => $q->levelNew,
                'levelPrevious' => $q->levelPrevious,
                'result' => $q->result,
                'date' => $q->date,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Прочие начисления — CRUD */
    public function charges(Request $request): JsonResponse
    {
        $query = DB::table('other_accruals')
            ->join('consultant', 'other_accruals.consultant', '=', 'consultant.id')
            ->select(
                'other_accruals.*',
                'consultant.personName as consultantName'
            );

        if ($request->filled('search')) {
            $query->where('consultant.personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('type')) {
            $query->where('other_accruals.type', $request->type);
        }

        $total = $query->count();
        $data = $query->orderByDesc('other_accruals.created_at')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'consultantName' => $r->consultantName,
                'consultant' => $r->consultant,
                'type' => $r->type,
                'amount' => round((float) $r->amount, 2),
                'points' => round((float) $r->points, 2),
                'comment' => $r->comment,
                'accrualDate' => $r->accrual_date,
                'createdAt' => $r->created_at,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /**
     * Создать начисление. Если в запросе есть баллы — они сразу
     * прибавляются к consultant.personalVolume и
     * consultant.groupVolumeCumulative (spec ✅Прочие начисления Part 2 §3).
     * Рубли (`amount`) остаются только в other_accruals и влияют на
     * финансовый баланс через агрегацию в реестре выплат.
     *
     * Баллы НЕ каскадятся по inviter-цепочке по прямому указанию спеки:
     * "не должны генерировать финансовую комиссию для вышестоящих
     * наставников, как это происходит при обычной продаже".
     */
    public function storeCharge(Request $request): JsonResponse
    {
        $request->validate([
            'consultant' => 'required|integer|exists:consultant,id',
            'type' => 'required|in:bonus,penalty,compensation',
            'amount' => 'required|numeric',
            'points' => 'nullable|numeric',
        ]);

        $consultantId = (int) $request->consultant;
        $points = (float) $request->input('points', 0);

        $id = DB::transaction(function () use ($request, $consultantId, $points) {
            $id = DB::table('other_accruals')->insertGetId([
                'consultant' => $consultantId,
                'type' => $request->type,
                'amount' => $request->amount,
                'points' => $points,
                'comment' => $request->comment,
                'accrual_date' => $request->input('accrual_date', now()),
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($points != 0.0) {
                DB::table('consultant')
                    ->where('id', $consultantId)
                    ->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) + {$points}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) + {$points}"),
                    ]);
            }

            return $id;
        });

        return response()->json(['message' => 'Начисление создано', 'id' => $id], 201);
    }

    /**
     * Удалить начисление с реверсивной транзакцией.
     * Per ✅Прочие начисления Part 2 §4: удаление должно откатить
     * изменения баланса (+100 баллов → удалили → −100 баллов обратно).
     */
    public function deleteCharge(int $id): JsonResponse
    {
        $row = DB::table('other_accruals')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Начисление не найдено'], 404);
        }

        DB::transaction(function () use ($row) {
            $points = (float) ($row->points ?? 0);

            if ($points != 0.0) {
                DB::table('consultant')
                    ->where('id', $row->consultant)
                    ->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) - {$points}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) - {$points}"),
                    ]);
            }

            DB::table('other_accruals')->where('id', $row->id)->delete();
        });

        return response()->json(['message' => 'Начисление удалено, баланс откатан']);
    }

    /** Реестр выплат */
    public function payments(Request $request): JsonResponse
    {
        $query = DB::table('consultantPayment')
            ->join('consultantBalance', 'consultantPayment.consultantBalance', '=', 'consultantBalance.id')
            ->join('consultant', 'consultantBalance.consultant', '=', 'consultant.id')
            ->select(
                'consultantPayment.id',
                'consultantPayment.amount',
                'consultantPayment.paymentDate',
                'consultantPayment.status',
                'consultantPayment.comment',
                'consultant.personName',
                'consultant.id as consultantId'
            );

        if ($request->filled('search')) {
            $query->where('consultant.personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('status')) {
            $query->where('consultantPayment.status', (int) $request->status);
        }

        $total = $query->count();
        // Postgres sorts NULLs first on DESC by default; half the legacy rows
        // have no paymentDate, so the first 25 were a wall of prochеrк's.
        // Push real dates to the top; fall back to id for deterministic order.
        $data = $query->orderByRaw('"paymentDate" DESC NULLS LAST')
            ->orderByDesc('consultantPayment.id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'consultantName' => $p->personName,
                'amount' => round((float) $p->amount, 2),
                'paymentDate' => $p->paymentDate,
                'status' => $p->status,
                'comment' => $p->comment,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Отчёты */
    public function reports(): JsonResponse
    {
        return response()->json(['data' => [], 'message' => 'В разработке']);
    }

    /** Доступность отчётов */
    public function reportAvailability(): JsonResponse
    {
        return response()->json(['data' => [], 'message' => 'В разработке']);
    }

    /** Валюты и НДС */
    public function currencies(): JsonResponse
    {
        $currencies = DB::table('currency')->orderBy('id')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                // Legacy schema has nameRu / nameEn / currencyName — no plain `name`.
                'name' => $c->nameRu ?? $c->nameEn ?? $c->currencyName ?? '',
                'symbol' => $c->symbol,
            ]);

        $vat = DB::table('vat')->orderBy('id')->get();

        return response()->json(['currencies' => $currencies, 'vat' => $vat]);
    }

    /** Импорт транзакций — placeholder */
    public function transactionImport(): JsonResponse
    {
        return response()->json(['data' => [], 'message' => 'В разработке']);
    }
}
