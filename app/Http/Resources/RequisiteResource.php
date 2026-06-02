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
            // Статус для UI профиля (чип/алерты на карточке реквизитов).
            // verified=true → подтверждено; иначе «на проверке». Отдельный
            // 'rejected' не выводим: updateRequisites ставит status=2 на любое
            // сохранение, поэтому по нему нельзя отличить отказ от ожидания.
            'verificationStatus' => $this->verified ? 'verified' : 'pending',
        ];
    }
}
