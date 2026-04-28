<?php

namespace Tests\Unit;

use App\Services\MonthlyFinaliser;
use App\Services\PeriodFreezeService;
use App\Services\MonthlyFinalisationRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke-тесты для MonthlyFinalisationRunner. DB-зависимые шаги тестируются
 * через CommissionSpecTest (pure-math) — здесь только инстанс-сборка
 * и проверка типов возвращаемого результата на пустой БД.
 */
class MonthlyFinalisationRunnerTest extends TestCase
{
    #[Test]
    public function it_can_be_resolved_from_container(): void
    {
        $runner = $this->app->make(MonthlyFinalisationRunner::class);
        $this->assertInstanceOf(MonthlyFinalisationRunner::class, $runner);
    }

    #[Test]
    public function frozen_period_short_circuits_with_error(): void
    {
        // Mock PeriodFreezeService — isFrozen всегда true.
        $freeze = $this->createMock(PeriodFreezeService::class);
        $freeze->method('isFrozen')->willReturn(true);

        $runner = new MonthlyFinalisationRunner(new MonthlyFinaliser(), $freeze);
        $r = $runner->applyForMonth(2026, 4);

        $this->assertArrayHasKey('error', $r);
        $this->assertSame(0, $r['total']);
        $this->assertSame(0, $r['otrifApplied']);
        $this->assertSame(0, $r['opApplied']);
    }

    #[Test]
    public function returns_zero_stats_on_empty_month(): void
    {
        // Реальный сервис, но месяц без commission-строк — вернёт нули.
        $freeze = $this->createMock(PeriodFreezeService::class);
        $freeze->method('isFrozen')->willReturn(false);

        $runner = new MonthlyFinalisationRunner(new MonthlyFinaliser(), $freeze);
        $r = $runner->applyForMonth(1900, 1);

        $this->assertSame(0, $r['total']);
        $this->assertSame(0, $r['otrifApplied']);
        $this->assertSame(0, $r['opApplied']);
        $this->assertEmpty($r['errors']);
    }
}
