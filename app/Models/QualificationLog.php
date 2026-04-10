<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualificationLog extends Model
{
    protected $table = 'qualificationLog';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'createdAt' => 'datetime',
            'dateDeleted' => 'datetime',
            'gap' => 'boolean',
            'levelsDontMatch' => 'boolean',
        ];
    }
}
