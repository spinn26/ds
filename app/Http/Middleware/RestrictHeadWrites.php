<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only гард для роли «head» (Руководитель).
 *
 * Спека ✅Кабинет-Руководителя.md и cabinetPermissions.js — все секции
 * head на уровне VIEW. UI это уже учитывает (скрывает кнопки), а этот
 * middleware закрывает дыру на серверной стороне: head не может через
 * DevTools / curl сделать POST/PUT/PATCH/DELETE ни в один admin-эндпоинт.
 *
 * Если у пользователя head совмещён с другой staff-ролью, имеющей
 * write-права (admin, backoffice, finance, calculations, corrections),
 * — гард пропускает. Гард предметный, не общий.
 *
 * Применяется поверх `role:` middleware в /admin-группе.
 */
class RestrictHeadWrites
{
    /** Роли, при которых head-гард не активен — права от другой staff-роли. */
    private const STAFF_OVERRIDES = ['admin', 'backoffice', 'finance', 'calculations', 'corrections'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $roles = array_map('trim', explode(',', strtolower((string) ($user->role ?? ''))));
        if (! in_array('head', $roles, true)) {
            return $next($request); // не head — пропускаем
        }
        if (array_intersect($roles, self::STAFF_OVERRIDES)) {
            return $next($request); // совмещённая роль с write-правами
        }

        // Чистый head: только чтение во всей admin-группе.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'У роли «Руководитель» нет прав на изменение данных — только просмотр.',
        ], 403);
    }
}
