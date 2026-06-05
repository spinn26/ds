<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доменный write-гард для роли «support» (Техподдержка).
 *
 * По cabinetPermissions.js / спеке ✅Кабинет-Техподдержки.md чистый support
 * пишет только в:
 *   - products      → /admin/products*, /admin/products-catalog*, /admin/programs-catalog*
 *   - instructions  → /admin/instructions*
 *   - communication / support-desk → роуты чата (отдельная middleware-группа,
 *     этим гардом не покрываются)
 * Всё остальное в admin-группе — Read-Only.
 *
 * До этого гарда единый `role:`-гейт пускал support (хелпдеск) к мутациям
 * партнёров/реквизитов/финансов (deletePartner, verifyRequisites, storeCharge,
 * finalizeMonth…). UI это скрывает, но curl/DevTools — нет.
 *
 * Зеркалит RestrictHeadWrites: если support совмещён с ролью, дающей широкие
 * write-права, гард пропускает — права берутся от той роли.
 */
class RestrictSupportWrites
{
    /** Роли, при которых support-гард не активен — права от другой staff-роли. */
    private const STAFF_OVERRIDES = ['admin', 'backoffice', 'finance', 'calculations', 'corrections'];

    /** Подстроки пути, в которые чистый support может писать (products + instructions). */
    private const WRITE_ALLOW = ['admin/products', 'admin/programs-catalog', 'admin/instructions'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $roles = array_map('trim', explode(',', strtolower((string) ($user->role ?? ''))));
        if (! in_array('support', $roles, true)) {
            return $next($request); // не support — пропускаем
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
            'message' => 'У роли «Техподдержка» нет прав на изменение этих данных — только просмотр.',
        ], 403);
    }
}
