<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Колонка канбана внутри проекта.
 *
 * @property int $id
 * @property ?int $project_id
 * @property string $name
 * @property string $color
 * @property int $sort_order
 * @property bool $is_done
 */
class TaskStage extends Model
{
    protected $fillable = ['project_id', 'name', 'color', 'sort_order', 'is_done'];

    protected $casts = ['is_done' => 'boolean', 'sort_order' => 'integer'];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'stage_id');
    }
}
