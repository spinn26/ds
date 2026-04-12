<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Проверяет, что пользователь имеет одну из указанных ролей.
     * Использование: middleware('role:admin,backoffice,finance')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $userRoles = array_map('trim', explode(',', $user->role ?? ''));

        // admin имеет доступ ко всему
        if (in_array('admin', $userRoles)) {
            return $next($request);
        }

        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Недостаточно прав'], 403);
    }
}
