<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\Contract;
use App\Services\ConsultantService;
use App\Services\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractController extends Controller
{
    public function __construct(
        private readonly ContractService $contractService,
        private readonly ConsultantService $consultantService,
    ) {}

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

        $this->contractService->applyContractFilters($query, $request);

        $total = $query->count();

        $contractRows = $query
            ->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        $contracts = $this->contractService->formatContracts($contractRows);

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

        $teamIds = $this->consultantService->getTeamIds($consultant->id);

        $query = Contract::whereIn('consultant', $teamIds)
            ->whereNull('deletedAt');

        $this->contractService->applyContractFilters($query, $request);

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

        $contracts = $this->contractService->formatContracts($contractRows, true);

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
}
