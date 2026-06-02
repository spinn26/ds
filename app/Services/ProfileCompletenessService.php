<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use App\Models\User;

/**
 * Единый источник правды «профиль активного ФК заполнен полностью».
 *
 * Решение 2026-06-02: текущих активных партнёров принудительно ведём на
 * заполнение профиля. Обязательны ТОЛЬКО личные данные — реквизиты ИП и
 * банковские реквизиты в гейт НЕ входят (заполняются отдельно и проходят
 * ручную верификацию финменеджера). Гейт применяется ТОЛЬКО к активным
 * консультантам (PartnerActivity::Active; legacy Inactive=2 трактуется
 * системой как «Активен», поэтому тоже учитывается). Staff,
 * зарегистрированные (ещё не активные), терминированные и исключённые —
 * не блокируются.
 *
 * Полнота считается на лету при каждом /auth/me — отдельная колонка-флаг
 * не нужна, ничего в БД не мигрируем.
 */
class ProfileCompletenessService
{
    /** Личные данные (атрибуты WebUser). key => человекочитаемая подпись. */
    private const PERSONAL = [
        'lastName' => 'Фамилия',
        'firstName' => 'Имя',
        'patronymic' => 'Отчество',
        'birthDate' => 'Дата рождения',
        'gender' => 'Пол',
        'phone' => 'Телефон',
        'email' => 'Email',
        'taxResidency' => 'Страна',
        'city' => 'Город',
    ];

    /**
     * @return array{applicable: bool, complete: bool, missing: array<int, array{key: string, label: string, section: string}>}
     */
    public function evaluate(User $user, ?Consultant $consultant = null): array
    {
        $consultant ??= Consultant::where('webUser', $user->id)->first();

        // Гейт не для всех: только активные ФК. Остальные считаются «complete»
        // и НЕ applicable — фронт их не блокирует и не показывает «всё ок».
        if ($user->isStaff() || ! $consultant || ! $this->isActivePartner($consultant)) {
            return ['applicable' => false, 'complete' => true, 'missing' => []];
        }

        $missing = [];

        foreach (self::PERSONAL as $key => $label) {
            if ($this->blank($user->{$key} ?? null)) {
                $missing[] = ['key' => $key, 'label' => $label, 'section' => 'personal'];
            }
        }

        return ['applicable' => true, 'complete' => empty($missing), 'missing' => $missing];
    }

    private function isActivePartner(Consultant $consultant): bool
    {
        $activity = $consultant->activity;
        $value = is_object($activity) ? $activity->value : (int) $activity;

        return in_array(
            $value,
            [PartnerActivity::Active->value, PartnerActivity::Inactive->value],
            true,
        );
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
