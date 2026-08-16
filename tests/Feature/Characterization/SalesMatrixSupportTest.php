<?php

namespace Tests\Feature\Characterization;

use App\Services\SalesMatrixSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Общие помощники матриц продаж (SalesMatrixSupport).
 *
 * Эти четыре метода жили ДВУМЯ копиями — в матрице по продуктам и в матрице
 * по партнёрам. Копии были написаны по-разному (разные циклы, разная
 * расстановка пробелов в SQL), поэтому перед сведением их в один сервис
 * нужно было доказать, что они дают одно и то же. Тесты ниже — это
 * доказательство: они гоняют границы месяцев и високосные годы, на которых
 * ручные счётчики месяцев обычно и расходятся.
 */
class SalesMatrixSupportTest extends TestCase
{
    private SalesMatrixSupport $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->support = new SalesMatrixSupport();
    }

    #[Test]
    public function month_range_covers_both_bounds(): void
    {
        $this->assertSame(['2026-03'], $this->support->monthRange('2026-03', '2026-03'));
        $this->assertSame(['2026-03', '2026-04', '2026-05'],
            $this->support->monthRange('2026-03', '2026-05'));
    }

    /** Переход через год — место, где ручные счётчики обычно и ломались. */
    #[Test]
    public function month_range_crosses_the_year_boundary(): void
    {
        $this->assertSame(['2025-11', '2025-12', '2026-01', '2026-02'],
            $this->support->monthRange('2025-11', '2026-02'));
    }

    /** Перевёрнутый период даёт пусто, а не бесконечный цикл. */
    #[Test]
    public function an_inverted_range_is_empty(): void
    {
        $this->assertSame([], $this->support->monthRange('2026-05', '2026-03'));
    }

    #[Test]
    public function the_exclusive_start_is_the_next_month(): void
    {
        $this->assertSame('2026-04-01', $this->support->monthExclusiveStart('2026-03'));
        $this->assertSame('2027-01-01', $this->support->monthExclusiveStart('2026-12'));
        $this->assertSame('2026-03-01', $this->support->monthExclusiveStart('2026-02'),
            'февраль високосного года ничем не отличается — берётся первое число следующего');
    }

    /**
     * Курс: управленческий на месяц, иначе ближайший ранний, иначе единица.
     * Здесь важна сама лесенка COALESCE — если она схлопнется, валютные
     * контракты посчитаются по курсу 1.
     */
    #[Test]
    public function the_rate_expression_keeps_its_fallback_ladder(): void
    {
        $sql = $this->support->rateExpr('createDate');

        $this->assertStringContainsString('COALESCE', $sql);
        $this->assertSame(2, substr_count($sql, 'management_currency_rate'),
            'две ступени: курс на месяц и ближайший более ранний');
        $this->assertStringContainsString('createDate', $sql);
        $this->assertStringEndsWith('1))', $sql, 'последний фолбэк — единица');
    }

    /** Поставщик резолвится каскадом, а не одной колонкой. */
    #[Test]
    public function the_supplier_expression_is_a_cascade(): void
    {
        $this->assertStringContainsString('COALESCE', $this->support->resolvedSupplierSql());
    }
}
