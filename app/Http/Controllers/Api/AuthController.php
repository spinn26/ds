<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Consultant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'Неверный email или пароль'], 401);
        }

        $valid = false;
        if (Hash::check($request->password, $user->password)) {
            $valid = true;
        } elseif ($user->password === md5($request->password)) {
            $user->password = Hash::make($request->password);
            $user->saveQuietly();
            $valid = true;
        }

        if (! $valid) {
            return response()->json(['message' => 'Неверный email или пароль'], 401);
        }

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userResponse($user),
        ]);
    }

    /**
     * Check for duplicates before registration.
     */
    public function checkDuplicates(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'phone' => 'nullable|string',
        ]);

        // Check email in WebUser (exclude terminated)
        $existingUser = User::where('email', $request->email)->first();
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

        // Check phone
        if ($request->phone) {
            $phone = preg_replace('/[^0-9]/', '', $request->phone);
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

        // Check if this email exists as a client
        if ($request->has('refCode')) {
            $client = Client::where('personName', 'like', '%' . $request->email . '%')
                ->orWhereHas('person', function ($q) use ($request) {
                    $q->where('email', $request->email);
                })->first();

            if ($client && $client->consultant) {
                $assignedConsultant = Consultant::find($client->consultant);
                if ($assignedConsultant && $assignedConsultant->participantCode !== $request->refCode) {
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
     */
    public function checkReferral(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $consultant = Consultant::where('participantCode', $request->code)
            ->where('active', true)
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
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'patronymic' => 'nullable|string|max:255',
            'email' => 'required|email|unique:WebUser,email',
            'phone' => 'nullable|string|max:50',
            'telegram' => 'nullable|string|max:100',
            'birthDate' => 'nullable|date',
            'city' => 'nullable|string|max:255',
            'password' => ['required', 'confirmed', Password::min(6)],
            'refCode' => 'nullable|string',
            'consentPersonalData' => 'accepted',
            'consentTerms' => 'accepted',
        ]);

        $user = User::create([
            'firstName' => $request->firstName,
            'lastName' => $request->lastName,
            'patronymic' => $request->patronymic,
            'email' => $request->email,
            'phone' => $request->phone,
            'nicTG' => $request->telegram,
            'birthDate' => $request->birthDate,
            'password' => Hash::make($request->password),
            'role' => 'registered',
            'dateCreated' => now()->toIso8601String(),
        ]);

        // Create consultant record
        $inviter = null;
        if ($request->refCode) {
            $inviter = Consultant::where('participantCode', $request->refCode)
                ->where('active', true)
                ->first();
        }

        $consultant = new Consultant();
        $consultant->person = $user->id;
        $consultant->personName = trim("{$request->lastName} {$request->firstName} {$request->patronymic}");
        $consultant->active = false;
        $consultant->status = 1; // Default status
        $consultant->dateCreated = now();
        $consultant->participantCode = null; // Assigned after activation
        if ($inviter) {
            $consultant->inviter = $inviter->id;
            $consultant->inviterName = $inviter->personName;
        }
        $consultant->save();

        // Link user to consultant
        $user->consultant_id = $consultant->id;
        $user->saveQuietly();

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userResponse($user),
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

        // Set role to consultant
        $user->role = 'registered,consultant';
        $user->saveQuietly();

        // Set 90-day activation deadline on consultant record
        $consultant = Consultant::where('webUser', $user->id)->first();
        if ($consultant) {
            $consultant->dateActivity = now();
            $consultant->dateDeterministic = now()->addDays(90);
            $consultant->dateDeterministicPlan = now()->addDays(90);
            $consultant->save();
        }

        return response()->json([
            'message' => 'Аккаунт активирован',
            'user' => $this->userResponse($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userResponse($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        // Delete Sanctum token
        $request->user()->currentAccessToken()->delete();

        // Also destroy web session (Filament admin)
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        auth('web')->logout();

        return response()->json(['message' => 'OK']);
    }

    private function userResponse(User $user): array
    {
        // Get activity status from consultant
        $consultant = Consultant::where('webUser', $user->id)->first();
        $activityId = $consultant?->activity;
        // Handle enum or integer
        $activityValue = is_object($activityId) ? $activityId->value : $activityId;

        return [
            'id' => $user->id,
            'email' => $user->email,
            'firstName' => $user->firstName,
            'lastName' => $user->lastName,
            'patronymic' => $user->patronymic,
            'phone' => $user->phone,
            'role' => $user->role,
            'activityStatus' => $activityValue,
            'avatarUrl' => $user->avatar ? '/storage/' . $user->avatar : null,
        ];
    }
}
