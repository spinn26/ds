<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Переопределение строки интерфейса (i18n). */
class TranslationOverride extends Model
{
    protected $connection = 'pgsql_v2';
    protected $table = 'translation_overrides';
    protected $guarded = [];
}
