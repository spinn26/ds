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

        $contracts = $query
            ->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($c) => $this->formatContract($c));

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

        $teamIds = DB::table('consultantStructure')
            ->where('parent', $consultant->id)
            ->pluck('child')
            ->toArray();
        $teamIds[] = $consultant->id;

        $query = Contract::whereIn('consultant', $teamIds)
            ->whereNull('deletedAt');

        $this->applyContractFilters($query, $request);

        // Фильтр по ФИО ФК
        if ($request->filled('consultant_search')) {
            $query->where('consultantName', 'ilike', '%' . $request->consultant_search . '%');
        }

        $total = $query->count();

        $contracts = $query
            ->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($c) => $this->formatContract($c, true));

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

    private function formatContract(Contract $c, bool $includeConsultant = false): array
    {
        $statusName = $c->status
            ? DB::table('contractStatus')->where('id', $c->status)->value('name')
            : null;
        $currencyName = $c->currency
            ? DB::table('currency')->where('id', $c->currency)->value('symbol')
            : null;

        // Vendor/provider names from program table
        $vendorName = null;
        $providerName = null;
        if ($c->program) {
            $program = DB::table('program')->where('id', $c->program)->first();
            if ($program) {
                $vendorName = $program->vendorName
                    ?? ($program->vendor ? DB::table('counterparty')->where('id', $program->vendor)->value('counterpartyName') : null);
                $providerName = $program->providerName
                    ?? ($program->provider ? DB::table('counterparty')->where('id', $program->provider)->value('counterpartyName') : null);
            }
        }

        // Points accrual status — check if commissions exist for this contract's transactions
        $hasPoints = DB::table('commission')
            ->whereIn('transaction', function ($q) use ($c) {
                $q->select('id')->from('transaction')->where('contract', $c->id)->whereNull('deletedAt');
            })
            ->whereNull('deletedAt')
            ->exists();

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
    }
}
