<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use App\Services\IncomingBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Личный отчёт партнёра (GET /finance/report): «Остаток на начало месяца» и
 * «Итого к выплате» — тем же правилом, что «Сальдо» в реестре выплат.
 *
 * Из этого ответа строится и Excel-выгрузка отчёта. Отчёт брал остаток из
 * одного снимка consultantBalance, а тот про ручные корректировки
 * (other_accruals) не знает: за август 2026 Зарипову (consultant 143) отчёт
 * показал остаток 3 611,01 ₽ и к выплате 167 067,40 ₽, реестр — 743,56 ₽ и
 * 164 199,95 ₽. Разница — две корректировки: −257,97 ₽ за июнь и
 * −2 609,48 ₽ за июль. Цифры ниже — ровно этот случай.
 */
class FinanceReportOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2400001;

    private User $admin;
    private int $seq = 2400100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User();
        $this->admin->id = 2400900;
        $this->admin->email = 'finance-report@test.local';
        $this->admin->firstName = 'Отчёт';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('consultant')->insert([
            'id' => self::PARTNER, 'personName' => 'Отчётов Партнёр',
            'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
        ]);

        // Снимок: июль закрылся с остатком 3 611,01 — корректировок он не видит.
        $this->balance('2026-07', [
            'balance' => 257.97, 'accruedTransactional' => 193_775.25, 'accruedTotal' => 193_775.25,
            'totalPayable' => 194_033.22, 'payed' => 190_422.21, 'remaining' => 3_611.01,
        ]);
        $this->balance('2026-08', [
            'balance' => 3_611.01, 'accruedTransactional' => 163_456.39, 'accruedTotal' => 163_456.39,
            'totalPayable' => 167_067.40, 'remaining' => 167_067.40,
        ]);

        $this->accrual(-257.97, '2026-06-02 00:00:00');
        $this->accrual(-2_609.48, '2026-07-31 00:00:00');
    }

    /** ⚠ Корректировки прошлых месяцев входят в остаток на начало месяца. */
    #[Test]
    public function opening_balance_includes_manual_accruals_of_earlier_months(): void
    {
        $m = $this->monthEnd('2026-08');

        $this->assertEqualsWithDelta(743.56, $m['balanceStart'], 0.01);
        $this->assertEqualsWithDelta(163_456.39, $m['totalAccrued'], 0.01);
        $this->assertEqualsWithDelta(164_199.95, $m['totalPayable'], 0.01);
    }

    /**
     * Корректировка самого месяца — в «Прочих начислениях», а не в остатке:
     * иначе те же рубли легли бы в «К выплате» дважды.
     */
    #[Test]
    public function manual_accruals_of_the_month_stay_out_of_the_opening_balance(): void
    {
        $this->accrual(500, '2026-08-10 00:00:00');

        $m = $this->monthEnd('2026-08');

        $this->assertEqualsWithDelta(743.56, $m['balanceStart'], 0.01);
        $this->assertEqualsWithDelta(500, $m['otherAccruals'], 0.01);
        $this->assertEqualsWithDelta(164_699.95, $m['totalPayable'], 0.01);
    }

    /** Отчёт и реестр выплат считают остаток одним правилом — и совпадают. */
    #[Test]
    public function report_opening_balance_matches_the_registry(): void
    {
        $registry = IncomingBalance::forMonth('2026-08')[self::PARTNER] ?? 0.0;

        $this->assertEqualsWithDelta($registry, $this->monthEnd('2026-08')['balanceStart'], 0.01);
        $this->assertEqualsWithDelta($registry, IncomingBalance::forConsultant(self::PARTNER, '2026-08'), 0.01);
    }

    /** @return array<string, mixed> */
    private function monthEnd(string $month): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/finance/report?' . http_build_query(['consultant' => self::PARTNER, 'month' => $month]))
            ->assertOk()
            ->json('summary.monthEnd');
    }

    /** @param array<string, mixed> $attrs */
    private function balance(string $dm, array $attrs): void
    {
        DB::table('consultantBalance')->insert(array_merge([
            'id' => $this->seq++,
            'consultant' => self::PARTNER,
            'dateMonth' => $dm,
            'dateYear' => substr($dm, 0, 4),
            'balance' => 0, 'accruedTransactional' => 0, 'accruedNonTransactional' => 0,
            'accruedPool' => 0, 'accruedTotal' => 0, 'totalPayable' => 0,
            'payed' => 0, 'remaining' => 0,
        ], $attrs));
    }

    private function accrual(float $amount, string $date): void
    {
        DB::table('other_accruals')->insert([
            'consultant' => self::PARTNER, 'amount' => $amount, 'points' => 0,
            'type' => 'rub', 'accrual_date' => $date,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
