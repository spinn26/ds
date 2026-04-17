<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'patronymic' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:WebUser,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'telegram' => ['nullable', 'string', 'max:100'],
            'birthDate' => ['nullable', 'date'],
            'city' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'refCode' => ['nullable', 'string'],
            'consentPersonalData' => ['accepted'],
            'consentTerms' => ['accepted'],
        ];
    }
}
