<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requisite extends Model
{
    protected $table = 'requisites';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'patent' => 'boolean',
            'uproshenka' => 'boolean',
            'selfEmployed' => 'boolean',
            'registrationDate' => 'datetime',
            'dateChange' => 'datetime',
            'deletedAt' => 'datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'consultant');
    }

    public function bankRequisites(): HasMany
    {
        return $this->hasMany(BankRequisite::class, 'requisites');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deletedAt');
    }
}
