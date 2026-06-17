<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string $original_name
 * @property string $path
 * @property int $size
 * @property ?string $mime
 */
class TaskAttachment extends Model
{
    protected $fillable = ['task_id', 'user_id', 'original_name', 'path', 'size', 'mime'];

    protected $casts = ['size' => 'integer'];
}
