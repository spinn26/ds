<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Кастомный пункт меню (Конструктор меню).
 *
 * @property string $area
 * @property ?string $group_title
 * @property string $title
 * @property ?string $icon
 * @property string $to
 * @property bool $external
 * @property ?array $roles
 * @property int $sort_order
 * @property bool $active
 */
class MenuItem extends Model
{
    protected $table = 'menu_items';

    protected $fillable = [
        'area', 'group_title', 'title', 'icon', 'to', 'external', 'roles', 'sort_order', 'active',
    ];

    protected $casts = [
        'roles' => 'array',
        'external' => 'boolean',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public const AREAS = ['admin', 'staff', 'partner'];
}
