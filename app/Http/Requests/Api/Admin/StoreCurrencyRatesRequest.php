<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Курсы валют за месяц — заводятся кнопкой в /admin/currencies.
 *
 * Правила и сообщения перенесены из тела контроллера ДОСЛОВНО. Курс участвует
 * в пересчёте amountRUB и дальше во всей цепочке, поэтому ослаблять проверки
 * (в частности exists по валюте и min:0 по курсу) нельзя.
 */
class StoreCurrencyRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => 'required|date_format:Y-m',
            'rates' => 'required|array|min:1',
            'rates.*.currencyId' => 'required|integer|exists:currency,id',
            'rates.*.rate' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'period.date_format' => 'Период указывается как ГГГГ-ММ.',
            'rates.required' => 'Укажите хотя бы один курс.',
        ];
    }
}
