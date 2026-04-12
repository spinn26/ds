<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankRequisite extends Model
{
    protected $table = 'bankrequisites';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'dateChange' => 'datetime',
            'deletedAt' => 'datetime',
        ];
    }

    public function requisite(): BelongsTo
    {
        return $this->belongsTo(Requisite::class, 'requisites');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deletedAt');
    }
}
