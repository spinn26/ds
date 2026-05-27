<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Audit-driven products catalog. Read-only umbrella container — every row
 * represents one ПРОДУКТ from the «Аудит Продукты и баллы» workbook and
 * groups its variants (programs) underneath.
 */
class ProductCatalog extends Model
{
    protected $table = 'products_catalog';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function programs(): HasMany
    {
        return $this->hasMany(ProgramCatalog::class, 'product_id');
    }
}
