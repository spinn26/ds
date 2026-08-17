<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

/**
 * Формат телефона на бэкенде: до этой проверки регистрация принимала любую
 * строку, и в базе оказался «+7 (150) 832-28-70» (код зоны 150 не существует).
 */
class PhoneValidationTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function numbers(): array
    {
        return [
            'мобильный РФ, E.164' => ['+79043904679', true],
            'мобильный РФ, трунковая 8' => ['8 904 390 46 79', true],
            'мобильный РФ без кода страны' => ['9043904679', true],
            'городской СПб' => ['+7 812 123-45-67', true],
            'мобильный КЗ' => ['+7 707 123 45 67', true],
            'США' => ['+1 202 555 0143', true],
            'Украина' => ['+380 67 123 4567', true],

            'несуществующий код зоны 150' => ['+7 (150) 832-28-70', false],
            'нули' => ['+7 (000) 000-00-00', false],
            'код зоны на 2' => ['+7 205 1234567', false],
            'обрубок' => ['12345', false],
            'потеряна цифра в +7' => ['+7 904 390 46 7', false],
            'не номер' => ['abc', false],
            'пусто' => ['', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('numbers')]
    public function test_phone_format(string $input, bool $expected): void
    {
        $this->assertSame($expected, Phone::isValid($input), $input);
    }

    public function test_norm_keeps_last_ten_digits(): void
    {
        $this->assertSame('9043904679', Phone::norm('+7 904 390 46 79'));
        $this->assertSame('9043904679', Phone::norm('89043904679'));
        $this->assertNull(Phone::norm('12345'));
    }
}
