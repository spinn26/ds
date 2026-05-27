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
        // Строгий формат ФИО/города — только кириллица + пробел/дефис
        // (заказчик 2026-05-13: «не как попало»). Фронт делает то же regex.
        $cyrillic = '/^[А-Яа-яЁё][А-Яа-яЁё\s\-]*$/u';
        return [
            'firstName' => ['required', 'string', 'max:255', 'regex:' . $cyrillic],
            'lastName' => ['required', 'string', 'max:255', 'regex:' . $cyrillic],
            'patronymic' => ['required', 'string', 'max:255', 'regex:' . $cyrillic],
            'email' => ['required', 'email', 'unique:WebUser,email'],
            'phone' => ['required', 'string', 'max:50'],
            'telegram' => ['required', 'string', 'max:100'],
            'birthDate' => ['required', 'date'],
            'city' => ['required', 'string', 'max:255', 'regex:' . $cyrillic],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            // Закрытая регистрация: можно попасть только по реф-ссылке от
            // активного партнёра. refCode обязателен и должен матчить
            // существующий consultant.participantCode.
            'refCode' => [
                'required', 'string',
                // Mirror checkReferral semantics:
                //  - case-insensitive lookup (legacy DB has both gcpc=... and GCPC=...)
                //  - allow any partner except Terminated/Excluded/soft-deleted
                //    (the `active` flag is not reliably set for Registered partners
                //    after the Directual import)
                function ($attribute, $value, $fail) {
                    $exists = \App\Models\Consultant::whereRaw('LOWER("participantCode") = ?', [mb_strtolower((string) $value)])
                        ->whereNull('dateDeleted')
                        ->whereNotIn('activity', [
                            \App\Enums\PartnerActivity::Terminated->value,
                            \App\Enums\PartnerActivity::Excluded->value,
                        ])
                        ->exists();
                    if (! $exists) {
                        $fail('Реферальный код не найден или партнёр неактивен. Регистрация возможна только по ссылке от активного партнёра.');
                    }
                },
            ],
            // Step 1: согласие на обработку ПД + Политику (один чекбокс).
            // Оферта принимается отдельно в кабинете после верификации
            // реквизитов — поэтому consentTerms здесь больше нет.
            'consentPersonalData' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'firstName.regex' => 'Имя — только русские буквы',
            'lastName.regex' => 'Фамилия — только русские буквы',
            'patronymic.regex' => 'Отчество — только русские буквы',
            'city.regex' => 'Город — только русские буквы',
        ];
    }
}
