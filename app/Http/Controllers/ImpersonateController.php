<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonateController extends Controller
{
    /**
     * Impersonate user — creates Sanctum token with original-id encoded
     * в abilities (impersonate:from:{adminId}). leave() читает её для
     * валидации, иначе любой admin мог бы выпустить токен любого другого
     * admin'а через POST {impersonator_id: X}.
     */
    public function impersonate(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        // Strict: only role admin (не backoffice).
        if (! $currentUser || ! $currentUser->hasAnyRole(['admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $token = $user->createToken('impersonate', ['impersonate:from:' . $currentUser->id])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'firstName' => $user->firstName,
                'lastName' => $user->lastName,
                'role' => $user->role,
            ],
            'impersonator_id' => $currentUser->id,
        ]);
    }

    /**
     * Leave impersonation — возвращается на оригинального admin'а.
     * Original-id берётся из Sanctum-abilities текущего токена
     * (impersonate:from:{adminId}), а не из тела запроса — body можно
     * подделать.
     */
    public function leave(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        $impersonatorId = null;
        foreach ((array) ($token?->abilities ?? []) as $ability) {
            if (str_starts_with((string) $ability, 'impersonate:from:')) {
                $impersonatorId = (int) substr($ability, strlen('impersonate:from:'));
                break;
            }
        }

        if (! $impersonatorId) {
            return response()->json(['message' => 'Not impersonating'], 400);
        }

        $admin = User::find($impersonatorId);
        if (! $admin || ! $admin->hasAnyRole(['admin'])) {
            return response()->json(['message' => 'Original admin not found or no longer admin'], 403);
        }

        // Удаляем impersonation-токен после выхода — иначе он остаётся валидным.
        $token?->delete();

        $newToken = $admin->createToken('return')->plainTextToken;

        return response()->json([
            'token' => $newToken,
            'user' => [
                'id' => $admin->id,
                'email' => $admin->email,
                'firstName' => $admin->firstName,
                'lastName' => $admin->lastName,
                'role' => $admin->role,
            ],
        ]);
    }
}
