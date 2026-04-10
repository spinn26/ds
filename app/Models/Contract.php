<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    protected $table = 'contract';
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'openDate' => 'datetime',
            'closeDate' => 'datetime',
            'createDate' => 'datetime',
            'createdAt' => 'datetime',
            'deletedAt' => 'datetime',
            'clearOpenDate' => 'boolean',
        ];
    }

    public function clientRelation(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client');
    }

    public function consultantRelation(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'consultant');
    }

    public function programRelation(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program');
    }

    public function productRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product');
    }

    public function statusRelation(): BelongsTo
    {
        return $this->belongsTo(ContractStatus::class, 'status');
    }

    public function currencyRelation(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'contract');
    }
}
