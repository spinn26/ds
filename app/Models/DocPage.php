<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Редактируемый из админки markdown-документ (напр. инструкция партнёра).
 * Хранится в собственной таблице doc_pages (см. миграцию) — вне кэш-карты
 * SystemSetting, т.к. контент крупный.
 */
class DocPage extends Model
{
    protected $table = 'doc_pages';

    protected $guarded = [];
}
