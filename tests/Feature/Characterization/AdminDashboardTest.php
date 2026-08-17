<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Административная сводка (GET /admin/dashboard).
 *
 * Сетка ПОД вынос. Экран целиком состоит из счётчиков, и цена ошибки здесь —
 * не падение, а тихо неверная цифра, поэтому закреплено:
 *   - мягко удалённые партнёры и контракты не считаются нигде;
 *   - выручка берётся по месяцу начисления, а показатели «прошлого месяца»
 *     считаются по своему периоду — они питают проценты роста, и подмена
 *     периода даёт правдоподобный, но неверный тренд;
 *   - «новые за месяц» отсчитываются от начала текущего месяца.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ALIVE = 3000001;
    private const DELETED = 3000002;
    private const INACTIVE = 3000003;

    private User $admin;
    private int $seq = 3000100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- Счётчики партнёров ----------------

    /** Удалённые партнёры не попадают ни в общий счёт, ни в активных. */
    #[Test]
    public function deleted_partners_are_not_counted(): void
    {
        $kpi = $this->dashboard()['kpi'];

        $this->assertSame(2, $kpi['totalPartners'], 'живой и неактивный, без удалённого');
        $this->assertSame(1, $kpi['activePartners'], 'активен только один');
    }

    /** «Новые за месяц» считаются от начала текущего месяца. */
    #[Test]
    public function new_partners_are_counted_from_the_month_start(): void
    {
        $this->partner(3000010, ['dateCreated' => now()->startOfMonth()->addDay()]);
        $this->partner(3000011, ['dateCreated' => now()->subMonths(2)]);

        $this->assertSame(1, $this->dashboard()['kpi']['newPartnersMonth']);
    }

    // ---------------- Контракты ----------------

    /** Удалённые контракты не считаются. */
    #[Test]
    public function deleted_contracts_are_not_counted(): void
    {
        $this->contract(3000020, []);
        $this->contract(3000021, ['deletedAt' => now()]);

        $this->assertSame(1, $this->dashboard()['kpi']['totalContracts']);
    }

    // ---------------- Выручка ----------------

    /**
     * ⚠ Выручка текущего и прошлого месяца считаются по СВОИМ периодам: из
     * них выводится процент роста, и подмена периода даёт правдоподобный, но
     * неверный тренд.
     */
    #[Test]
    public function the_revenue_splits_current_and_previous_month(): void
    {
        $this->commission(now()->format('Y-m'), 10_000);
        $this->commission(now()->subMonth()->format('Y-m'), 4_000);

        $kpi = $this->dashboard()['kpi'];

        $this->assertEqualsWithDelta(10_000, $kpi['revenueMonth'], 0.01);
        $this->assertEqualsWithDelta(4_000, $kpi['revenuePrevMonth'], 0.01);
    }

    /** Мягко удалённые начисления в выручку не идут. */
    #[Test]
    public function deleted_commissions_do_not_count_as_revenue(): void
    {
        $this->commission(now()->format('Y-m'), 10_000);
        $this->commission(now()->format('Y-m'), 90_000, ['deletedAt' => now()]);

        $this->assertEqualsWithDelta(10_000, $this->dashboard()['kpi']['revenueMonth'], 0.01);
    }

    // ---------------- Доступ ----------------

    /** Партнёру административная сводка недоступна. */
    #[Test]
    public function a_partner_cannot_open_the_admin_dashboard(): void
    {
        $partner = new User();
        $partner->id = 3000901;
        $partner->email = 'nope@test.local';
        $partner->role = 'consultant';
        $partner->firstName = 'Не';
        $partner->lastName = 'Админ';
        $partner->password = bcrypt('secret123');
        $partner->save();

        $this->actingAs($partner, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();
    }

    // ================================================================

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()->json();
    }

    /** @param array<string, mixed> $attrs */
    private function partner(int $id, array $attrs = []): void
    {
        DB::table('consultant')->insert(array_merge([
            'id' => $id,
            'personName' => 'Партнёр ' . $id,
            'activity' => 1,
            'dateCreated' => now()->subYear(),
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function contract(int $id, array $attrs): void
    {
        DB::table('contract')->insert(array_merge([
            'id' => $id,
            'consultant' => self::ALIVE,
            'number' => 'AD-' . $id,
            'status' => 1,
            'ammount' => 1000,
            'createDate' => now(),
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function commission(string $month, float $amount, array $attrs = []): void
    {
        DB::table('commission')->insert(array_merge([
            'id' => $this->seq++,
            'consultant' => self::ALIVE,
            'transaction' => null,
            'dateMonth' => $month,
            'dateYear' => substr($month, 0, 4),
            'chainOrder' => 1,
            'amountRUB' => $amount,
            'personalVolume' => 0,
            'createdAt' => now(),
        ], $attrs));
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 3000900;
        $this->admin->email = 'admindash@test.local';
        $this->admin->firstName = 'Сводка';
        $this->admin->lastName = 'Тестовая';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        $this->partner(self::ALIVE);
        $this->partner(self::INACTIVE, ['activity' => 3]);
        $this->partner(self::DELETED, ['dateDeleted' => now()->subMonth()]);
    }
}
