<?php

namespace Tests\Unit;

use App\Support\TerminationDeadline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Правило плановой даты терминации.
 *
 * Одно определение обслуживает колонку «Будет терминирован», фильтр
 * «План. терминация», сортировку по ней и отчёт по статусам — разъедутся они
 * ровно тогда, когда кто-то поправит правило мимо этого класса.
 *
 * Окно активации передаём явным аргументом: без него класс полез бы в
 * настройку (SystemSetting), а это уже не unit-тест.
 */
class TerminationDeadlineTest extends TestCase
{
    private const ACTIVE = 1;
    private const REGISTERED = 4;

    /** Активный: источник истины — конец годового периода, его двигает раннер. */
    #[Test]
    public function the_year_period_end_wins(): void
    {
        $this->assertSame('2026-11-20', TerminationDeadline::resolve(
            activity: self::ACTIVE,
            yearPeriodEnd: '2026-11-20 00:00:00',
            dateActivity: '2026-06-01 00:00:00',
        ));
    }

    /**
     * Период не проставлен (legacy-строки) — падаем на «активация + год».
     * Тот же фоллбэк, что в PartnerStatusService::yearPeriodInfo().
     */
    #[Test]
    public function without_a_period_it_falls_back_to_activation_plus_a_year(): void
    {
        $this->assertSame('2027-06-01', TerminationDeadline::resolve(
            activity: self::ACTIVE,
            yearPeriodEnd: null,
            dateActivity: '2026-06-01 00:00:00',
        ));
    }

    /** Зарегистрированный: срок — окно набора ЛП, его могли продлить вручную. */
    #[Test]
    public function a_registered_partner_uses_its_activation_deadline(): void
    {
        $this->assertSame('2026-09-15', TerminationDeadline::resolve(
            activity: self::REGISTERED,
            yearPeriodEnd: null,
            dateActivity: null,
            activationDeadline: '2026-09-15 00:00:00',
            dateCreated: '2026-05-20 00:00:00',
            activationDays: 120,
        ));
    }

    /** Дедлайн не проставлен — считаем от регистрации по окну активации. */
    #[Test]
    public function without_a_deadline_it_counts_the_window_from_registration(): void
    {
        $this->assertSame('2026-09-17', TerminationDeadline::resolve(
            activity: self::REGISTERED,
            yearPeriodEnd: null,
            dateActivity: null,
            activationDeadline: null,
            dateCreated: '2026-05-20 00:00:00',
            activationDays: 120,
        ));
    }

    /** У терминированных и исключённых планового дедлайна нет — он наступил. */
    #[Test]
    public function terminated_and_excluded_have_no_deadline(): void
    {
        foreach ([3, 5] as $activity) {
            $this->assertNull(TerminationDeadline::resolve(
                activity: $activity,
                yearPeriodEnd: '2026-11-20 00:00:00',
                dateActivity: '2026-06-01 00:00:00',
                activationDeadline: '2026-09-15 00:00:00',
                dateCreated: '2026-05-20 00:00:00',
                activationDays: 120,
            ), "статус {$activity}");
        }
    }

    /** Без дат вообще — null, а не «сегодня плюс срок». */
    #[Test]
    public function no_dates_means_no_deadline(): void
    {
        $this->assertNull(TerminationDeadline::resolve(
            activity: self::ACTIVE, yearPeriodEnd: null, dateActivity: null,
        ));
        $this->assertNull(TerminationDeadline::resolve(
            activity: self::REGISTERED, yearPeriodEnd: null, dateActivity: null,
            activationDeadline: null, dateCreated: null, activationDays: 120,
        ));
    }
}
