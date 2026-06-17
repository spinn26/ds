<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskTemplate;

/**
 * Создание задачи из шаблона (ручной запуск и повтор по расписанию).
 */
class TaskTemplateRunner
{
    public static function instantiate(TaskTemplate $tpl, int $createdBy): Task
    {
        $maxOrder = (int) Task::where('project_id', $tpl->project_id)->whereNull('stage_id')->max('sort_order');

        $task = Task::create([
            'project_id' => $tpl->project_id,
            'title' => $tpl->title,
            'description' => $tpl->description,
            'created_by' => $createdBy,
            'assignee_id' => $tpl->assignee_id,
            'priority' => $tpl->priority ?: 'normal',
            'status' => 'pending',
            'tags' => $tpl->tags,
            'requires_result' => (bool) $tpl->requires_result,
            'sort_order' => $maxOrder + 1,
        ]);

        // Чек-лист → подзадачи.
        foreach ((array) $tpl->checklist as $i => $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            Task::create([
                'parent_id' => $task->id,
                'project_id' => $tpl->project_id,
                'title' => mb_substr($item, 0, 500),
                'created_by' => $createdBy,
                'status' => 'pending',
                'sort_order' => $i,
            ]);
        }

        if ($tpl->assignee_id) {
            TaskNotifier::assigned($task, $createdBy);
        }

        return $task;
    }
}
