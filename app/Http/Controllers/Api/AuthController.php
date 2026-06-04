<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\CheckDuplicatesRequest;
use App\Http\Requests\Api\Auth\CheckReferralRequest;
use App\Enums\PartnerActivity;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Client;
use App\Models\Consultant;
use App\Models\User;
use App\Services\PartnerAcceptanceService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user) {
            return response()->json(['message' => 'Неверный email или пароль'], 401);
        }

        if (! $user->validatePassword($request->input('password'))) {
            return response()->json(['message' => 'Неверный email или пароль'], 401);
        }

        // Если 2FA включён — отдаём challenge, не выдавая полноценный
        // токен до проверки TOTP-кода.
        if ($user->two_factor_enabled) {
            $expires = time() + 300; // 5 минут
            $payload = "{$user->id}|{$expires}";
            $sig = hash_hmac('sha256', $payload, config('app.key'));
            $challenge = base64_encode("{$payload}|{$sig}");
            \App\Support\Audit::log('login_2fa_challenge', 'WebUser', $user->id);
            return response()->json([
                'requires_2fa' => true,
                'challenge' => $challenge,
            ]);
        }

        $token = $user->createToken('spa')->plainTextToken;
        \App\Support\Audit::log('login', 'WebUser', $user->id, ['email' => $user->email]);

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * Check for duplicates before registration.
     */
    public function checkDuplicates(CheckDuplicatesRequest $request): JsonResponse
    {
        $existingUser = User::where('email', $request->input('email'))->first();

        if ($existingUser) {
            $consultant = Consultant::where('webUser', $existingUser->id)->first();
            $isTerminated = $consultant && $consultant->statusRelation && $consultant->statusRelation->title === 'Терминирован';

            if (! $isTerminated) {
                return response()->json([
                    'duplicate' => true,
                    'type' => 'email',
                    'message' => 'Такой партнёр существует. Войдите в свой кабинет.',
                ]);
            }
        }

        if ($request->filled('phone')) {
            $phone = preg_replace('/[^0-9]/', '', $request->input('phone'));
            if ($phone) {
                $existingByPhone = User::where('phone', 'like', "%{$phone}%")->first();
                if ($existingByPhone && $existingByPhone->id !== ($existingUser->id ?? null)) {
                    return response()->json([
                        'duplicate' => true,
                        'type' => 'phone',
                        'message' => 'Пользователь с таким номером телефона уже существует.',
                    ]);
                }
            }
        }

        if ($request->filled('refCode')) {
            $client = Client::where('personName', 'like', '%' . $request->input('email') . '%')
                ->orWhereHas('person', function ($q) use ($request) {
                    $q->where('email', $request->input('email'));
                })->first();

            if ($client && $client->consultant) {
                $assignedConsultant = Consultant::find($client->consultant);
                if ($assignedConsultant && $assignedConsultant->participantCode !== $request->input('refCode')) {
                    return response()->json([
                        'duplicate' => true,
                        'type' => 'client_mismatch',
                        'message' => "Вы являетесь клиентом партнёра {$assignedConsultant->personName}. Для регистрации обратитесь к нему или напишите в техподдержку.",
                    ]);
                }
            }
        }

        return response()->json(['duplicate' => false]);
    }

    /**
     * Validate referral code and return mentor info.
     *
     * Раньше требовалось consultant.active=true — это отрезало 717 из 1146
     * партнёров с реф-кодами (Registered ещё не активированных, тех у кого
     * флаг active не выставлен после Directual-импорта). Партнёр на любом
     * валидном статусе должен мочь приглашать. Блокируем только
     * Terminated (3) / Excluded (5) и soft-deleted.
     */
    public function checkReferral(CheckReferralRequest $request): JsonResponse
    {
        // participantCode lookup is case-insensitive: Directual export left
        // ~694 codes in lowercase and ~474 in uppercase, with no cross-case
        // collisions. Matching ILIKE/LOWER lets both styles of links work.
        $consultant = Consultant::whereRaw('LOWER("participantCode") = ?', [mb_strtolower((string) $request->input('code'))])
            ->whereNull('dateDeleted')
            ->whereNotIn('activity', [
                \App\Enums\PartnerActivity::Terminated->value,
                \App\Enums\PartnerActivity::Excluded->value,
            ])
            ->first();

        if (! $consultant) {
            return response()->json([
                'valid' => false,
                'message' => 'Реферальный код не найден или партнёр неактивен.',
            ]);
        }

        return response()->json([
            'valid' => true,
            'mentor' => [
                'id' => $consultant->id,
                'name' => $consultant->personName,
                'code' => $consultant->participantCode,
            ],
        ]);
    }

    /**
     * Full 2-step registration.
     */
    public function register(RegisterRequest $request, PartnerAcceptanceService $acceptance): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'firstName' => $request->input('firstName'),
                'lastName' => $request->input('lastName'),
                'patronymic' => $request->input('patronymic'),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'nicTG' => $request->input('telegram'),
                'birthDate' => $request->input('birthDate'),
                'password' => Hash::make($request->input('password')),
                'role' => 'registered',
                'dateCreated' => now()->toIso8601String(),
            ]);

            $inviter = null;
            if ($request->filled('refCode')) {
                // Те же правила что в checkReferral: разрешаем всех кроме
                // терминированных/исключённых/удалённых.
                $inviter = Consultant::where('participantCode', $request->input('refCode'))
                    ->whereNull('dateDeleted')
                    ->whereNotIn('activity', [
                        PartnerActivity::Terminated->value,
                        PartnerActivity::Excluded->value,
                    ])
                    ->first();
            }

            $consultant = new Consultant();
            // consultant.person → person.id (Directual-namespace, не WebUser).
            // Раньше тут стоял $user->id (WebUser.id), что давало битый FK на
            // случайную person-запись с тем же численным id (см. CLAUDE.md
            // про split id-spaces). Корректная связка — через webUser FK.
            $consultant->webUser = $user->id;
            $consultant->personName = trim("{$request->input('lastName')} {$request->input('firstName')} {$request->input('patronymic')}");
            $consultant->active = false;
            $consultant->status = 1;
            $consultant->activity = PartnerActivity::Registered;
            $consultant->dateCreated = now();
            $consultant->participantCode = null;
            if ($inviter) {
                $consultant->inviter = $inviter->id;
                $consultant->inviterName = $inviter->personName;
            }
            $consultant->save();

            $user->consultant_id = $consultant->id;
            $user->saveQuietly();

            return [$user, $consultant];
        });

        [$user, $consultant] = $user;

        // Шаг регистрации: фиксируем согласие на обработку ПД + Политику
        // (галка в форме). Остальные документы (Оферта, ПЭП) партнёр примет
        // в едином блокирующем окне акцепта при первом входе — acceptance
        // остаётся false до тех пор.
        $acceptance->recordRegistrationConsents($consultant, $request);

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user),
        ], 201);
    }

    /**
     * Запрос ссылки на сброс пароля. По email — если он есть в WebUser,
     * шлём письмо с одноразовым токеном через Password broker. Если
     * email не найден — отвечаем тем же сообщением, чтобы не давать
     * enumerate users.
     *
     * Throttle 5/мин на роуте + Password broker внутри тоже имеет
     * throttling (по умолчанию 60с между отправками на один email).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(['email' => $request->input('email')]);

        // По требованию владельца (2026-06-03): если такого пользователя нет —
        // явно сообщаем (раньше отдавали одинаковый текст ради анти-enumeration).
        // Брокер сам проверяет наличие пользователя → INVALID_USER.
        if ($status === Password::INVALID_USER) {
            return response()->json([
                'message' => 'Пользователь с такой почтой не найден.',
            ], 404);
        }

        // Слишком частые запросы сброса — троттлинг брокера (раз в 5 минут
        // на один email, см. config/auth.php). Отдаём остаток секунд, чтобы
        // страница восстановления показала партнёру обратный отсчёт.
        if ($status === Password::RESET_THROTTLED) {
            $retryAfter = $this->resetThrottleRetryAfter($request->input('email'));
            return response()->json([
                'message' => 'Письмо для сброса уже отправлено. Запросить новое можно раз в 5 минут.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        return response()->json([
            'message' => 'Ссылка для сброса пароля отправлена. Проверьте почту.',
            'status' => $status,
        ]);
    }

    /**
     * Сколько секунд осталось до повторного запроса сброса для email.
     * Брокер не отдаёт остаток, поэтому считаем сами по created_at последнего
     * токена в password_reset_tokens и интервалу throttle из config/auth.php.
     */
    private function resetThrottleRetryAfter(string $email): int
    {
        $throttle = (int) config('auth.passwords.users.throttle', 300);
        $table = config('auth.passwords.users.table', 'password_reset_tokens');

        $createdAt = DB::table($table)->where('email', $email)->value('created_at');
        if (! $createdAt) {
            return $throttle;
        }

        $expiresAt = \Illuminate\Support\Carbon::parse($createdAt)->addSeconds($throttle);
        return now()->lt($expiresAt) ? max(1, (int) now()->diffInSeconds($expiresAt)) : 1;
    }

    /**
     * Сброс пароля по токену из письма. Минимальные требования к новому
     * паролю: 8 символов + буква + цифра (как в register).
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', 'min:8',
                'regex:/[A-Za-zА-Яа-я]/', 'regex:/\d/'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->password = Hash::make($password);
                $user->setRememberToken(Str::random(60));
                $user->saveQuietly();

                // Инвалидируем все Sanctum-токены пользователя — после
                // сброса пароля старые сессии должны прекратиться.
                $user->tokens()->delete();

                event(new PasswordReset($user));

                \App\Support\Audit::log('password_reset', 'WebUser', $user->id, [
                    'email' => $user->email,
                ]);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Пароль изменён. Войдите с новым паролем.']);
        }

        return response()->json([
            'message' => match ($status) {
                Password::INVALID_TOKEN => 'Ссылка недействительна или истекла. Запросите новую.',
                Password::INVALID_USER => 'Пользователь не найден.',
                default => 'Не удалось сбросить пароль. Попробуйте позже.',
            },
            'status' => $status,
        ], 422);
    }

    /**
     * Activate account after passing education tests.
     * Changes role from 'registered' to 'consultant' and sets 90-day deadline.
     */
    public function activate(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'registered') {
            return response()->json(['message' => 'Аккаунт уже активирован'], 400);
        }

        DB::transaction(function () use ($user) {
            $user->role = 'registered,consultant';
            $user->saveQuietly();

            $consultant = Consultant::where('webUser', $user->id)->first();
            if ($consultant) {
                $consultant->dateActivity = now();
                $consultant->dateDeterministic = now()->addDays(90);
                $consultant->dateDeterministicPlan = now()->addDays(90);
                $consultant->save();
            }
        });

        return response()->json([
            'message' => 'Аккаунт активирован',
            'user' => UserResource::make($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(UserResource::make($request->user()));
    }

    /**
     * GET /auth/me/permissions — effective permissions для текущего user'а.
     *
     * Источник — таблица permission_groups (управляется через
     * /manage/permissions). admin → 'full' на все известные секции.
     * Несколько ролей → merge по max-level (full > edit > view).
     *
     * Эндпоинт читается фронтом при логине / на app-init и кэшируется
     * в auth-store. Composable usePermissions() читает из этого кэша,
     * а не из статического resources/js/config/cabinetPermissions.js.
     */
    public function permissions(Request $request, \App\Services\PermissionResolverService $resolver): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['permissions' => [], 'roles' => []]);
        }
        $roles = $user->getRolesArray();
        return response()->json([
            'roles' => $roles,
            'permissions' => $resolver->effectivePermissions($roles),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        // Plain text токен из заголовка (для инвалидации в socket-server,
        // который кэширует validateToken по plain Bearer'у).
        $bearer = (string) $request->bearerToken();
        $token->delete();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        auth('web')->logout();

        // Сообщаем socket-серверу, что токен невалиден — он снимет кэши и
        // принудительно дисконнектит активные сокеты с этим токеном.
        // Best-effort: ошибка не должна ронять logout.
        if ($bearer !== '') {
            try {
                // config() (not env()) so the secret survives config:cache on prod.
                $host = config('services.socket.host', '127.0.0.1');
                $port = config('services.socket.api_port', 3002);
                $secret = (string) config('services.socket.emit_secret', '');
                if ($secret !== '') {
                    \Illuminate\Support\Facades\Http::timeout(2)
                        ->withToken($secret)
                        ->post("http://{$host}:{$port}/invalidate-token", ['token' => $bearer]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug('socket invalidate-token failed: ' . $e->getMessage());
            }
        }

        return response()->json(['message' => 'OK']);
    }
}
