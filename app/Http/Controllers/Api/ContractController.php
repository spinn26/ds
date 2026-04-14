<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractController extends Controller
{
    use Concerns\HasTeamTree;
    /**
     * Контракты моих клиентов.
     * Фильтры: ФИО клиента, статус контракта, дата открытия, продукт.
     * Таблица: номер, ФИО клиента, дата открытия, продукт, программа, срок, сумма+валюта, статус.
     */
    public function myContracts(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Contract::where('consultant', $consultant->id)
            ->whereNull('deletedAt');

        $this->applyContractFilters($query, $request);

        $total = $query->count();

        $contractRows = $query
            ->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        $contracts = $this->formatContracts($contractRows);

        return response()->json(['data' => $contracts, 'total' => $total]);
    }

    /**
     * Контракты команды.
     * Дополнительно: фильтр ФИО ФК + колонка ФИО ФК.
     */
    public function teamContracts(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $teamIds = $this->getTeamIds($consultant->id);

        $query = Contract::whereIn('consultant', $teamIds)
            ->whereNull('deletedAt');

        $this->applyContractFilters($query, $request);

        // Фильтр по ФИО ФК
        if ($request->filled('consultant_search')) {
            $query->where('consultantName', 'ilike', '%' . $request->consultant_search . '%');
        }

        $total = $query->count();

        $contractRows = $query
            ->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        $contracts = $this->formatContracts($contractRows, true);

        return response()->json(['data' => $contracts, 'total' => $total]);
    }

    /**
     * Список статусов контрактов (для фильтра).
     */
    public function statuses(): JsonResponse
    {
        $statuses = DB::table('contractStatus')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);

        return response()->json($statuses);
    }

    /**
     * Список продуктов (для фильтра-автокомплита).
     */
    public function products(Request $request): JsonResponse
    {
        $query = DB::table('product')->where('active', true);

        if ($request->filled('q')) {
            $query->where('name', 'ilike', '%' . $request->q . '%');
        }

        $products = $query->orderBy('name')->limit(20)
            ->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]);

        return response()->json($products);
    }

    private function applyContractFilters($query, Request $request): void
    {
        // ФИО клиента
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('clientName', 'ilike', '%' . $request->search . '%')
                  ->orWhere('number', 'ilike', '%' . $request->search . '%');
            });
        }

        // Статус контракта
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Продукт
        if ($request->filled('product')) {
            $query->where('product', $request->product);
        }

        // Дата открытия — диапазон
        if ($request->filled('date_from')) {
            $query->where('openDate', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('openDate', '<=', $request->date_to);
        }
    }

    /**
     * Batch-format a collection of contracts (avoids N+1 queries).
     */
    private function formatContracts($contracts, bool $includeConsultant = false)
    {
        if ($contracts->isEmpty()) {
            return $contracts;
        }

        // Batch load statuses
        $statusIds = $contracts->pluck('status')->filter()->unique();
        $statuses = $statusIds->isNotEmpty()
            ? DB::table('contractStatus')->whereIn('id', $statusIds)->pluck('name', 'id')
            : collect();

        // Batch load currencies
        $currencyIds = $contracts->pluck('currency')->filter()->unique();
        $currencies = $currencyIds->isNotEmpty()
            ? DB::table('currency')->whereIn('id', $currencyIds)->pluck('symbol', 'id')
            : collect();

        // Batch load programs
        $programIds = $contracts->pluck('program')->filter()->unique();
        $programs = $programIds->isNotEmpty()
            ? DB::table('program')->whereIn('id', $programIds)->get()->keyBy('id')
            : collect();

        // Batch load counterparties for vendor/provider resolution
        $counterpartyIds = collect();
        foreach ($programs as $program) {
            if (! ($program->vendorName ?? null) && ($program->vendor ?? null)) {
                $counterpartyIds->push($program->vendor);
            }
            if (! ($program->providerName ?? null) && ($program->provider ?? null)) {
                $counterpartyIds->push($program->provider);
            }
        }
        $counterpartyIds = $counterpartyIds->filter()->unique();
        $counterparties = $counterpartyIds->isNotEmpty()
            ? DB::table('counterparty')->whereIn('id', $counterpartyIds)->pluck('counterpartyName', 'id')
            : collect();

        // Batch check points accrual: get contract IDs that have commissions via transactions
        $contractIds = $contracts->pluck('id')->filter()->unique();
        $txByContract = DB::table('transaction')
            ->whereIn('contract', $contractIds)
            ->whereNull('deletedAt')
            ->select('id', 'contract')
            ->get();
        $txIds = $txByContract->pluck('id')->filter()->unique();
        $contractsWithPoints = collect();
        if ($txIds->isNotEmpty()) {
            $commissionsExist = DB::table('commission')
                ->whereIn('transaction', $txIds)
                ->whereNull('deletedAt')
                ->select('transaction')
                ->distinct()
                ->pluck('transaction');
            $txContractMap = $txByContract->pluck('contract', 'id');
            foreach ($commissionsExist as $txId) {
                $cId = $txContractMap[$txId] ?? null;
                if ($cId) {
                    $contractsWithPoints[$cId] = true;
                }
            }
        }

        return $contracts->map(function ($c) use ($statuses, $currencies, $programs, $counterparties, $contractsWithPoints, $includeConsultant) {
            $statusName = $c->status ? ($statuses[$c->status] ?? null) : null;
            $currencyName = $c->currency ? ($currencies[$c->currency] ?? null) : null;

            $vendorName = null;
            $providerName = null;
            if ($c->program) {
                $program = $programs[$c->program] ?? null;
                if ($program) {
                    $vendorName = $program->vendorName
                        ?? ($program->vendor ? ($counterparties[$program->vendor] ?? null) : null);
                    $providerName = $program->providerName
                        ?? ($program->provider ? ($counterparties[$program->provider] ?? null) : null);
                }
            }

            $hasPoints = isset($contractsWithPoints[$c->id]);

            $data = [
                'id' => $c->id,
                'number' => $c->number,
                'clientName' => $c->clientName,
                'productName' => $c->productName,
                'programName' => $c->programName,
                'term' => $c->term ?? null,
                'statusName' => $statusName,
                'ammount' => $c->ammount,
                'currencySymbol' => $currencyName,
                'openDate' => $c->openDate?->format('d.m.Y'),
                'createDate' => $c->createDate?->format('d.m.Y'),
                'vendorName' => $vendorName,
                'providerName' => $providerName,
                'counterpartyContractId' => $c->counterpartyContractId,
                'comment' => $c->comment,
                'pointsStatus' => $hasPoints ? 'accrued' : 'pending',
            ];

            if ($includeConsultant) {
                $data['consultantName'] = $c->consultantName;
            }

            return $data;
        });
    }
}
