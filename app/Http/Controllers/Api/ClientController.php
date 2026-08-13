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

        // Фильтры — по СОБСТВЕННЫМ полям карточки. Раньше искали через person,
        // и партнёр получал в выдаче чужие контакты: указатель мог вести на
        // другого человека (инцидент 2026-08-12, в админке фолбэк уже снят).
        if ($request->filled('email')) {
            $query->where('email', 'ilike', '%' . $request->input('email') . '%');
        }
        if ($request->filled('birth_date_from')) {
            $query->where('birthDate', '>=', $request->input('birth_date_from'));
        }
        if ($request->filled('birth_date_to')) {
            $query->where('birthDate', '<=', $request->input('birth_date_to'));
        }
        if ($request->filled('city')) {
            // client.city хранит и название (форма клиента), и legacy-id города,
            // поэтому ищем по обоим: по тексту напрямую и по коду через city.
            $cityLike = '%' . $request->input('city') . '%';
            $query->where(function ($q) use ($cityLike) {
                $q->where('city', 'ilike', $cityLike)
                    ->orWhereIn('city', function ($sub) use ($cityLike) {
                        $sub->from('city')->select(DB::raw('"id"::text'))
                            ->where('cityNameRu', 'ilike', $cityLike);
                    });
            });
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

        $cityIds = $clientRows->pluck('city')->filter()->unique();
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

        $items = $clientRows->map(function ($c) use ($cities, $productsByClient) {
            // Клиент владеет своими контактами, фолбэк на person снят.
            $cityCode = $c->city;
            // Legacy-код города резолвим через таблицу city, но форма клиента
            // сохраняет НАЗВАНИЕ (Cyrillic) — тогда берём как есть.
            $cityName = $cityCode
                ? (is_numeric($cityCode) ? ($cities[$cityCode] ?? null) : $cityCode)
                : null;

            return [
                'id' => $c->id,
                'personName' => $c->personName,
                'birthDate' => $c->birthDate ?? null,
                'city' => $cityName,
                'phone' => $c->phone ?? null,
                'email' => $c->email ?? null,
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
