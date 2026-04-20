<?php

namespace App\Http\Controllers\Api;

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

    public function index(Request $request): JsonResponse
    {
        // Hide soft-deleted users unless the caller explicitly asks for them.
        $query = User::query();
        if (! $request->boolean('with_deleted')) {
            $query->whereNull('dateDeleted');
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('email', 'ilike', "%{$s}%")
                  ->orWhere('firstName', 'ilike', "%{$s}%")
                  ->orWhere('lastName', 'ilike', "%{$s}%");
            });
        }
        if ($request->filled('role')) {
            $query->where('role', 'ilike', "%{$request->role}%");
        }
        if ($request->filled('blocked')) {
            $query->where('isBlocked', $request->blocked === 'true');
        }

        $total = $query->count();

        $rows = $query->orderByDesc('id')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        $codes = Consultant::whereIn('webUser', $rows->pluck('id'))
            ->pluck('participantCode', 'webUser');

        $users = $rows->map(fn ($u) => [
            'id' => $u->id,
            'email' => $u->email,
            'firstName' => $u->firstName,
            'lastName' => $u->lastName,
            'patronymic' => $u->patronymic,
            'phone' => $u->phone,
            'role' => $u->role,
            'gender' => $u->gender,
            'birthDate' => $u->birthDate,
            'isBlocked' => (bool) $u->isBlocked,
            'agreement' => (bool) $u->agreement,
            'participantCode' => $codes[$u->id] ?? null,
        ]);

        return response()->json(['data' => $users, 'total' => $total]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|unique:WebUser,email',
            'password' => 'required|string|min:6',
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
            'role' => $request->input('role', 'registered'),
            'gender' => $request->gender,
            'birthDate' => $request->birthDate,
            'isBlocked' => $request->boolean('isBlocked'),
            'agreement' => $request->boolean('agreement'),
        ]);

        return response()->json(['message' => 'Создан', 'id' => $user->id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $consultant = Consultant::where('webUser', $id)->first();

        $request->validate([
            'email' => "required|email|unique:WebUser,email,{$id}",
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

        DB::transaction(function () use ($request, $user, $consultant) {
            $user->email = $request->input('email', $user->email);
            $user->firstName = $request->input('firstName', $user->firstName);
            $user->lastName = $request->input('lastName', $user->lastName);
            $user->patronymic = $request->input('patronymic', $user->patronymic);
            $user->phone = $request->input('phone', $user->phone);
            $user->role = $request->input('role', $user->role);
            $user->gender = $request->input('gender', $user->gender);
            $user->birthDate = $request->input('birthDate', $user->birthDate);
            $user->isBlocked = $request->boolean('isBlocked');
            $user->agreement = $request->boolean('agreement');

            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            $user->saveQuietly();

            if ($request->has('participantCode') && $consultant) {
                $code = $request->input('participantCode');
                $consultant->participantCode = $code === '' ? null : $code;
                $consultant->saveQuietly();
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
}
