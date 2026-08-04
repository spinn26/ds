<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Шаблон дизайна (логотип / палитры тем / кастомный CSS).
 * Активный (is_active) применяется SPA в рантайме.
 */
class DesignTheme extends Model
{
    protected $table = 'design_themes';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'array',
        ];
    }

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->first();
    }
}
