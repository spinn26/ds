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

    /**
     * Секции, где право на запись выдаётся точечно через «Группы и права» и
     * этот гард не должен мешать. Общий read-only для head сохраняется: сюда
     * попадает только то, что осознанно открыли руководителю.
     *
     * Работает в связке с `permission:<section>,<level>` на самом маршруте —
     * гард пропускает запрос, а уровень доступа проверяет CheckPermission.
     */
    private const SECTION_EXCEPTIONS = ['news'];

    public function __construct(private \App\Services\PermissionResolverService $resolver)
    {
    }

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

        // Исключение: маршрут закрыт секционным правом из SECTION_EXCEPTIONS и
        // право у ролей есть — значит доступ выдан осознанно в «Группах и
        // правах». Уровень (edit/full) дальше проверит CheckPermission.
        if ($this->hasSectionException($request, $roles)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'У роли «Руководитель» нет прав на изменение данных — только просмотр.',
        ], 403);
    }

    /**
     * На маршруте висит `permission:<section>,<level>` с секцией-исключением,
     * и роли пользователя дают нужный уровень.
     *
     * @param array<string> $roles
     */
    private function hasSectionException(Request $request, array $roles): bool
    {
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                continue;
            }
            [$section, $level] = array_pad(
                explode(',', substr($middleware, strlen('permission:'))), 2, 'edit'
            );
            if (! in_array($section, self::SECTION_EXCEPTIONS, true)) {
                continue;
            }
            $ok = $level === 'full'
                ? $this->resolver->canFull($roles, $section)
                : $this->resolver->canEdit($roles, $section);
            if ($ok) return true;
        }

        return false;
    }
}
