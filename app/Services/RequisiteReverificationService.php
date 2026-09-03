<?php

namespace App\Services;

use App\Http\Controllers\Api\NotificationController;
use App\Models\BankRequisite;
use App\Models\Consultant;
use App\Models\Requisite;
use Illuminate\Support\Facades\DB;

/**
 * Сброс верификации реквизитов при смене личности партнёра.
 *
 * Per spec ✅Верификация реквизитов Партнёра.md, Контур 3: правка ФИО или ИНН
 * снимает статус «Верифицировано». Партнёром ДС может быть только ИП,
 * оформленное на то же имя, что в профиле, — после переименования это надо
 * подтвердить заново.
 *
 * Делает ровно то же, что кнопка «Отклонить» в разделе Реквизиты
 * (AdminDataController::verifyRequisites): иначе в системе было бы два разных
 * «возврата партнёру», расходящихся в деталях. А именно — снимает verified с
 * ИП-строки и с банковской, закрывает платёжный гейт
 * (consultant.statusRequisites = 2), пишет причину (партнёр видит её плашкой
 * и в кабинете, и в разделе Реквизиты) и перевзводит SLA-таймер.
 *
 * Доступ к повторному вводу открывается сам собой: и ProfileController, и
 * ProductController запрещают правку реквизитов ровно по verified = true.
 */
class RequisiteReverificationService
{
    /** Причина для партнёрской плашки при смене ФИО сотрудником. */
    public const NAME_CHANGE_REASON = 'ФИО в профиле изменено сотрудником — реквизиты нужно подтвердить заново. Проверьте данные ИП и отправьте их повторно.';

    /**
     * Снять верификацию у партнёра и вернуть реквизиты на повторный ввод.
     *
     * @return array{requisiteId: int|null, wasVerified: bool}|null
     *         null — сбрасывать было нечего: реквизиты и так на проверке.
     */
    public function reset(Consultant $consultant, string $reason): ?array
    {
        // Тот же «победитель», что и в списке админки: сперва подтверждённый,
        // затем самый свежий (см. RequisitesListingService::deduplicate).
        $requisite = Requisite::where('consultant', $consultant->id)
            ->active()
            ->orderByDesc('verified')
            ->orderByDesc('id')
            ->first();

        $bank = $requisite
            ? BankRequisite::where('requisites', $requisite->id)->active()->first()
            : null;

        // Гейт мог быть открыт и вручную (AdminUserController: галка
        // «Реквизиты верифицированы»), без подтверждённой строки в requisites, —
        // такой случай тоже закрываем.
        $gateOpen = (int) ($consultant->statusRequisites ?? 0) === 3;
        $wasVerified = (bool) ($requisite?->verified);

        if (! $wasVerified && $bank?->verified !== true && ! $gateOpen) {
            return null;
        }

        DB::transaction(function () use ($requisite, $bank, $consultant, $reason) {
            if ($requisite) {
                $requisite->verified = false;
                $requisite->status = 2; // возвращено партнёру на исправление
                $requisite->rejection_reason = $reason;
                $requisite->dateChange = now();
                // Новый цикл проверки → перевзводим SLA-таймер (см. RequisiteSla).
                $requisite->overdue_notified_at = null;
                $requisite->save();
            }

            if ($bank) {
                $bank->verified = false;
                $bank->dateChange = now();
                $bank->save();
            }

            // Гейт продуктов/выплат. Пишем запросом, а не через модель: карточку
            // партнёра сохраняет вызывающий, и лишний save() отсюда мог бы
            // записать его наполовину применённые правки.
            DB::table('consultant')->where('id', $consultant->id)
                ->update(['statusRequisites' => 2]);
        });

        // Держим модель вызывающего в синхроне с БД, не делая её «грязной».
        $consultant->statusRequisites = 2;
        $consultant->syncOriginalAttribute('statusRequisites');

        if ($consultant->webUser) {
            NotificationController::create(
                (int) $consultant->webUser,
                'requisites',
                'Реквизиты требуют повторного подтверждения',
                $reason,
                '/profile?tab=requisites',
            );
        }

        return ['requisiteId' => $requisite?->id, 'wasVerified' => $wasVerified];
    }
}
