<?php

namespace App\Enums;

/**
 * Статусы активности партнёра.
 * 4 статуса по статусной схеме:
 * 1 - Активен, 3 - Терминирован, 4 - Зарегистрирован, 5 - Исключён.
 * Статус "Неактивный" (2) удалён — все записи переведены в Активен.
 */
enum PartnerActivity: int
{
    case Active = 1;          // Активен
    case Terminated = 3;      // Терминирован
    case Registered = 4;      // Зарегистрирован
    case Excluded = 5;        // Исключён

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активен',
            self::Terminated => 'Терминирован',
            self::Registered => 'Зарегистрирован',
            self::Excluded => 'Исключён',
        };
    }

    public function hasAccess(): bool
    {
        return match ($this) {
            self::Active, self::Registered => true,
            self::Terminated, self::Excluded => false,
        };
    }

    public function canInvite(): bool
    {
        return $this === self::Active;
    }

    public const MAX_TERMINATIONS = 3;
    public const ACTIVATION_DAYS = 90;
    public const ACTIVATION_POINTS = 500;
}
