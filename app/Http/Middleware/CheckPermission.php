<?php

namespace App\Http\Middleware;

use App\Services\PermissionResolverService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Секционная серверная проверка прав: `permission:<section>,<level>`.
 *
 * Единый источник прав — таблица permission_groups (сетка «Группы и права»),
 * тот же резолвер, что кормит фронт (usePermissions). Middleware закрывает
 * дыру, когда роль имеет доступ к staff-группе по роли, но НЕ имеет нужного
 * уровня на конкретный раздел: фронт прячет кнопку, а прямой POST/PUT/DELETE
 * из DevTools/curl проходил. Теперь — 403.
 *
 * Уровни зеркалят фронт: create/update → 'edit', деструктив/спец → 'full'.
 * admin получает full на все секции (см. PermissionResolverService), мульти-
 * роль — максимум уровня по всем ролям. Существующие role-level гарды
 * (restrict.invest и т.п.) остаются как есть — работают в связке (AND).
 *
 * Применение в routes/api.php:
 *   Route::post('/admin/clients', ...)->middleware('permission:clients,edit');
 *   Route::delete('/admin/clients/{id}', ...)->middleware('permission:clients,full');
 */
class CheckPermission
{
    public function __construct(private PermissionResolverService $resolver)
    {
    }

    public function handle(Request $request, Closure $next, string $section, string $level = 'edit'): Response
    {
        $user = $request->user();
        if (! $user) {
            // Аутентификацию проверяет auth:sanctum раньше в стеке; если сюда
            // дошли без user — не наша забота, пропускаем к следующему звену.
            return $next($request);
        }

        $roles = $user->getRolesArray();
        $ok = match ($level) {
            'full' => $this->resolver->canFull($roles, $section),
            'view' => $this->resolver->canView($roles, $section),
            default => $this->resolver->canEdit($roles, $section),
        };

        abort_unless($ok, 403, 'Недостаточно прав для этого действия в разделе.');

        return $next($request);
    }
}
