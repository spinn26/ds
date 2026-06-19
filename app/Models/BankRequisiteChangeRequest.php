<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Запрос партнёра на смену банковских реквизитов (с доп. проверкой Катей).
 * Хранит снимок «было/стало»; на принятие — применяется к bankrequisites
 * с сохранением текущего статуса верификации.
 */
class BankRequisiteChangeRequest extends Model
{
    protected $table = 'bank_requisite_change_requests';

    protected $guarded = [];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }
}
