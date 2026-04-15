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

        if ($request->filled('email') || $request->filled('birth_date_from') || $request->filled('birth_date_to')) {
            $personQuery = DB::table('person')->select('id');
            if ($request->filled('email')) {
                $personQuery->where('email', 'ilike', '%' . $request->email . '%');
            }
            if ($request->filled('birth_date_from')) {
                $personQuery->where('birthDate', '>=', $request->birth_date_from);
            }
            if ($request->filled('birth_date_to')) {
                $personQuery->where('birthDate', '<=', $request->birth_date_to);
            }
            $query->whereIn('person', $personQuery->pluck('id'));
        }

        $total = $query->count();

        // Server-side sorting
        $sortBy = $request->input('sort_by', 'personName');
        $sortDir = $request->input('sort_dir', 'asc');
        $allowedSort = ['personName', 'id'];
        if (in_array($sortBy, $allowedSort)) {
            $query->orderBy($sortBy, $sortDir === 'desc' ? 'desc' : 'asc');
        } else {
            $query->orderBy('personName', $sortDir === 'desc' ? 'desc' : 'asc');
        }

        $clientRows = $query
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        // Batch load person data
        $personIds = $clientRows->pluck('person')->filter()->unique();
        $persons = $personIds->isNotEmpty()
            ? DB::table('person')->whereIn('id', $personIds)->get()->keyBy('id')
            : collect();

        // Batch load cities from person data
        $cityIds = $persons->pluck('city')->filter()->unique();
        $cities = $cityIds->isNotEmpty()
            ? DB::table('city')->whereIn('id', $cityIds)->pluck('cityNameRu', 'id')
            : collect();

        $clients = $clientRows->map(function ($c) use ($persons, $cities) {
                $personData = $c->person ? ($persons[$c->person] ?? null) : null;
                $cityName = ($personData && ($personData->city ?? null))
                    ? ($cities[$personData->city] ?? null)
                    : null;

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
