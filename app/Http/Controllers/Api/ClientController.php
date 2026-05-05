<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientListItemResource;
use App\Models\Client;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    use PaginatesRequests;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        // Soft-deleted клиентов в «Мои клиенты» НЕ показываем. Раньше
        // не было фильтра — поэтому в UI вылезали 15 пустых строк
        // (12 заброшенных draft'ов удалены 10.02.2025, 3 — настоящих
        // удаления). Badge у Саляхутдинова показывал 165 вместо 150.
        $query = Client::where('consultant', $consultant->id)
            ->whereNull('dateDeleted');

        if ($request->filled('search')) {
            $query->where('personName', 'ilike', '%' . $request->input('search') . '%');
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'active') {
                $query->where('active', true);
            } elseif ($status === 'inactive') {
                $query->where('active', false);
            }
        }

        $hasPersonFilter = $request->filled('email')
            || $request->filled('birth_date_from')
            || $request->filled('birth_date_to')
            || $request->filled('city');

        if ($hasPersonFilter) {
            $personQuery = DB::table('person')->select('person.id');
            if ($request->filled('email')) {
                $personQuery->where('email', 'ilike', '%' . $request->input('email') . '%');
            }
            if ($request->filled('birth_date_from')) {
                $personQuery->where('birthDate', '>=', $request->input('birth_date_from'));
            }
            if ($request->filled('birth_date_to')) {
                $personQuery->where('birthDate', '<=', $request->input('birth_date_to'));
            }
            if ($request->filled('city')) {
                // person.city is text (legacy Directual), city.id is integer — cast on FK side
                $personQuery->join('city', DB::raw('"city"."id"::text'), '=', 'person.city')
                    ->where('city.cityNameRu', 'ilike', '%' . $request->input('city') . '%');
            }
            $query->whereIn('person', $personQuery->pluck('person.id'));
        }

        $total = $query->count();

        $sortBy = $request->input('sort_by', 'personName');
        $sortDir = $request->input('sort_dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['personName', 'id'];
        $query->orderBy(in_array($sortBy, $allowedSort) ? $sortBy : 'personName', $sortDir);

        $clientRows = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        // Batch load person data
        $personIds = $clientRows->pluck('person')->filter()->unique();
        $persons = $personIds->isNotEmpty()
            ? DB::table('person')->whereIn('id', $personIds)->get()->keyBy('id')
            : collect();

        $cityIds = $persons->pluck('city')->filter()->unique();
        $cities = $cityIds->isNotEmpty()
            ? DB::table('city')->whereIn('id', $cityIds)->pluck('cityNameRu', 'id')
            : collect();

        // Per spec ✅Мои клиенты.md: «Открытые продукты» — список названий
        // активных продуктов всех контрактов клиента.
        $clientIds = $clientRows->pluck('id')->all();
        $productsByClient = [];
        if (! empty($clientIds)) {
            $contractRows = DB::table('contract')
                ->whereIn('client', $clientIds)
                ->whereNull('deletedAt')
                ->get(['client', 'productName']);
            foreach ($contractRows as $r) {
                if (! $r->productName) continue;
                $productsByClient[$r->client] = $productsByClient[$r->client] ?? [];
                if (! in_array($r->productName, $productsByClient[$r->client], true)) {
                    $productsByClient[$r->client][] = $r->productName;
                }
            }
        }

        $items = $clientRows->map(function ($c) use ($persons, $cities, $productsByClient) {
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
                'active' => (bool) $c->active,
                'products' => $productsByClient[$c->id] ?? [],
            ];
        });

        return response()->json([
            'data' => ClientListItemResource::collection($items),
            'total' => $total,
        ]);
    }
}
