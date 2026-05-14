<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'product';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'noComission' => 'boolean',
            'visibleToResident' => 'boolean',
            'visibleToCalculator' => 'boolean',
        ];
    }
}
