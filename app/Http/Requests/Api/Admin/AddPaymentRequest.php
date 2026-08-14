<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Проведение выплаты партнёру по реестру.
 *
 * Правила перенесены из тела AdminPaymentRegistryController ДОСЛОВНО.
 * ⚠ min:0.01 — не косметика: нулевая выплата создала бы строку в реестре,
 * ничего не выплатив.
 *
 * Приостановка выплат (consultant.payments_suspended) проверяется в
 * контроллере: это не валидация ввода, а состояние партнёра, и ответ там
 * 422 с отдельным текстом.
 */
class AddPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'comment' => 'nullable|string|max:500',
        ];
    }
}
