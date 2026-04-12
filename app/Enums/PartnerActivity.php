<?php

namespace App\Enums;

/**
 * Статусы активности партнёра.
 * Соответствуют записям в таблице directory_of_activities.
 */
enum PartnerActivity: int
{
    case Active = 1;          // Активный
    case Inactive = 2;        // Неактивный (компрессия)
    case Terminated = 3;      // Терминирован
    case Registered = 4;      // Зарегистрирован
    case Excluded = 5;        // Исключен

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активен',
            self::Inactive => 'Неактивен',
            self::Terminated => 'Терминирован',
            self::Registered => 'Зарегистрирован',
            self::Excluded => 'Исключён',
        };
    }

    /**
     * Может ли партнёр с данным статусом пользоваться кабинетом.
     */
    public function hasAccess(): bool
    {
        return match ($this) {
            self::Active, self::Registered => true,
            self::Inactive, self::Terminated, self::Excluded => false,
        };
    }

    /**
     * Доступна ли генерация реферальных ссылок.
     */
    public function canInvite(): bool
    {
        return $this === self::Active;
    }

    /**
     * Максимальное количество терминаций перед исключением.
     */
    public const MAX_TERMINATIONS = 3;

    /**
     * Дней на активацию после регистрации.
     */
    public const ACTIVATION_DAYS = 90;

    /**
     * Баллов ЛП для активации / сохранения статуса «Активен».
     */
    public const ACTIVATION_POINTS = 500;
}
