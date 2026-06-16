<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Сегмент = сохранённый набор фильтров партнёров. */
class UserSegment extends Model
{
    protected $table = 'user_segments';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }
}
