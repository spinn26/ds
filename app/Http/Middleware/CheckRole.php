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

        // Регистр не значим: User::getRolesArray() и все Restrict*-гарды
        // приводят роли к нижнему регистру, и этот гард обязан вести себя так
        // же — иначе роль «Admin» из legacy-данных получала 403 там, где
        // остальной код считал её админской.
        $userRoles = $user->getRolesArray();

        // admin имеет доступ ко всему
        if (in_array('admin', $userRoles, true)) {
            return $next($request);
        }

        foreach ($roles as $role) {
            if (in_array(strtolower(trim($role)), $userRoles, true)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Недостаточно прав'], 403);
    }
}
