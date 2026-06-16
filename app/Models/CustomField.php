<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Определение кастомного поля пользователя (тип, обязательность, опции).
 */
class CustomField extends Model
{
    protected $table = 'custom_fields';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'active' => 'boolean',
            'options' => 'array',
            'roles' => 'array',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'field_id');
    }
}
