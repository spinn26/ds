<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankRequisiteResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bankName' => $this->bankName,
            'bankBik' => $this->bankBik,
            'accountNumber' => $this->accountNumber,
            'correspondentAccount' => $this->correspondentAccount,
            'beneficiaryName' => $this->beneficiaryName,
            'verified' => $this->verified,
        ];
    }
}
