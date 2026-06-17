<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Отдел оргструктуры.
 *
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property ?int $parent_id
 * @property ?int $head_id
 * @property ?int $deputy_id
 * @property int $sort_order
 */
class Department extends Model
{
    protected $fillable = ['name', 'description', 'parent_id', 'head_id', 'deputy_id', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function memberIds()
    {
        return \DB::table('department_members')->where('department_id', $this->id)->pluck('user_id');
    }
}
