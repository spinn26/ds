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
            'taxRegime' => $this->tax_regime,
            'verified' => $this->verified,
            'statusName' => $statusName,
            'rejectionReason' => $this->rejection_reason,
            // Статус для UI профиля (чип/алерты на карточке реквизитов).
            // verified → подтверждено; есть rejection_reason → отклонено
            // (текст финменеджера / ФИО не на своё имя / режим не УСН);
            // иначе — «на проверке».
            'verificationStatus' => $this->verified
                ? 'verified'
                : (filled($this->rejection_reason) ? 'rejected' : 'pending'),
        ];
    }
}
