<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Значение кастомного поля для конкретного пользователя (WebUser.id).
 */
class CustomFieldValue extends Model
{
    protected $table = 'custom_field_values';
    protected $guarded = [];
}
