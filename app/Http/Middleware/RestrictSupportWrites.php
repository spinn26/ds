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

    /**
     * Префиксы пути, в которые чистый support может писать (products +
     * instructions). ⚠ Именно ПРЕФИКСЫ и именно с 'api/v1/': прежний
     * str_contains по подстроке пропускал бы любой путь, где эти сегменты
     * встречаются не с начала.
     */
    private const WRITE_ALLOW = [
        'api/v1/admin/products',
        'api/v1/admin/programs-catalog',
        'api/v1/admin/instructions',
    ];

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
            if (str_starts_with($path, $allowed)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'У роли «Техподдержка» нет прав на изменение этих данных — только просмотр.',
        ], 403);
    }
}
