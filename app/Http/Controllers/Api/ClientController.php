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
                // Try multiple sources for person data:
                // 1. client.person → person table
                // 2. client.person → WebUser table (person and WebUser share IDs in some cases)
                // 3. Fallback: search WebUser by email match with personName
                $personData = null;
                $cityName = null;

                if ($c->person) {
                    // Try person table first
                    $personData = DB::table('person')->where('id', $c->person)->first();

                    // If person table has no contact data, try WebUser
                    if (! $personData || (! $personData->email && ! $personData->phone)) {
                        $webUserData = DB::table('WebUser')->where('id', $c->person)->first();
                        if ($webUserData && ($webUserData->email || $webUserData->phone)) {
                            $personData = $webUserData;
                        }
                    }
                }

                // Get city from person data
                if ($personData) {
                    $cityId = $personData->city ?? null;
                    if ($cityId) {
                        $cityName = DB::table('city')->where('id', $cityId)->value('cityNameRu');
                    }
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
