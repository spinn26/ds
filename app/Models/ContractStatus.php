<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractStatus extends Model
{
    protected $table = 'contractStatus';
    public $timestamps = false;
    protected $guarded = ['id'];
}
