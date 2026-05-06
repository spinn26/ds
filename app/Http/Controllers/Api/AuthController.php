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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

        $token = $user->createToken('spa')->plainTextToken;

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
        $consultant = Consultant::where('participantCode', $request->input('code'))
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
    public function register(RegisterRequest $request): JsonResponse
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
            $consultant->person = $user->id;
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

            return $user;
        });

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user),
        ], 201);
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
                $host = env('SOCKET_HOST', '127.0.0.1');
                $port = env('SOCKET_API_PORT', 3002);
                $secret = (string) env('SOCKET_EMIT_SECRET', '');
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
