<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonateController extends Controller
{
    /**
     * Impersonate user — creates Sanctum token and returns it.
     */
    public function impersonate(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        if (! $currentUser || ! $currentUser->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $token = $user->createToken('impersonate')->plainTextToken;

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
     * Leave impersonation — return to original admin.
     */
    public function leave(Request $request): JsonResponse
    {
        $impersonatorId = $request->input('impersonator_id');

        if (! $impersonatorId) {
            return response()->json(['message' => 'No impersonator'], 400);
        }

        $admin = User::find($impersonatorId);
        if (! $admin || ! $admin->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $token = $admin->createToken('return')->plainTextToken;

        return response()->json([
            'token' => $token,
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
