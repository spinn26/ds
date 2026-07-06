<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AppliesSorting;
use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\User;
use App\Support\Audit;
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
        $query = $this->buildUserQuery($request);

        $total = $query->count();

        $this->applyUserSorting($query, $request);

        $rows = $query
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        return response()->json(['data' => $this->mapUsers($rows), 'total' => $total]);
    }

    /**
     * GET /admin/users/export — все строки по текущим фильтрам (без пагинации)
     * для выгрузки в Excel. XLSX собирается на клиенте (useExport), здесь —
     * только данные. Те же фильтры/сортировка, что и в index().
     */
    public function export(Request $request): JsonResponse
    {
        $query = $this->buildUserQuery($request);
        $this->applyUserSorting($query, $request);

        // Жёсткий потолок, чтобы случайный «выгрузить всё без фильтра» не
        // вытянул всю таблицу в память. ~1030 реальных WebUser — 20000 с запасом.
        $rows = $query->limit(20000)->get();

        return response()->json(['data' => $this->mapUsers($rows)]);
    }

    /** Общий конструктор запроса с фильтрами (search / role / blocked / soft-delete). */
    private function buildUserQuery(Request $request)
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

        return $query;
    }

    /** Сортировка по клику на заголовок (общая для списка и выгрузки). */
    private function applyUserSorting($query, Request $request): void
    {
        // Колонки WebUser в camelCase — Postgres folds unquoted identifiers to
        // lowercase, поэтому в кавычках.
        $this->applySorting($query, $request, [
            'id'         => 'id',
            'lastName'   => '"lastName"',
            'firstName'  => '"firstName"',
            'email'      => 'email',
            'phone'      => 'phone',
            'role'       => 'role',
            'isBlocked'  => '"isBlocked"',
        ], 'id', 'desc');
    }

    /** Батч-обогащение строк (реф-коды, должности, флаги партнёрского профиля). */
    private function mapUsers($rows)
    {
        $codes = Consultant::whereIn('webUser', $rows->pluck('id'))
            ->pluck('participantCode', 'webUser');
        $positions = \Illuminate\Support\Facades\DB::table('employee_positions')
            ->whereIn('user_id', $rows->pluck('id'))->pluck('position', 'user_id');
        // Управляемые админом флаги партнёрского профиля (для формы).
        $consultants = Consultant::whereIn('webUser', $rows->pluck('id'))
            ->get(['webUser', 'statusRequisites', 'acceptance', 'payments_suspended', 'products_access_no_verify'])
            ->keyBy('webUser');

        return $rows->map(function ($u) use ($codes, $positions, $consultants) {
            $c = $consultants[$u->id] ?? null;

            return [
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
                // Включена ли 2FA — фронт показывает кнопку «Отключить 2ФА» (только админу).
                'twoFactorEnabled' => (bool) $u->two_factor_enabled,
                // Метка мягкого удаления — фронт показывает удалённые строки
                // (фильтр with_deleted) и рисует чип «Удалён».
                'dateDeleted' => $u->dateDeleted?->format('Y-m-d H:i'),
                'agreement' => (bool) $u->agreement,
                'participantCode' => $codes[$u->id] ?? null,
                // Партнёрский профиль + управляемые флаги доступа.
                'hasConsultant' => $c !== null,
                'productsAccessNoVerify' => (bool) ($c->products_access_no_verify ?? false),
                'requisitesVerified' => ((int) ($c->statusRequisites ?? 0)) === 3,
                'offerAccepted' => (bool) ($c->acceptance ?? false),
                'paymentsSuspended' => (bool) ($c->payments_suspended ?? false),
            ];
        });
    }

    public function store(Request $request): JsonResponse
    {
        // Strict: только роль admin (не backoffice).
        $isAdmin = $request->user()->hasAnyRole(['admin']);
        $request->validate([
            // Дубль только среди живых строк — soft-deleted Directual-сироты
            // по email не должны блокировать создание (см. update()).
            'email' => [
                'required', 'email',
                \Illuminate\Validation\Rule::unique('WebUser', 'email')
                    ->whereNull('dateDeleted'),
            ],
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
            // Уникальность email считаем ТОЛЬКО среди живых строк WebUser.
            // Directual-экспорт оставил soft-deleted дубли по одному email
            // (напр. Булка Л.А.: активная 394 + удалённая 409) — без
            // whereNull('dateDeleted') правило спотыкается о сироту и не даёт
            // сохранить живую запись («Этот email уже зарегистрирован»).
            'email' => [
                'required', 'email',
                \Illuminate\Validation\Rule::unique('WebUser', 'email')
                    ->ignore($id)
                    ->whereNull('dateDeleted'),
            ],
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

            // Управление доступом партнёра — только admin. Ручные переопределения
            // гейтов, которые иначе ставятся флоу верификации/акцепта/смены реквизитов.
            if ($isAdmin && $consultant) {
                $touched = false;
                if ($request->has('productsAccessNoVerify')) {
                    $consultant->products_access_no_verify = $request->boolean('productsAccessNoVerify');
                    $touched = true;
                }
                if ($request->has('requisitesVerified')) {
                    // 3 = верифицирован, 2 = на проверке/не подтверждён.
                    $consultant->statusRequisites = $request->boolean('requisitesVerified') ? 3 : 2;
                    $touched = true;
                }
                if ($request->has('offerAccepted')) {
                    $consultant->acceptance = $request->boolean('offerAccepted');
                    $touched = true;
                }
                if ($request->has('paymentsSuspended')) {
                    $susp = $request->boolean('paymentsSuspended');
                    $consultant->payments_suspended = $susp;
                    $consultant->payments_suspended_at = $susp ? now() : null;
                    $touched = true;
                }
                if ($touched) {
                    $consultant->saveQuietly();
                }
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
     * Восстановление мягко-удалённого аккаунта: снимаем dateDeleted и разблок.
     * Зеркалит destroy(). Consultant-строки НЕ трогаем автоматически — их
     * dateDeleted мог быть выставлен раньше и по другим причинам; при
     * необходимости партнёрский профиль восстанавливается отдельно.
     */
    public function restore(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->dateDeleted === null) {
            return response()->json(['message' => 'Аккаунт не был удалён'], 422);
        }
        $user->dateDeleted = null;
        $user->isBlocked = false;
        $user->saveQuietly();

        return response()->json(['message' => 'Восстановлен']);
    }

    /**
     * POST /admin/users/{id}/disable-2fa — админ снимает 2FA пользователю
     * (например, тот потерял доступ к приложению-аутентификатору). В отличие
     * от самостоятельного отключения (TwoFactorController::disable) пароль не
     * требуется — действие под ролью admin и логируется в аудит.
     */
    public function disable2fa(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if (! $user->two_factor_enabled && ! $user->two_factor_secret) {
            return response()->json(['message' => 'У пользователя 2FA не включена'], 422);
        }
        $user->two_factor_secret = null;
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;
        $user->saveQuietly();

        Audit::log('2fa_disabled_by_admin', 'WebUser', $user->id);

        return response()->json(['message' => '2FA отключена']);
    }

    /**
     * FK-колонки, ссылающиеся на "WebUser" (из information_schema). ~50 штук
     * в legacy Directual-схеме. Используется references()/forceDelete() для
     * показа связанных сущностей и переноса их на другой аккаунт.
     */
    private function webUserReferencingColumns(): array
    {
        $rows = DB::select(
            "SELECT tc.table_name AS t, kcu.column_name AS c
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
             JOIN information_schema.constraint_column_usage ccu
               ON tc.constraint_name = ccu.constraint_name
             WHERE tc.constraint_type = 'FOREIGN KEY' AND ccu.table_name = 'WebUser'"
        );

        return array_map(fn ($r) => [$r->t, $r->c], $rows);
    }

    /**
     * GET /admin/users/{id}/references — какие сущности завязаны на аккаунт.
     * Нужно для диалога физического удаления: показать, что будет перенесено,
     * и потребовать выбор целевого аккаунта, если связи есть.
     */
    public function references(int $id): JsonResponse
    {
        User::findOrFail($id);

        $entities = [];
        foreach ($this->webUserReferencingColumns() as [$table, $column]) {
            $count = (int) DB::table($table)->where($column, $id)->count();
            if ($count > 0) {
                $entities[] = ['table' => $table, 'column' => $column, 'count' => $count];
            }
        }
        usort($entities, fn ($a, $b) => $b['count'] <=> $a['count']);

        $tokens = (int) DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $id)
            ->count();

        return response()->json(['entities' => $entities, 'tokens' => $tokens]);
    }

    /**
     * DELETE /admin/users/{id}/force — ФИЗИЧЕСКОЕ удаление аккаунта.
     * WebUser завязан на ~50 FK-таблиц, поэтому:
     *   1) если на аккаунте есть связанные сущности — обязателен reassign_to
     *      (другой живой WebUser), на который они переносятся;
     *   2) переносим все FK-ссылки, удаляем токены, удаляем строку — в одной
     *      транзакции. При конфликте уникальных индексов транзакция
     *      откатывается и возвращаем понятную ошибку.
     * Только admin, нельзя себя, только уже мягко-удалённые (защита от случайного
     * необратимого удаления живого аккаунта).
     */
    public function forceDelete(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['admin'])) {
            return response()->json(['message' => 'Только администратор'], 403);
        }
        if ($id === (int) $request->user()->id) {
            return response()->json(['message' => 'Нельзя удалить свой аккаунт'], 422);
        }

        $user = User::findOrFail($id);
        if ($user->dateDeleted === null) {
            return response()->json(['message' => 'Сначала мягко удалите аккаунт, потом удаляйте физически'], 422);
        }

        $reassignTo = $request->filled('reassign_to') ? (int) $request->input('reassign_to') : null;

        // Собираем реальные ссылки.
        $refs = [];
        foreach ($this->webUserReferencingColumns() as [$table, $column]) {
            $count = (int) DB::table($table)->where($column, $id)->count();
            if ($count > 0) $refs[] = [$table, $column, $count];
        }

        if (! empty($refs) && ! $reassignTo) {
            return response()->json([
                'message' => 'На аккаунте есть связанные сущности — укажите аккаунт для переноса',
            ], 422);
        }
        if ($reassignTo !== null) {
            if ($reassignTo === $id) {
                return response()->json(['message' => 'Нельзя перенести на тот же аккаунт'], 422);
            }
            $target = User::find($reassignTo);
            if (! $target) {
                return response()->json(['message' => 'Аккаунт для переноса не найден'], 404);
            }
            if ($target->dateDeleted !== null) {
                return response()->json(['message' => 'Аккаунт для переноса сам удалён'], 422);
            }
        }

        $moved = 0;
        try {
            DB::transaction(function () use ($id, $reassignTo, $refs, &$moved) {
                foreach ($refs as [$table, $column, $count]) {
                    DB::table($table)->where($column, $id)->update([$column => $reassignTo]);
                    $moved += $count;
                }
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $id)
                    ->delete();
                DB::table('WebUser')->where('id', $id)->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Чаще всего — конфликт уникального индекса при переносе (у целевого
            // аккаунта уже есть строка с тем же ключом). Транзакция откатилась.
            return response()->json([
                'message' => 'Перенос упёрся в конфликт данных у целевого аккаунта — удаление отменено. '
                    . 'Проверьте дубли (напр. один и тот же период/контракт на обоих аккаунтах).',
            ], 409);
        }

        return response()->json([
            'message' => 'Аккаунт удалён физически',
            'moved' => $moved,
            'reassignedTo' => $reassignTo,
        ]);
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
