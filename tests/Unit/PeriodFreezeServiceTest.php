<?php

namespace Tests\Unit;

use App\Services\PeriodFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Функциональные тесты PeriodFreezeService — close/reopen/isFrozen/guard.
 * Все идут на реальной тестовой БД (newds_test) через RefreshDatabase,
 * чтобы реально проверить INSERT/UPDATE логику в `period_closures`.
 */
class PeriodFreezeServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function fresh_period_is_not_frozen(): void
    {
        $svc = new PeriodFreezeService();
        $this->assertFalse($svc->isFrozen(2026, 4));
    }

    #[Test]
    public function close_makes_period_frozen(): void
    {
        $svc = new PeriodFreezeService();
        $svc->close(2026, 4, userId: 42, note: 'test');

        $this->assertTrue($svc->isFrozen(2026, 4));

        // Sanity: запись в БД создана с правильным юзером и нотом.
        $row = DB::table('period_closures')
            ->where(['year' => 2026, 'month' => 4])
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(42, (int) $row->closed_by);
        $this->assertSame('test', $row->note);
        $this->assertNull($row->reopened_at);
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $svc = new PeriodFreezeService();
        $svc->close(2026, 4);
        $svc->close(2026, 4);
        $svc->close(2026, 4);

        $this->assertTrue($svc->isFrozen(2026, 4));
        $count = DB::table('period_closures')
            ->where(['year' => 2026, 'month' => 4])
            ->count();
        $this->assertSame(1, $count, 'Duplicate close() should not create new rows');
    }

    #[Test]
    public function reopen_unfreezes_period(): void
    {
        $svc = new PeriodFreezeService();
        $svc->close(2026, 4);
        $this->assertTrue($svc->isFrozen(2026, 4));

        $svc->reopen(2026, 4, userId: 99);
        $this->assertFalse($svc->isFrozen(2026, 4));

        // История сохранена — `reopened_at` заполнен, запись не удалена.
        $row = DB::table('period_closures')
            ->where(['year' => 2026, 'month' => 4])
            ->first();
        $this->assertNotNull($row->reopened_at);
        $this->assertSame(99, (int) $row->reopened_by);
    }

    #[Test]
    public function close_after_reopen_re_freezes(): void
    {
        $svc = new PeriodFreezeService();
        $svc->close(2026, 4);
        $svc->reopen(2026, 4);
        $svc->close(2026, 4, userId: 50);

        $this->assertTrue($svc->isFrozen(2026, 4));
        $row = DB::table('period_closures')
            ->where(['year' => 2026, 'month' => 4])
            ->first();
        // После повторного close — reopened_* очищается, closed_* свежие.
        $this->assertNull($row->reopened_at);
        $this->assertSame(50, (int) $row->closed_by);
    }

    #[Test]
    public function guard_aborts_with_422_on_frozen(): void
    {
        $svc = new PeriodFreezeService();
        $svc->close(2026, 4);

        try {
            $svc->guard(2026, 4);
            $this->fail('guard() должен был бросить HttpException 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    #[Test]
    public function guard_passes_on_open_period(): void
    {
        $svc = new PeriodFreezeService();
        // Не заморожено → guard должен пройти без исключения.
        $svc->guard(2026, 4);
        $this->assertTrue(true); // если дошли сюда — guard не бросил
    }

    #[Test]
    public function resolve_period_handles_all_inputs(): void
    {
        $svc = new PeriodFreezeService();

        $this->assertSame([2026, 4], $svc->resolvePeriod(year: 2026, month: 4));
        $this->assertSame([2026, 4], $svc->resolvePeriod(date: '2026-04-15'));
        $this->assertSame([2026, 4], $svc->resolvePeriod(dateMonth: '2026-04'));
        $this->assertNull($svc->resolvePeriod());
        $this->assertNull($svc->resolvePeriod(dateMonth: 'invalid'));
    }

    #[Test]
    public function isFrozen_treats_reopened_as_open(): void
    {
        $svc = new PeriodFreezeService();
        $svc->close(2026, 4);
        $svc->reopen(2026, 4);

        // Запись в `period_closures` есть, но `reopened_at` заполнен —
        // период должен считаться открытым.
        $this->assertFalse($svc->isFrozen(2026, 4));
    }
}
