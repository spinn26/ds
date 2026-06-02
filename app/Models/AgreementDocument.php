<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AgreementDocument extends Model
{
    protected $table = 'agreementPartnersDocuments';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'in_acceptance_flow' => 'boolean',
        ];
    }

    /**
     * Only the documents that are part of the mandatory acceptance flow
     * (Согласие, Политика, Оферта, ПЭП). Стандарты/Фото are excluded.
     * Schema-guarded so envs without the 2026_06_02 migration still work.
     */
    public function scopeInFlow(Builder $query): Builder
    {
        if (Schema::hasColumn($this->getTable(), 'in_acceptance_flow')) {
            $query->where('in_acceptance_flow', true);
        }

        return $query;
    }
}
