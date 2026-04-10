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
    public function myContracts(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('person', $user->id)->first();

        if (! $consultant) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Contract::where('consultant', $consultant->id);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('clientName', 'ilike', '%' . $request->search . '%')
                  ->orWhere('number', 'ilike', '%' . $request->search . '%');
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $total = $query->count();

        $contracts = $query
            ->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'number' => $c->number,
                'clientName' => $c->clientName,
                'consultantName' => $c->consultantName,
                'productName' => $c->productName,
                'programName' => $c->programName,
                'statusName' => DB::table('contractStatus')->where('id', $c->status)->value('name'),
                'ammount' => $c->ammount,
                'currencySymbol' => DB::table('currency')->where('id', $c->currency)->value('symbol'),
                'openDate' => $c->openDate?->format('d.m.Y'),
                'closeDate' => $c->closeDate?->format('d.m.Y'),
            ]);

        return response()->json(['data' => $contracts, 'total' => $total]);
    }

    public function teamContracts(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('person', $user->id)->first();

        if (! $consultant) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $teamIds = DB::table('consultantStructure')
            ->where('parent', $consultant->id)
            ->pluck('child')
            ->toArray();
        $teamIds[] = $consultant->id;

        $query = Contract::whereIn('consultant', $teamIds);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('clientName', 'ilike', '%' . $request->search . '%')
                  ->orWhere('consultantName', 'ilike', '%' . $request->search . '%')
                  ->orWhere('number', 'ilike', '%' . $request->search . '%');
            });
        }

        $total = $query->count();

        $contracts = $query
            ->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'number' => $c->number,
                'clientName' => $c->clientName,
                'consultantName' => $c->consultantName,
                'productName' => $c->productName,
                'programName' => $c->programName,
                'statusName' => DB::table('contractStatus')->where('id', $c->status)->value('name'),
                'ammount' => $c->ammount,
                'currencySymbol' => DB::table('currency')->where('id', $c->currency)->value('symbol'),
                'openDate' => $c->openDate?->format('d.m.Y'),
            ]);

        return response()->json(['data' => $contracts, 'total' => $total]);
    }
}
