<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property ?int $project_id
 * @property ?int $stage_id
 * @property ?int $parent_id
 * @property string $title
 * @property ?string $description
 * @property int $created_by
 * @property ?int $assignee_id
 * @property string $priority
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $deadline
 * @property ?\Illuminate\Support\Carbon $started_at
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property int $sort_order
 */
class Task extends Model
{
    protected $fillable = [
        'project_id', 'stage_id', 'parent_id', 'title', 'description', 'created_by',
        'assignee_id', 'priority', 'status', 'deadline', 'started_at', 'completed_at', 'sort_order', 'tags', 'time_spent',
        'requires_result', 'result',
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
        'tags' => 'array',
        'time_spent' => 'integer',
        'requires_result' => 'boolean',
    ];

    public const PRIORITIES = ['low', 'normal', 'high'];
    public const STATUSES = ['pending', 'in_progress', 'done', 'deferred', 'rejected'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(TaskStage::class, 'stage_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('id');
    }

    public function watcherIds()
    {
        return \DB::table('task_watchers')->where('task_id', $this->id)->pluck('user_id');
    }
}
