<?php

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Права доступа к задачам (Bitrix-style): матрица «роль в задаче × действие».
 *
 * Роли определяются отношением пользователя к задаче (постановщик/исполнитель/
 * наблюдатель). Администратор задач (роль admin) имеет полный доступ всегда.
 * Матрица хранится в system_settings ключом `tasks.permissions` (json) с
 * фолбэком на DEFAULTS — отсутствие настройки не меняет поведение.
 */
class TaskPermissions
{
    /** Действия: key => человекочитаемая подпись. */
    public const ACTIONS = [
        'view' => 'Просмотр',
        'edit' => 'Редактирование',
        'change_status' => 'Менять статус',
        'change_deadline' => 'Менять сроки',
        'change_responsible' => 'Менять исполнителя',
        'comment' => 'Комментировать',
        'delete' => 'Удалять',
    ];

    /** Роли в задаче: key => подпись. */
    public const ROLES = [
        'creator' => 'Постановщик',
        'responsible' => 'Исполнитель',
        'accomplice' => 'Соисполнитель',
        'auditor' => 'Наблюдатель',
    ];

    /** Дефолтная матрица (роль => [действие => bool]). */
    public const DEFAULTS = [
        'creator'     => ['view' => true, 'edit' => true,  'change_status' => true,  'change_deadline' => true,  'change_responsible' => true,  'comment' => true, 'delete' => true],
        'responsible' => ['view' => true, 'edit' => true,  'change_status' => true,  'change_deadline' => true,  'change_responsible' => false, 'comment' => true, 'delete' => false],
        'accomplice'  => ['view' => true, 'edit' => true,  'change_status' => true,  'change_deadline' => false, 'change_responsible' => false, 'comment' => true, 'delete' => false],
        'auditor'     => ['view' => true, 'edit' => false, 'change_status' => false, 'change_deadline' => false, 'change_responsible' => false, 'comment' => true, 'delete' => false],
    ];

    /** Текущая матрица: дефолты, перекрытые сохранённой настройкой. */
    public static function matrix(): array
    {
        $stored = SystemSetting::value('tasks.permissions', null);
        $matrix = self::DEFAULTS;
        if (is_array($stored)) {
            foreach ($matrix as $role => $actions) {
                foreach ($actions as $act => $_) {
                    if (isset($stored[$role][$act])) {
                        $matrix[$role][$act] = (bool) $stored[$role][$act];
                    }
                }
            }
        }

        return $matrix;
    }

    /** Колонками канбана управляют только администраторы? (по умолчанию — нет). */
    public static function columnsAdminOnly(): bool
    {
        return (bool) SystemSetting::value('tasks.columns_admin_only', false);
    }

    /** Роли пользователя в данной задаче. */
    public static function rolesFor(Task $task, int $uid): array
    {
        $roles = [];
        if ((int) $task->created_by === $uid) {
            $roles[] = 'creator';
        }
        if ((int) $task->assignee_id === $uid) {
            $roles[] = 'responsible';
        }
        if (DB::table('task_accomplices')->where('task_id', $task->id)->where('user_id', $uid)->exists()) {
            $roles[] = 'accomplice';
        }
        if (DB::table('task_watchers')->where('task_id', $task->id)->where('user_id', $uid)->exists()) {
            $roles[] = 'auditor';
        }

        return $roles;
    }

    /** Может ли пользователь выполнить действие над задачей. Админ — всегда да. */
    public static function can(Task $task, $user, string $action): bool
    {
        if (! $user) {
            return false;
        }
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['admin'])) {
            return true;
        }
        $matrix = self::matrix();
        foreach (self::rolesFor($task, (int) $user->id) as $role) {
            if (! empty($matrix[$role][$action])) {
                return true;
            }
        }

        return false;
    }
}
