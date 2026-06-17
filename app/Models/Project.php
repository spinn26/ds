<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property string $color
 * @property int $created_by
 * @property bool $archived
 */
class Project extends Model
{
    protected $fillable = ['name', 'description', 'color', 'created_by', 'archived'];

    protected $casts = ['archived' => 'boolean'];

    public function stages(): HasMany
    {
        return $this->hasMany(TaskStage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function memberIds()
    {
        return \DB::table('project_members')->where('project_id', $this->id)->pluck('user_id');
    }
}
