<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Формат телефона на бэкенде — зеркало libphonenumber-js у PhoneInput/
 * vue-tel-input. Клиентская проверка обходится (автозаполнение не поднимает
 * @validate, прямой POST — тем более), поэтому до этого правила в базу
 * попадали номера вида «+7 (150) 832-28-70».
 *
 * Пустое значение пропускаем: обязательность — дело правила `required`.
 */
final class ValidPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if (! Phone::isValid(is_string($value) ? $value : (string) $value)) {
            $fail('Неверный номер телефона.');
        }
    }
}
