<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only гард для роли «invest» (Инвест департамент).
 *
 * Роль видит все разделы (кроме админки) только на просмотр — группа
 * invest в permission_groups (см. «Группы и права») даёт уровень VIEW,
 * UI прячет кнопки на основе effective permissions. Единый источник прав —
 * таблица permission_groups, статического cabinetPermissions.js больше нет.
 * Этот middleware закрывает серверную дыру: invest не может через
 * DevTools / curl сделать POST/PUT/PATCH/DELETE ни в один staff-эндпоинт.
 *
 * Если invest совмещён с write-ролью (admin/backoffice/finance/
 * calculations/corrections) — гард пропускает (права от другой роли).
 */
class RestrictInvestWrites
{
    /** Роли, при которых invest-гард не активен — права от другой staff-роли. */
    private const STAFF_OVERRIDES = ['admin', 'backoffice', 'finance', 'calculations', 'corrections'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $roles = array_map('trim', explode(',', strtolower((string) ($user->role ?? ''))));
        if (! in_array('invest', $roles, true)) {
            return $next($request); // не invest — пропускаем
        }
        if (array_intersect($roles, self::STAFF_OVERRIDES)) {
            return $next($request); // совмещённая роль с write-правами
        }

        // Чистый invest: только чтение во всей staff-группе.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'У роли «Инвест департамент» нет прав на изменение данных — только просмотр.',
        ], 403);
    }
}
