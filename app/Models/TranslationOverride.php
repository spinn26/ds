<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Переопределение строки интерфейса (i18n). */
class TranslationOverride extends Model
{
    protected $table = 'translation_overrides';
    protected $guarded = [];
}
