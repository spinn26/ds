<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Контент-страница (правила/FAQ/оферта), редактируется админом.
 */
class ContentPage extends Model
{
    protected $connection = 'pgsql_v2';
    protected $table = 'content_pages';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
