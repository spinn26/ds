<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доменный write-гард для роли «corrections» (специалист по документации).
 *
 * По cabinetPermissions.js чистый corrections имеет FULL только на разделе
 * «Инструкции» (база знаний), всё остальное — VIEW. Значит в admin-группе он
 * может писать только в /admin/instructions*, остальное — Read-Only.
 *
 * До этого гарда единый `role:`-гейт пускал corrections к deletePartner /
 * verifyRequisites / финансам. Зеркалит RestrictHeadWrites/RestrictSupportWrites.
 */
class RestrictCorrectionsWrites
{
    /** Роли, при которых гард не активен — права от другой staff-роли. */
    private const STAFF_OVERRIDES = ['admin', 'backoffice', 'finance', 'calculations'];

    /** Подстроки пути, в которые чистый corrections может писать. */
    private const WRITE_ALLOW = ['admin/instructions'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $roles = array_map('trim', explode(',', strtolower((string) ($user->role ?? ''))));
        if (! in_array('corrections', $roles, true)) {
            return $next($request); // не corrections — пропускаем
        }
        if (array_intersect($roles, self::STAFF_OVERRIDES)) {
            return $next($request); // совмещённая роль с write-правами
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $path = $request->path();
        foreach (self::WRITE_ALLOW as $allowed) {
            if (str_contains($path, $allowed)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'У роли «Документация» нет прав на изменение этих данных — только просмотр.',
        ], 403);
    }
}
