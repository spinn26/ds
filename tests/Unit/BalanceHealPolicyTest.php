<?php

namespace Tests\Unit;

use App\Services\BalanceHealPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Границы автопочинки снимка начислений.
 *
 * Тест сторожит не формулу, а ПРАВО автомата писать в consultantBalance —
 * таблицу, из которой реестр берёт суммы к выплате. Каждый «block» здесь
 * оплачен конкретным случаем из докблока BalanceHealPolicy.
 */
class BalanceHealPolicyTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function ctx(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'historical' => false,
            'future' => false,
            'frozen' => false,
            'limit' => 100000.0,
            'force' => false,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function facts(array $overrides = []): array
    {
        return array_merge(['drifted' => 13, 'total' => 8921.60, 'dupPartners' => 0], $overrides);
    }

    #[Test]
    public function no_drift_means_nothing_to_do(): void
    {
        $d = (new BalanceHealPolicy)->decide($this->facts(['drifted' => 0, 'total' => 0.0]), $this->ctx());

        $this->assertSame(BalanceHealPolicy::NOTHING, $d['action']);
    }

    #[Test]
    public function ordinary_drift_is_healed(): void
    {
        $d = (new BalanceHealPolicy)->decide($this->facts(), $this->ctx());

        $this->assertSame(BalanceHealPolicy::HEAL, $d['action']);
    }

    #[Test]
    public function frozen_month_is_never_touched(): void
    {
        // Снимок закрытого месяца уже ушёл в выплаты — правка задним числом
        // только после явной разморозки админом.
        $d = (new BalanceHealPolicy)->decide($this->facts(), $this->ctx(['frozen' => true]));

        $this->assertSame(BalanceHealPolicy::BLOCK, $d['action']);
        $this->assertStringContainsString('закрыт', $d['reason']);
    }

    #[Test]
    public function duplicates_block_the_heal(): void
    {
        // Ресинк перенёс бы задвоенную сумму в реестр — партнёру заплатят дважды.
        $d = (new BalanceHealPolicy)->decide($this->facts(['dupPartners' => 2]), $this->ctx());

        $this->assertSame(BalanceHealPolicy::BLOCK, $d['action']);
        $this->assertStringContainsString('дубли', $d['reason']);
    }

    #[Test]
    public function duplicates_block_even_with_force(): void
    {
        $d = (new BalanceHealPolicy)->decide($this->facts(['dupPartners' => 1]), $this->ctx(['force' => true]));

        $this->assertSame(BalanceHealPolicy::BLOCK, $d['action']);
    }

    #[Test]
    public function drift_above_limit_calls_a_human(): void
    {
        $d = (new BalanceHealPolicy)->decide($this->facts(['total' => 4_400_000.0]), $this->ctx());

        $this->assertSame(BalanceHealPolicy::BLOCK, $d['action']);
        $this->assertStringContainsString('порога', $d['reason']);
    }

    #[Test]
    public function force_lifts_only_the_limit(): void
    {
        $d = (new BalanceHealPolicy)->decide($this->facts(['total' => 4_400_000.0]), $this->ctx(['force' => true]));

        $this->assertSame(BalanceHealPolicy::HEAL, $d['action']);
    }

    #[Test]
    public function historical_period_stays_immutable(): void
    {
        $d = (new BalanceHealPolicy)->decide($this->facts(), $this->ctx(['historical' => true]));

        $this->assertSame(BalanceHealPolicy::BLOCK, $d['action']);
    }

    #[Test]
    public function future_period_is_not_rebuilt(): void
    {
        // Фантомный «2027-05» 27.08.2026 размножился с 3 строк до 689.
        $d = (new BalanceHealPolicy)->decide($this->facts(), $this->ctx(['future' => true]));

        $this->assertSame(BalanceHealPolicy::BLOCK, $d['action']);
    }

    #[Test]
    public function kill_switch_stops_the_automation(): void
    {
        $d = (new BalanceHealPolicy)->decide($this->facts(), $this->ctx(['enabled' => false]));

        $this->assertSame(BalanceHealPolicy::BLOCK, $d['action']);
        $this->assertStringContainsString('выключена', $d['reason']);
    }
}
