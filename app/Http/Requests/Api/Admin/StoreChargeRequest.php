<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Начисление/правка «Прочих» — прямая запись денег или баллов партнёру.
 *
 * Правила перенесены из тела AdminFinanceController ДОСЛОВНО: тот же набор,
 * тот же порядок, те же сообщения. Один и тот же класс обслуживает store и
 * update — в контроллере правила были продублированы слово в слово.
 *
 * Доступ проверяет middleware `role:admin,finance,calculations` на маршруте,
 * поэтому authorize() здесь всегда true — дублировать гейт в двух местах
 * значит однажды поправить только одно.
 */
class StoreChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'consultant' => 'required|integer|exists:consultant,id',
            // Per spec ✅Прочие начисления §3: тип = Рубли | Баллы.
            // Старые типы (bonus/penalty/compensation) тоже принимаются для
            // обратной совместимости — они мапятся в 'rub' семантику.
            'type' => 'required|in:rub,points,bonus,penalty,compensation',
            'amount' => 'required|numeric',
            'comment' => 'required|string|max:2000',
        ];
    }
}
