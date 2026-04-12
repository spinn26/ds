<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    /**
     * Список клиентов партнёра.
     * По спеке: ФИО, дата рождения, место жительства, телефон, email, открытые продукты.
     * Убрано: кнопка добавить, капитал, активность, статус, последний контакт.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Client::where('consultant', $consultant->id);

        // Фильтр по ФИО
        if ($request->filled('search')) {
            $query->where('personName', 'ilike', '%' . $request->search . '%');
        }

        $total = $query->count();

        $clients = $query
            ->orderByDesc('id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(function ($c) {
                // Person data from WebUser (birthDate, city, phone, email)
                $person = $c->person
                    ? DB::table('WebUser')->where('id', $c->person)->first()
                    : null;

                $cityName = $person && $person->city
                    ? DB::table('city')->where('id', $person->city)->value('cityNameRu')
                    : null;

                // Open products via contracts
                $products = DB::table('contract')
                    ->where('client', $c->id)
                    ->whereNull('deletedAt')
                    ->whereNotNull('product')
                    ->join('product', 'contract.product', '=', 'product.id')
                    ->distinct()
                    ->pluck('product.name')
                    ->toArray();

                // Contract count
                $contractCount = DB::table('contract')
                    ->where('client', $c->id)
                    ->whereNull('deletedAt')
                    ->count();

                // Is partner — check if person exists in consultant table
                $isPartner = $c->person
                    ? DB::table('consultant')->where('person', $c->person)->whereNull('dateDeleted')->exists()
                    : false;

                return [
                    'id' => $c->id,
                    'dsId' => $c->idDs,
                    'personName' => $c->personName,
                    'birthDate' => $person?->birthDate ?? null,
                    'city' => $cityName,
                    'phone' => $person?->phone ?? null,
                    'email' => $person?->email ?? null,
                    'products' => $products,
                    'workSince' => $c->workSince?->format('d.m.Y'),
                    'contractCount' => $contractCount,
                    'isPartner' => $isPartner,
                    'comment' => $c->comment,
                    'consultantName' => $c->consultantName,
                ];
            });

        return response()->json(['data' => $clients, 'total' => $total]);
    }
}
