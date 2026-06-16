<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Системное объявление/баннер для пользователей.
 */
class Announcement extends Model
{
    protected $table = 'announcements';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'active' => 'boolean',
            'dismissible' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
