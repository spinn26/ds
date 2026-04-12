<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationCategory extends Model
{
    protected $table = 'communicationCategory';
    public $timestamps = false;
    protected $guarded = ['id'];
}
