<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Правка проведённой выплаты.
 *
 * Правила перенесены ДОСЛОВНО, включая nullable у всех трёх полей: это
 * частичное обновление, и отсутствие поля означает «не трогать». Замена
 * nullable на required незаметно превратила бы правку комментария в
 * обязательный ввод суммы.
 */
class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|integer|in:1,2,3',
            'comment' => 'nullable|string|max:500',
        ];
    }
}
