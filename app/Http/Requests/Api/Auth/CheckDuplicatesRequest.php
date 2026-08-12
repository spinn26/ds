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
            // ФИО — для сверки «партнёр с таким именем уже есть». Необязательные:
            // эндпоинт зовут и до заполнения имени.
            'lastName' => ['nullable', 'string', 'max:255'],
            'firstName' => ['nullable', 'string', 'max:255'],
            'patronymic' => ['nullable', 'string', 'max:255'],
        ];
    }
}
