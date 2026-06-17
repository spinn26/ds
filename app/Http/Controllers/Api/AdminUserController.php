<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AppliesSorting;
use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    use PaginatesRequests;
    use AppliesSorting;

    public function index(Request $request): JsonResponse
    {
        // Hide soft-deleted users unless the caller explicitly asks for them.
        $query = User::query();
        if (! $request->boolean('with_deleted')) {
            $query->whereNull('dateDeleted');
        }

        if ($request->filled('search')) {
            // Token-based search: split by whitespace, every token must match
            // SOMEWHERE (email/firstName/lastName/patronymic/phone).
            // So "Саляхутдинов Денис" finds rows where one token is in lastName
            // and the other is in firstName — previously the query was looking
            // for the whole literal "Саляхутдинов Денис" as a single substring.
            $tokens = array_filter(preg_split('/\s+/', trim($request->search)));
            $query->where(function ($outer) use ($tokens) {
                foreach ($tokens as $t) {
                    $pat = '%' . $t . '%';
                    $outer->where(function ($sub) use ($pat) {
                        $sub->where('email', 'ilike', $pat)
                            ->orWhere('firstName', 'ilike', $pat)
                            ->orWhere('lastName', 'ilike', $pat)
                            ->orWhere('patronymic', 'ilike', $pat)
                            ->orWhere('phone', 'ilike', $pat);
                    });
                }
            });
        }
        if ($request->filled('role')) {
            $query->where('role', 'ilike', "%{$request->role}%");
        } elseif (! $request->boolean('include_clients')) {
            // По умолчанию скрываем role=client — это CRM-клиенты
            // (без login-аккаунта по факту, legacy от Directual + от
            // прошлого storeClient'а с багом). Они не нужны в админке
            // пользователей. Чтобы показать — фильтр «Роль» = «Клиент»
            // или ?include_clients=1.
            $query->where(function ($w) {
                $w->whereNull('role')->orWhere('role', '!=', 'client');
            });
        }
        if ($request->filled('blocked')) {
            $query->where('isBlocked', $request->blocked === 'true');
        }

        $total = $query->count();

        // Сортировка по клику на заголовок. Колонки WebUser в camelCase —
        // Postgres folds unquoted identifiers to lowercase, поэтому в кавычках.
        $this->applySorting($query, $request, [
            'id'         => 'id',
            'lastName'   => '"lastName"',
            'firstName'  => '"firstName"',
            'email'      => 'email',
            'phone'      => 'phone',
            'role'       => 'role',
            'isBlocked'  => '"isBlocked"',
        ], 'id', 'desc');

        $rows = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        $codes = Consultant::whereIn('webUser', $rows->pluck('id'))
            ->pluck('participantCode', 'webUser');
        $positions = \Illuminate\Support\Facades\DB::table('employee_positions')
            ->whereIn('user_id', $rows->pluck('id'))->pluck('position', 'user_id');

        $users = $rows->map(fn ($u) => [
            'id' => $u->id,
            'email' => $u->email,
            'firstName' => $u->firstName,
            'lastName' => $u->lastName,
            'patronymic' => $u->patronymic,
            'phone' => $u->phone,
            'role' => $u->role,
            'position' => $positions[$u->id] ?? null,
            'gender' => $u->gender,
            // Y-m-d, иначе datetime-каст сериализует Carbon в UTC и при
            // app-tz Europe/Moscow дата уезжает на день назад (см. ProfileController).
            'birthDate' => $u->birthDate?->format('Y-m-d'),
            'isBlocked' => (bool) $u->isBlocked,
            'agreement' => (bool) $u->agreement,
            'participantCode' => $codes[$u->id] ?? null,
        ]);

        return response()->json(['data' => $users, 'total' => $total]);
    }

    public function store(Request $request): JsonResponse
    {
        // Strict: только роль admin (не backoffice).
        $isAdmin = $request->user()->hasAnyRole(['admin']);
        $request->validate([
            'email' => 'required|email|unique:WebUser,email',
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
            'firstName' => 'required|string',
            'lastName' => 'required|string',
        ]);

        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'firstName' => $request->firstName,
            'lastName' => $request->lastName,
            'patronymic' => $request->patronymic,
            'phone' => $request->phone,
            // Только admin может задавать роль при создании. Иначе всегда 'registered'.
            'role' => $isAdmin ? $request->input('role', 'registered') : 'registered',
            'gender' => $request->gender,
            'birthDate' => $request->birthDate,
            'isBlocked' => $isAdmin ? $request->boolean('isBlocked') : false,
            'agreement' => $request->boolean('agreement'),
        ]);

        return response()->json(['message' => 'Создан', 'id' => $user->id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $consultant = Consultant::where('webUser', $id)->first();
        // Strict: только роль admin может править role/password/isBlocked.
        $isAdmin = $request->user()->hasAnyRole(['admin']);

        $request->validate([
            'email' => "required|email|unique:WebUser,email,{$id}",
            'password' => ['sometimes', 'nullable', 'string',
                \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers(),
            ],
            'participantCode' => [
                'nullable', 'string', 'max:32',
                function ($attribute, $value, $fail) use ($consultant) {
                    if ($value === null || $value === '') return;
                    if (! $consultant) {
                        $fail('У пользователя нет партнёрского профиля — реферальный код задать некуда.');
                        return;
                    }
                    $exists = Consultant::where('participantCode', $value)
                        ->where('id', '!=', $consultant->id)
                        ->exists();
                    if ($exists) $fail('Такой реферальный код уже используется.');
                },
            ],
        ]);

        DB::transaction(function () use ($request, $user, $consultant, $isAdmin) {
            $user->email = $request->input('email', $user->email);
            $user->firstName = $request->input('firstName', $user->firstName);
            $user->lastName = $request->input('lastName', $user->lastName);
            $user->patronymic = $request->input('patronymic', $user->patronymic);
            $user->phone = $request->input('phone', $user->phone);
            $user->gender = $request->input('gender', $user->gender);
            $user->birthDate = $request->input('birthDate', $user->birthDate);
            $user->agreement = $request->boolean('agreement');

            // role / isBlocked / password — только admin.
            if ($isAdmin) {
                $user->role = $request->input('role', $user->role);
                $user->isBlocked = $request->boolean('isBlocked');
                if ($request->filled('password')) {
                    $user->password = Hash::make($request->password);
                }
            }

            $user->saveQuietly();

            // Блокировка должна выкидывать уже залогиненного: отзываем токены
            // (иначе живёт до истечения ≤7 дней).
            if ($user->isBlocked) {
                $user->tokens()->delete();
            }

            if ($request->has('participantCode') && $consultant) {
                $code = $request->input('participantCode');
                $consultant->participantCode = $code === '' ? null : $code;
                $consultant->saveQuietly();
            }

            // Должность (оргструктура / мини-профиль).
            if ($request->has('position')) {
                $pos = trim((string) $request->input('position'));
                DB::table('employee_positions')->updateOrInsert(
                    ['user_id' => $user->id],
                    ['position' => $pos !== '' ? $pos : null, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });

        return response()->json(['message' => 'Обновлён']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($id === (int) $request->user()->id) {
            return response()->json(['message' => 'Нельзя удалить свой аккаунт'], 422);
        }

        $user = User::findOrFail($id);

        // Soft-delete — hard DELETE on WebUser breaks a dozen legacy FKs.
        // Schema already has isBlocked + dateDeleted columns for exactly this.
        // Consultant rows are soft-deleted in the same transaction: otherwise
        // they stay visible on "Партнёры" and "Статусы партнёров" because both
        // screens filter on consultant.dateDeleted, not WebUser.dateDeleted.
        DB::transaction(function () use ($user) {
            $user->isBlocked = true;
            $user->dateDeleted = now();
            $user->saveQuietly();
            $user->tokens()->delete();

            DB::table('consultant')
                ->where('webUser', $user->id)
                ->whereNull('dateDeleted')
                ->update(['dateDeleted' => now()]);
        });

        return response()->json(['message' => 'Удалён']);
    }

    /**
     * История входов пользователя: audit_log (action='login') + гео-резолв
     * по IP через IpGeoService (кэш в `ip_geo_cache`).
     *
     * Возвращает максимум 50 последних входов. Записываются в audit_log
     * автоматически из AuthController::login и login_2fa_challenge,
     * поэтому требований к миграции данных нет — история начинает
     * накапливаться с момента, когда соответствующий Audit::log() вызов
     * уже стоит в коде.
     */
    /** GET /admin/login-log — глобальный журнал входов (audit_log). */
    public function loginLog(Request $request): JsonResponse
    {
        $q = DB::table('audit_log as a')
            ->leftJoin('WebUser as w', 'w.id', '=', 'a.user_id')
            ->whereIn('a.action', ['login', 'login_2fa_challenge'])
            ->orderByDesc('a.created_at');

        if ($s = trim((string) $request->input('search', ''))) {
            $q->where(function ($x) use ($s) {
                $x->where('w.email', 'ilike', "%{$s}%")
                  ->orWhere('w.lastName', 'ilike', "%{$s}%")
                  ->orWhere('w.firstName', 'ilike', "%{$s}%")
                  ->orWhere('a.ip', 'ilike', "%{$s}%");
            });
        }

        $rows = $q->limit(300)->get([
            'a.id', 'a.action', 'a.ip', 'a.user_agent', 'a.created_at', 'a.user_id',
            'w.firstName', 'w.lastName', 'w.email',
        ])->map(fn ($r) => [
            'id' => $r->id,
            'action' => $r->action,
            'ip' => $r->ip,
            'userAgent' => $r->user_agent,
            'createdAt' => $r->created_at,
            'userId' => $r->user_id,
            'name' => trim(($r->lastName ?? '') . ' ' . ($r->firstName ?? '')) ?: ($r->email ?? '—'),
            'email' => $r->email,
        ]);

        return response()->json(['data' => $rows]);
    }

    public function loginHistory(int $id, \App\Services\IpGeoService $geo): JsonResponse
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $rows = DB::table('audit_log')
            ->where('entity', 'WebUser')
            ->where(function ($q) use ($id) {
                // user_id = id (когда сам пользователь логинится — Audit::log
                // подставляет request user_id), либо entity_id (на случай
                // если кто-то логировал событие про этого юзера извне).
                $q->where('user_id', $id)->orWhere('entity_id', (string) $id);
            })
            ->whereIn('action', ['login', 'login_2fa_challenge'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'action', 'ip', 'user_agent', 'created_at', 'payload']);

        if ($rows->isEmpty()) {
            return response()->json(['data' => [], 'user' => [
                'id' => $user->id, 'firstName' => $user->firstName, 'lastName' => $user->lastName,
            ]]);
        }

        $geoMap = $geo->resolveMany($rows->pluck('ip')->filter()->unique()->all());

        // Для countryCode нужно достать из кэша явно — resolveMany() даёт
        // country/region/city/isp без кода. Идём за iso-2 одним select'ом
        // на уникальных IP (≤50 строк, быстрее чем менять интерфейс сервиса).
        $countryCodes = DB::table('ip_geo_cache')
            ->whereIn('ip', $rows->pluck('ip')->filter()->unique()->all())
            ->pluck('country_code', 'ip');

        $data = $rows->map(function ($r) use ($geoMap, $countryCodes) {
            $g = $r->ip ? ($geoMap[$r->ip] ?? null) : null;
            return [
                'id' => $r->id,
                'action' => $r->action,
                'ip' => $r->ip,
                'userAgent' => $r->user_agent,
                'createdAt' => $r->created_at,
                'country' => $g['country'] ?? null,
                'countryCode' => $r->ip ? ($countryCodes[$r->ip] ?? null) : null,
                'region' => $g['region'] ?? null,
                'city' => $g['city'] ?? null,
                'isp' => $g['isp'] ?? null,
            ];
        });

        return response()->json([
            'data' => $data,
            'user' => [
                'id' => $user->id, 'firstName' => $user->firstName,
                'lastName' => $user->lastName, 'email' => $user->email,
            ],
        ]);
    }
}
