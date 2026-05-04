<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Commission row — one per MLM cascade level per transaction.
 * Bulk inserts happen via DB::table() in CommissionCalculator and bypass
 * ActivityLog by design (they'd overwhelm the log table). This model is
 * for admin-panel edits, which should be audited.
 */
class Commission extends Model
{
    use LogsActivity;

    protected $table = 'commission';
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            // dateMonth хранится как строка 'YYYY-MM' ('2026-02'), а не int —
            // cast 'integer' давал 2026 вместо 2 (intval до дефиса).
            'dateMonth' => 'string',
            'dateYear' => 'integer',
            'chainOrder' => 'integer',
            'percent' => 'float',
            'personalVolume' => 'float',
            'groupVolume' => 'float',
            'groupBonus' => 'float',
            'groupBonusRub' => 'float',
            'amount' => 'float',
            'amountRUB' => 'float',
            'amountUSD' => 'float',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['consultant', 'transaction', 'amount', 'amountRUB', 'percent', 'type', 'chainOrder'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Commission {$eventName}");
    }

    public function consultantRelation(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'consultant');
    }

    public function transactionRelation(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction');
    }
}
