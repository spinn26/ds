<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Program extends Model
{
    protected $table = 'program';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'visibleToResident' => 'boolean',
            'visibleToCalculator' => 'boolean',
        ];
    }

    public function productRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product');
    }

    public function currencyRelation(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency');
    }
}
