<?php

namespace App\Services;

use App\Http\Controllers\Api\NotificationController;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Уведомления по событиям модуля «Задачи и Проекты».
 * Best-effort (NotificationController::create сам глушит ошибки): запись в
 * notifications + push по socket. Актор (тот, кто совершил действие) из
 * получателей исключается.
 */
class TaskNotifier
{
    /** Ссылка на задачу в кабинете (открывается доска проекта либо «Мои задачи»). */
    private static function link(Task $task): string
    {
        return $task->project_id ? "/projects/{$task->project_id}" : '/tasks';
    }

    /** Назначен исполнителем. */
    public static function assigned(Task $task, int $actorId): void
    {
        if (! $task->assignee_id || (int) $task->assignee_id === $actorId) {
            return;
        }
        NotificationController::create(
            (int) $task->assignee_id,
            'system',
            'Вам назначена задача',
            $task->title,
            self::link($task),
        );
    }

    /** Задача завершена — уведомляем постановщика. */
    public static function completed(Task $task, int $actorId): void
    {
        if ((int) $task->created_by === $actorId) {
            return;
        }
        NotificationController::create(
            (int) $task->created_by,
            'system',
            'Задача выполнена',
            $task->title,
            self::link($task),
        );
    }

    /** Новый комментарий — постановщику, исполнителю и наблюдателям (кроме автора). */
    public static function commented(Task $task, int $actorId, string $snippet): void
    {
        $recipients = collect([$task->created_by, $task->assignee_id])
            ->merge(DB::table('task_watchers')->where('task_id', $task->id)->pluck('user_id'))
            ->filter()->map(fn ($i) => (int) $i)->unique()
            ->reject(fn ($i) => $i === $actorId);

        foreach ($recipients as $uid) {
            NotificationController::create(
                $uid,
                'chat',
                "Комментарий: {$task->title}",
                mb_strimwidth($snippet, 0, 120, '…'),
                self::link($task),
            );
        }
    }
}
