<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContractService
{
    /**
     * Apply common contract filters to a query builder.
     */
    public function applyContractFilters($query, Request $request): void
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
    public function formatContracts(Collection $contracts, bool $includeConsultant = false): Collection
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
