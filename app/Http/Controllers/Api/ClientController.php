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
        $consultant = Consultant::forUser($user->id);

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
        if ($request->filled('phone')) {
            // Телефон в базе лежит в свободном формате: +7 (999) 123-45-67,
            // 89991234567, с пробелами и без. Поиск подстрокой по сырому полю
            // нашёл бы клиента только при совпадении разделителей, поэтому
            // сравниваем по одним цифрам — и в колонке, и в запросе.
            $digits = preg_replace('/\D+/', '', (string) $request->input('phone'));
            if ($digits !== '') {
                $query->whereRaw(
                    "regexp_replace(coalesce(phone, ''), '\\D', '', 'g') LIKE ?",
                    ['%' . $digits . '%']
                );
            }
        }
        if ($request->filled('product')) {
            // «Открытые продукты» в выдаче собираются из контрактов клиента
            // (см. ниже), поэтому и фильтр идёт по ним же — иначе колонка и
            // фильтр показывали бы разное. Удалённые контракты не в счёт.
            $productLike = '%' . $request->input('product') . '%';
            $query->whereExists(function ($sub) use ($productLike) {
                $sub->select(DB::raw(1))
                    ->from('contract')
                    ->whereColumn('contract.client', 'client.id')
                    ->whereNull('contract.deletedAt')
                    ->where('contract.productName', 'ilike', $productLike);
            });
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
        // birthDate — своя колонка карточки, сортировать по ней безопасно.
        // city сознательно НЕ добавлен: там лежит и название города, и legacy-id
        // (см. фильтр выше), поэтому сортировка получилась бы наполовину по
        // буквам, наполовину по числам — в таблице она выключена.
        $allowedSort = ['personName', 'id', 'birthDate'];
        $query->orderBy(in_array($sortBy, $allowedSort) ? $sortBy : 'personName', $sortDir);

        $clientRows = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        // Только коды справочника: форма клиента сохраняет название, а оно
        // в whereIn по integer city.id роняет весь список.
        $cityIds = $clientRows->pluck('city')->filter(fn ($v) => ctype_digit((string) $v))->unique();
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
