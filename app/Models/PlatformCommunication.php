<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformCommunication extends Model
{
    protected $table = 'platformCommunication';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'read' => 'boolean',
        ];
    }

    public function consultantRelation(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'consultant');
    }

    public function categoryRelation(): BelongsTo
    {
        return $this->belongsTo(CommunicationCategory::class, 'category');
    }

    public function scopeForConsultant($query, int $consultantId)
    {
        return $query->where('consultant', $consultantId);
    }

    public function scopeIncoming($query)
    {
        return $query->where('direction', 'ds2p');
    }

    public function scopeOutgoing($query)
    {
        return $query->where('direction', 'p2ds');
    }

    public function scopeUnread($query)
    {
        return $query->where('read', false)->incoming();
    }
}
