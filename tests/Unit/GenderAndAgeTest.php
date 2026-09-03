<?php

namespace Tests\Unit;

use App\Support\Age;
use App\Support\Gender;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Пол и возраст для демографической выгрузки.
 *
 * Правила касаются персональных данных реальных людей, поэтому сетка
 * закрепляет не только «что распознаём», но и «где отказываемся угадывать»:
 * молчаливая догадка по имени хуже пустого значения.
 */
class GenderAndAgeTest extends TestCase
{
    /** Легаси-значения Directual и однобуквенные коды приводятся к канону. */
    #[Test]
    public function stored_values_are_normalized(): void
    {
        foreach (['male', 'M', 'м', 'Муж', 'Мужской'] as $raw) {
            $this->assertSame('male', Gender::normalize($raw), "значение «{$raw}»");
        }
        foreach (['female', 'F', 'ж', 'Жен', 'Женский'] as $raw) {
            $this->assertSame('female', Gender::normalize($raw), "значение «{$raw}»");
        }
        foreach (['', '   ', null, 'не указан', '0'] as $raw) {
            $this->assertNull(Gender::normalize($raw));
        }
    }

    /** Русское отчество даёт пол однозначно. */
    #[Test]
    public function patronymic_resolves_gender(): void
    {
        foreach (['Сергеевич', 'Ильич', 'Кузьмич', 'Саввич', 'Никитич'] as $p) {
            $this->assertSame('male', Gender::fromPatronymic($p), "отчество «{$p}»");
        }
        foreach (['Сергеевна', 'Ильинична', 'Кузьминична', 'Нурлановна'] as $p) {
            $this->assertSame('female', Gender::fromPatronymic($p), "отчество «{$p}»");
        }
    }

    /** Нераспознанное отчество — null, а не догадка. */
    #[Test]
    public function unknown_patronymic_stays_unknown(): void
    {
        foreach (['', null, 'Ким', 'Ли', '—', 'нет'] as $p) {
            $this->assertNull(Gender::fromPatronymic($p), 'отчество «' . (string) $p . '»');
        }
    }

    /** Заполненное значение всегда сильнее вычисленного по отчеству. */
    #[Test]
    public function stored_value_wins_over_the_guess(): void
    {
        $this->assertSame('female', Gender::resolve('Женский', 'Сергеевич', 'Иванов Иван Сергеевич'));
    }

    /**
     * У партнёров без логина отдельного поля отчества нет — берём третье
     * слово из денормализованного ФИО.
     */
    #[Test]
    public function person_name_is_the_last_resort(): void
    {
        $this->assertSame('female', Gender::resolve(null, null, 'Петрова Мария Сергеевна'));
        $this->assertSame('male', Gender::resolve(null, null, 'Петров Пётр Сергеевич'));
        // ФИО без отчества гадать не даём.
        $this->assertNull(Gender::resolve(null, null, 'Петров Пётр'));
    }

    /** Возраст считается в полных годах на «сегодня». */
    #[Test]
    public function age_counts_full_years(): void
    {
        $now = Carbon::parse('2026-09-03');

        $this->assertSame(38, Age::years('1988-03-14', $now));
        // День рождения ещё не наступил — на год меньше.
        $this->assertSame(37, Age::years('1988-12-31', $now));
        // Directual-формат «d.m.Y» из consultant.birthDate (там varchar).
        $this->assertSame(46, Age::years('18.02.1980', $now));
    }

    /** Битые и невозможные даты в статистику не попадают. */
    #[Test]
    public function broken_dates_yield_no_age(): void
    {
        $now = Carbon::parse('2026-09-03');

        foreach (['', null, 'не указана', '0001-01-01', '2030-01-01'] as $value) {
            $this->assertNull(Age::years($value, $now), 'дата «' . (string) $value . '»');
        }
    }

    /** Границы возрастных групп. */
    #[Test]
    public function age_buckets_have_no_gaps(): void
    {
        $this->assertSame('до 25', Age::bucket(24));
        $this->assertSame('25–34', Age::bucket(25));
        $this->assertSame('25–34', Age::bucket(34));
        $this->assertSame('35–44', Age::bucket(35));
        $this->assertSame('45–54', Age::bucket(54));
        $this->assertSame('55–64', Age::bucket(55));
        $this->assertSame('65 и старше', Age::bucket(65));
        $this->assertSame('65 и старше', Age::bucket(99));
        $this->assertSame('Не указан', Age::bucket(null));
    }
}
