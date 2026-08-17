<?php

namespace App\Http\Requests\Api\Auth;

use App\Rules\ValidPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Почту приводим к нижнему регистру ещё до валидации: логин по email
     * регистрозависим, и «Ivan@x.ru» в базе означает партнёра, который не
     * может войти под тем, что сам набирает.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
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
            // Почта: сверка РЕГИСТРОНЕЗАВИСИМАЯ. `unique:WebUser,email` в
            // Postgres сравнивает байт-в-байт, поэтому «Ivan@x.ru» проходил
            // мимо уже занятого «ivan@x.ru» — а логин по почте
            // регистрозависим, и такой партнёр остаётся без входа.
            'email' => [
                'required', 'email',
                function ($attribute, $value, $fail) {
                    $exists = \App\Models\User::whereRaw('lower(btrim(email)) = ?', [mb_strtolower(trim((string) $value))])
                        ->exists();
                    if ($exists) {
                        $fail('Партнёр с такой почтой уже зарегистрирован. Войдите в свой кабинет.');
                    }
                },
            ],
            // Телефон: формат + уникальность. Раньше на бэкенде не было ни
            // того, ни другого — вся проверка жила на фронте (@validate у
            // vue-tel-input) и в /auth/check-duplicates, а этот шаг
            // необязателен: автозаполнение не поднимает @validate, прямой POST
            // на /auth/register проходит мимо обоих. Отсюда и «+7 (150)…» в
            // базе, и дубли по номеру.
            'phone' => [
                'required', 'string', 'max:50', new ValidPhone,
                function ($attribute, $value, $fail) {
                    // Сверка по последним 10 цифрам (канон App\Support\Phone):
                    // в колонке номер лежит отформатированным, LIKE по сырой
                    // строке не совпадал никогда.
                    $norm = \App\Support\Phone::norm($value);
                    $exists = \App\Models\User::whereRaw(\App\Support\Phone::sql('phone') . ' = ?', [$norm])->exists();
                    if ($exists) {
                        $fail('Партнёр с таким номером телефона уже зарегистрирован. Войдите в свой кабинет.');
                    }
                },
            ],
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
