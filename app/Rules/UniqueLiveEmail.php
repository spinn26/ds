<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Почта не занята ДРУГИМ ЖИВЫМ логином (WebUser).
 *
 * Замена `unique:WebUser,email[,{id},id]`, у которого две мины:
 *
 *   1) удалённые записи (dateDeleted) блокировали сохранение. Soft-deleted
 *      дубль по почте не давал сохранить живую карточку партнёра вообще —
 *      даже при правке отчества или наставника (ФК 588: живой WebUser 319
 *      упирался в удалённый 388 с той же почтой; таких карточек 8);
 *   2) `unique` в Postgres сравнивает байт-в-байт, а вход по почте
 *      регистрозависим: «Ivan@x.ru» проходил мимо занятого «ivan@x.ru», и
 *      партнёр оставался без входа (см. RegisterRequest и историю с
 *      импортными ФК, которым логины чинили приведением к нижнему регистру).
 *
 * Пустое значение пропускаем — обязательность это дело правила `required`.
 */
final class UniqueLiveEmail implements ValidationRule
{
    /**
     * @param int|null    $ignoreWebUserId собственный логин записи (при редактировании)
     * @param string|null $currentEmail    почта записи сейчас — если присланная ей равна,
     *                                     проверка не запускается вовсе (см. validate)
     */
    public function __construct(
        private readonly ?int $ignoreWebUserId = null,
        private readonly ?string $currentEmail = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $normalized = mb_strtolower(trim((string) $value));

        // Почта НЕ менялась → не валидируем. Форма карточки шлёт email всегда,
        // и без этой отсечки любой живой конфликт (напр. партнёр и его же
        // клиентский аккаунт с одной почтой в разном регистре — WebUser
        // 511/687, 668/726) запирал бы карточку целиком: не сохранить ни
        // отчество, ни наставника. Конфликт ловим тогда, когда почту реально
        // пытаются поменять на занятую.
        if ($this->currentEmail !== null && $normalized === mb_strtolower(trim($this->currentEmail))) {
            return;
        }

        $taken = User::whereRaw('lower(btrim(email)) = ?', [$normalized])
            ->whereNull('dateDeleted')
            ->when($this->ignoreWebUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($taken) {
            $fail('Этот email уже зарегистрирован.');
        }
    }
}
