<?php

namespace Tests\Unit;

use App\Services\PeriodFreezeService;
use App\Services\PoolCalculator;
use App\Services\PoolRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke + invariant тесты для PoolRunner.
 *
 * Глубокая math-проверка живёт в CommissionSpecTest (pure-math на
 * PoolCalculator). Здесь — только то что специфично для оркестратора:
 * заморозка, пустой период, возврат frozen-флага, конструктор.
 */
class PoolRunnerTest extends TestCase
{
    #[Test]
    public function it_can_be_resolved_from_container(): void
    {
        $runner = $this->app->make(PoolRunner::class);
        $this->assertInstanceOf(PoolRunner::class, $runner);
    }

    #[Test]
    public function frozen_period_blocks_apply_write(): void
    {
        $freeze = $this->createMock(PeriodFreezeService::class);
        $freeze->method('isFrozen')->willReturn(true);

        $runner = new PoolRunner(new PoolCalculator(), $freeze);
        $r = $runner->run(2026, 4, applyWrite: true);

        $this->assertTrue($r['frozen']);
        $this->assertSame(0, $r['written']);
        $this->assertSame(0.0, $r['totalPaid']);
        $this->assertArrayHasKey('message', $r);
        $this->assertStringContainsString('закрыт', $r['message']);
    }

    #[Test]
    public function frozen_period_allows_preview_read(): void
    {
        // Замороженный период разрешён для preview (applyWrite=false) —
        // оператор может посмотреть на числа, но не переписать.
        $freeze = $this->createMock(PeriodFreezeService::class);
        $freeze->method('isFrozen')->willReturn(true);

        $runner = new PoolRunner(new PoolCalculator(), $freeze);
        $r = $runner->run(2026, 4, applyWrite: false);

        // Не возвращает frozen=true для preview — даёт обычный ответ
        // (либо snapshot из poolLog, либо пустой если данных нет).
        $this->assertArrayHasKey('participants', $r);
        $this->assertArrayHasKey('shareValues', $r);
    }

    #[Test]
    public function empty_month_returns_zero_payouts(): void
    {
        $freeze = $this->createMock(PeriodFreezeService::class);
        $freeze->method('isFrozen')->willReturn(false);

        $runner = new PoolRunner(new PoolCalculator(), $freeze);
        $r = $runner->run(1900, 1, applyWrite: false);

        $this->assertSame(0.0, (float) $r['totalPaid']);
        $this->assertSame(0.0, (float) $r['revenue']);
        $this->assertEmpty($r['participants']);
    }

    #[Test]
    public function response_structure_is_stable(): void
    {
        // Контракт UI: payoutRows / shareValues / metrics ожидают
        // конкретный набор ключей. Любая поломка структуры — регресс.
        $freeze = $this->createMock(PeriodFreezeService::class);
        $freeze->method('isFrozen')->willReturn(false);

        $runner = new PoolRunner(new PoolCalculator(), $freeze);
        $r = $runner->run(1900, 1, applyWrite: false);

        foreach (['year', 'month', 'revenue', 'fund', 'shareValues',
                  'participants', 'totalPaid', 'totalForfeited', 'written'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
        $this->assertIsArray($r['shareValues']);
        $this->assertIsArray($r['participants']);
    }
}
