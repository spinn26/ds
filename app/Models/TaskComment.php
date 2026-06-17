<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string $body
 */
class TaskComment extends Model
{
    protected $fillable = ['task_id', 'user_id', 'body'];
}
