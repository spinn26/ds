<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доп. ограничение для роли «education» (Куратор обучения).
 *
 * Пользователь, у которого ТОЛЬКО роль `education` (нет admin / другой
 * staff-роли с write-правами на чужие домены), может писать только в
 * собственные домены: education-конструктор и инструкции. На все
 * остальные admin-эндпоинты допускается только чтение (GET / HEAD /
 * OPTIONS).
 *
 * Если пользователь не education-only (например, education+head или
 * чистый admin), middleware пропускает его без ограничений — гард
 * предметный, не общий.
 *
 * Применяется поверх `role:` middleware, который проверяет базовый
 * доступ к staff-группе.
 */
class RestrictEducationWrites
{
    /** Префиксы URI, на которые education-only пользователю разрешена запись. */
    private const ALLOWED_WRITE_PREFIXES = [
        'api/v1/admin/education/',
        'api/v1/admin/instructions',
    ];

    /** Роли, с которыми education ходит «как админ» — гард не активен. */
    private const STAFF_OVERRIDES = ['admin', 'backoffice', 'finance', 'head', 'calculations', 'corrections'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $roles = array_map('trim', explode(',', $user->role ?? ''));
        if (! in_array('education', $roles, true)) {
            return $next($request); // не education — пропускаем
        }
        if (array_intersect($roles, self::STAFF_OVERRIDES)) {
            return $next($request); // совмещённая роль — права от другой staff-роли
        }

        // Чистый education: только GET-чтение на чужие домены.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Write — только на свои домены.
        $path = $request->path();
        foreach (self::ALLOWED_WRITE_PREFIXES as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'У роли «Куратор обучения» нет прав на изменение этого раздела.',
        ], 403);
    }
}
