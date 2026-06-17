<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Шаблон задачи (+ опциональное расписание повтора).
 */
class TaskTemplate extends Model
{
    protected $fillable = [
        'name', 'title', 'description', 'priority', 'tags', 'requires_result', 'checklist',
        'assignee_id', 'project_id', 'recurrence_freq', 'recurrence_interval',
        'recurrence_weekday', 'recurrence_monthday', 'recurrence_time', 'active', 'next_run_at', 'created_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'checklist' => 'array',
        'requires_result' => 'boolean',
        'active' => 'boolean',
        'next_run_at' => 'datetime',
    ];

    public const FREQ = ['none', 'daily', 'weekly', 'monthly'];

    /** Вычислить следующий запуск от базовой точки (по умолчанию — сейчас). */
    public function computeNextRun(?Carbon $from = null): ?Carbon
    {
        if ($this->recurrence_freq === 'none') {
            return null;
        }
        $from = $from ?: now();
        [$h, $m] = array_pad(explode(':', (string) ($this->recurrence_time ?: '09:00')), 2, '0');
        $interval = max(1, (int) $this->recurrence_interval);

        if ($this->recurrence_freq === 'daily') {
            $next = $from->copy()->addDays($interval)->setTime((int) $h, (int) $m);
        } elseif ($this->recurrence_freq === 'weekly') {
            $weekday = max(1, min(7, (int) ($this->recurrence_weekday ?: 1)));
            $next = $from->copy()->next($weekday === 7 ? Carbon::SUNDAY : $weekday)->setTime((int) $h, (int) $m);
        } else { // monthly
            $day = max(1, min(28, (int) ($this->recurrence_monthday ?: 1)));
            $next = $from->copy()->addMonthsNoOverflow($interval)->day($day)->setTime((int) $h, (int) $m);
        }

        return $next;
    }
}
