<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $table = 'client';
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'leadDs' => 'boolean',
            'dateCreated' => 'datetime',
            'dateChanged' => 'datetime',
            'dateDeleted' => 'datetime',
            'workSince' => 'datetime',
            'investingStartDate' => 'datetime',
            'investingEndDate' => 'datetime',
            'lastActivityDate' => 'datetime',
        ];
    }

    public function consultantRelation(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'consultant');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'client');
    }
}
