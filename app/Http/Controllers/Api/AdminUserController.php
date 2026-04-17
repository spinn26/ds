<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    use PaginatesRequests;

    public function index(Request $request): JsonResponse
    {
        $query = User::query();

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

        $users = $query->orderByDesc('id')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get()
            ->map(fn ($u) => [
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

        $request->validate([
            'email' => "required|email|unique:WebUser,email,{$id}",
        ]);

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

        return response()->json(['message' => 'Обновлён']);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'Удалён']);
    }
}
