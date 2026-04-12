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
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = Client::where('consultant', $consultant->id);

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
                $personData = null;
                $cityName = null;

                // client.person → person table (данные клиента: телефон, email, ДР, город)
                if ($c->person) {
                    $personData = DB::table('WebUser')->where('id', $c->person)->first();
                }

                // Город из person.city → city.cityNameRu
                if ($personData && ($personData->city ?? null)) {
                    $cityName = DB::table('city')->where('id', $personData->city)->value('cityNameRu');
                }

                return [
                    'id' => $c->id,
                    'personName' => $c->personName,
                    'birthDate' => $personData?->birthDate ?? null,
                    'city' => $cityName,
                    'phone' => $personData?->phone ?? null,
                    'email' => $personData?->email ?? null,
                ];
            });

        return response()->json(['data' => $clients, 'total' => $total]);
    }
}
