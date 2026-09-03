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
 */
class TerminationDeadlineTest extends TestCase
{
    /** Источник истины — конец годового периода, его двигает раннер. */
    #[Test]
    public function the_year_period_end_wins(): void
    {
        $this->assertSame(
            '2026-11-20',
            TerminationDeadline::resolve(1, '2026-11-20 00:00:00', '2026-06-01 00:00:00'),
        );
    }

    /**
     * Период не проставлен (legacy-строки) — падаем на «активация + год».
     * Тот же фоллбэк, что в PartnerStatusService::yearPeriodInfo().
     */
    #[Test]
    public function without_a_period_it_falls_back_to_activation_plus_a_year(): void
    {
        $this->assertSame(
            '2027-06-01',
            TerminationDeadline::resolve(1, null, '2026-06-01 00:00:00'),
        );
    }

    /** У неактивных планового дедлайна нет: у «Зарегистрирован» свой срок. */
    #[Test]
    public function only_active_partners_have_a_deadline(): void
    {
        $this->assertNull(TerminationDeadline::resolve(4, '2026-11-20 00:00:00', '2026-06-01 00:00:00'));
        $this->assertNull(TerminationDeadline::resolve(3, null, '2026-06-01 00:00:00'));
        $this->assertNull(TerminationDeadline::resolve(5, null, '2026-06-01 00:00:00'));
    }

    /** Активный без дат вообще — не дата, а null, а не «сегодня + год». */
    #[Test]
    public function an_active_partner_without_dates_has_no_deadline(): void
    {
        $this->assertNull(TerminationDeadline::resolve(1, null, null));
    }
}
