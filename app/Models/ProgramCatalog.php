<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit-driven program (variant of a product). Year of КВ payout and
 * contract term live inside the `tariffs` JSONB array as parameters of
 * the per-tariff rate line — they are NOT separate program rows.
 */
class ProgramCatalog extends Model
{
    protected $table = 'programs_catalog';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'has_red'    => 'boolean',
            'active'     => 'boolean',
            'rate_lines' => 'integer',
            'tariffs'    => 'array',
            'row_colors' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductCatalog::class, 'product_id');
    }
}
