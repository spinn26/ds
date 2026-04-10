<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $table = 'transaction';
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'dateCreated' => 'datetime',
            'deletedAt' => 'datetime',
            'dateDay' => 'datetime',
            'customCommission' => 'boolean',
            'zeroDsIncome' => 'boolean',
            'customCurrencyRate' => 'boolean',
        ];
    }

    public function contractRelation(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract');
    }

    public function currencyRelation(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency');
    }
}
