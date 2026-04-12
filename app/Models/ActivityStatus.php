<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityStatus extends Model
{
    protected $table = 'directory_of_activities';
    public $timestamps = false;
    protected $guarded = ['id'];

    public function consultants(): HasMany
    {
        return $this->hasMany(Consultant::class, 'activity');
    }
}
