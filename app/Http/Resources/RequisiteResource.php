<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class RequisiteResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $statusName = DB::table('status_requisites')
            ->where('id', $this->status)
            ->value('name');

        return [
            'id' => $this->id,
            'individualEntrepreneur' => $this->individualEntrepreneur,
            'inn' => $this->inn,
            'ogrn' => $this->ogrn,
            'address' => $this->address,
            'registrationDate' => $this->registrationDate?->toDateString(),
            'email' => $this->email,
            'phone' => $this->phone,
            'verified' => $this->verified,
            'statusName' => $statusName,
        ];
    }
}
