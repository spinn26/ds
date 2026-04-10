<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultant extends Model
{
    protected $table = 'consultant';
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'acceptance' => 'boolean',
            'isStudent' => 'boolean',
            'fieldForReport' => 'boolean',
            'dateCreated' => 'datetime',
            'dateChanged' => 'datetime',
            'dateDeleted' => 'datetime',
            'dateActivity' => 'datetime',
            'dateDeactivity' => 'datetime',
            'qualificationLocked' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'person');
    }

    public function statusRelation(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status');
    }

    public function countryRelation(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country');
    }

    public function inviterRelation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'inviter');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'consultant');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'consultant');
    }
}
