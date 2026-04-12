<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgreementDocument extends Model
{
    protected $table = 'agreementPartnersDocuments';
    public $timestamps = false;
    protected $guarded = ['id'];
}
