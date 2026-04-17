<?php

namespace App\Http\Requests\Api\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:50'],
            'nicTG' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string'],
            'birthDate' => ['nullable', 'date'],
        ];
    }
}
