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
                // Person data (birthDate, city, phone, email)
                $person = $c->person
                    ? DB::table('person')->where('id', $c->person)->first()
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

                return [
                    'id' => $c->id,
                    'personName' => $c->personName,
                    'birthDate' => $person->birthDate ?? null,
                    'city' => $cityName,
                    'phone' => $person->phone ?? null,
                    'email' => $person->email ?? null,
                    'products' => $products,
                ];
            });

        return response()->json(['data' => $clients, 'total' => $total]);
    }
}
