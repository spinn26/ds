<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CheckDuplicatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string'],
            'refCode' => ['nullable', 'string'],
        ];
    }
}
