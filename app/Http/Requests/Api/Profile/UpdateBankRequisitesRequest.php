<?php

namespace App\Http\Requests\Api\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBankRequisitesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'bankName' => ['required', 'string', 'max:255'],
            'bankBik' => ['required', 'string', 'max:20'],
            'accountNumber' => ['required', 'string', 'max:30'],
            'correspondentAccount' => ['nullable', 'string', 'max:30'],
            'beneficiaryName' => ['required', 'string', 'max:255'],
        ];
    }
}
