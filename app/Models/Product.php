<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'product';
    public $timestamps = false;
    protected $guarded = ['id'];

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
