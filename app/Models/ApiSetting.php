<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ключи сторонних интеграций. Значение хранится зашифрованным (Laravel
 * encrypted cast → APP_KEY). В UI маскируется если secret=true.
 */
class ApiSetting extends Model
{
    protected $table = 'api_settings';

    protected $fillable = ['key', 'group', 'value', 'label', 'hint', 'secret', 'updated_by'];

    protected $casts = [
        'value' => 'encrypted',
        'secret' => 'boolean',
    ];
}
