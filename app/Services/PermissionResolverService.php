<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Вычисление effective permissions для набора ролей пользователя.
 *
 * Источник истины — таблица `permission_groups` (управляется через
 * админку /manage/permissions). Если пользователь имеет несколько
 * ролей, по каждой секции берём максимальный уровень (full > edit > view).
 *
 * admin — особый случай: всегда возвращаем 'full' для всех известных
 * секций (config/permissions.php). Это убирает необходимость прописывать
 * права admin'а в БД и страхует от случайного снятия доступа.
 */
class PermissionResolverService
{
    private const LEVEL_RANK = ['view' => 1, 'edit' => 2, 'full' => 3];

    /**
     * @param array<string> $roles  список ролей (lowercased ключи)
     * @return array<string, string>  карта section => level
     */
    public function effectivePermissions(array $roles): array
    {
        if (in_array('admin', $roles, true)) {
            $sections = config('permissions.sections', []);
            $all = [];
            foreach ($sections as $s) {
                $all[$s['key']] = 'full';
            }
            return $all;
        }

        if (empty($roles)) return [];

        $merged = [];

        // Инвест департамент — просмотр всех разделов: базовый 'view' на все
        // известные секции. Не понижает права других ролей (ниже DB-группы
        // перекрывают более высоким уровнем через max-merge).
        if (in_array('invest', $roles, true)) {
            // 'permissions' (управление группами и правами) — админ-функция, не даём.
            $adminOnly = ['permissions'];
            foreach (config('permissions.sections', []) as $s) {
                if (in_array($s['key'], $adminOnly, true)) continue;
                $merged[$s['key']] = 'view';
            }
        }

        $rows = DB::table('permission_groups')
            ->whereIn('key', $roles)
            ->get();

        foreach ($rows as $row) {
            $perms = is_string($row->permissions)
                ? (json_decode($row->permissions, true) ?? [])
                : ($row->permissions ?? []);
            foreach ($perms as $section => $level) {
                if (! isset(self::LEVEL_RANK[$level])) continue;
                $current = $merged[$section] ?? null;
                if (! $current || self::LEVEL_RANK[$level] > self::LEVEL_RANK[$current]) {
                    $merged[$section] = $level;
                }
            }
        }
        return $merged;
    }

    /**
     * Уровень доступа конкретно к одной секции — удобно для серверных
     * проверок в Policy/Gate (TODO).
     */
    public function permissionFor(array $roles, string $section): ?string
    {
        return $this->effectivePermissions($roles)[$section] ?? null;
    }

    public function canView(array $roles, string $section): bool
    {
        return $this->permissionFor($roles, $section) !== null;
    }

    public function canEdit(array $roles, string $section): bool
    {
        $level = $this->permissionFor($roles, $section);
        return $level === 'edit' || $level === 'full';
    }

    public function canFull(array $roles, string $section): bool
    {
        return $this->permissionFor($roles, $section) === 'full';
    }
}
