<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Раздел «Статусы партнёров» (/admin/partner-statuses).
 *
 * Сетка ПОД вынос метода в сервис. Здесь десять фильтров, и восемь из них —
 * диапазоны дат по четырём разным колонкам. Их легко перепутать при переносе,
 * поэтому каждая пара проверяется отдельно, причём ОБЕ границы.
 *
 * Отдельно закреплено, что верхняя граница включает весь день (' 23:59:59'):
 * без этого последний день диапазона терялся — грабля уже случалась в отчётах.
 */
class PartnerStatusesListingTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVE = 1300001;
    private const REGISTERED = 1300002;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    #[Test]
    public function summary_counts_partners_by_activity(): void
    {
        $r = $this->list('');

        $r->assertOk()->assertJsonStructure(['summary', 'data', 'total']);
        $this->assertSame(2, $r->json('total'));
    }

    #[Test]
    public function search_and_activity_filters(): void
    {
        $this->assertOnly('search=' . urlencode('Активный'), [self::ACTIVE]);
        $this->assertOnly('activity=1', [self::ACTIVE]);
        $this->assertOnly('activity=4', [self::REGISTERED]);
    }

    /** Диапазон по дате регистрации. */
    #[Test]
    public function created_range_filters_both_bounds(): void
    {
        $this->assertOnly('created_from=2026-05-01', [self::REGISTERED]);
        $this->assertOnly('created_to=2026-01-31', [self::ACTIVE]);
    }

    /** Диапазон по дате активации — отдельная колонка. */
    #[Test]
    public function activity_range_uses_its_own_column(): void
    {
        $this->assertOnly('activity_from=2026-03-01', [self::ACTIVE]);
        // У зарегистрированного даты активации нет — в диапазон он не попадает.
        $this->assertOnly('activity_to=2026-12-31', [self::ACTIVE]);
    }

    /** Плановая дата терминации и фактическая — тоже разные колонки. */
    #[Test]
    public function plan_and_term_ranges_use_different_columns(): void
    {
        $this->assertOnly('plan_from=2026-10-01', [self::REGISTERED]);
        $this->assertOnly('term_from=2026-07-01', [self::ACTIVE]);
    }

    /**
     * ⚠ Верхняя граница диапазона включает ВЕСЬ день: к дате дописывается
     * ' 23:59:59'. Без этого запись, созданная в течение последнего дня,
     * выпадала из выборки.
     */
    #[Test]
    public function upper_bound_includes_the_whole_day(): void
    {
        // Активный создан 2026-01-15 10:30 — с границей-полуночью он бы выпал.
        $this->assertOnly('created_to=2026-01-15', [self::ACTIVE]);
    }

    #[Test]
    public function row_carries_email_and_termination_forecast(): void
    {
        $rows = collect($this->list('')->json('data'))->keyBy('id');

        $this->assertSame('login@status.local', $rows[self::ACTIVE]['email'], 'почта из WebUser');
        $this->assertSame('own@status.local', $rows[self::REGISTERED]['email'], 'без логина — своя колонка');
        $this->assertSame('2027-03-01', $rows[self::ACTIVE]['willTerminate'], 'активному +год от активации');
        $this->assertNull($rows[self::REGISTERED]['willTerminate'], 'зарегистрированному прогноза нет');
    }

    // ================================================================

    /** @param list<int> $expected */
    private function assertOnly(string $query, array $expected): void
    {
        $ids = array_column($this->list($query)->json('data'), 'id');
        sort($ids);
        sort($expected);

        $this->assertSame($expected, $ids, 'фильтр: ' . $query);
    }

    private function list(string $query)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/partner-statuses' . ($query ? '?' . $query : ''))
            ->assertOk();
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 1300900;
        $this->admin->email = 'statuses@test.local';
        $this->admin->firstName = 'Статусы';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        $webUser = new User();
        $webUser->id = 1300800;
        $webUser->email = 'login@status.local';
        $webUser->firstName = 'Логин';
        $webUser->lastName = 'Активного';
        $webUser->role = 'consultant';
        $webUser->password = bcrypt('secret123');
        $webUser->save();

        DB::table('consultant')->insert([
            'id' => self::ACTIVE,
            'webUser' => $webUser->id,
            'personName' => 'Активный Партнёр',
            'activity' => 1,
            'dateCreated' => '2026-01-15 10:30:00',
            'dateActivity' => '2026-03-01 00:00:00',
            'dateDeterministic' => '2026-07-10 00:00:00',
        ]);
        DB::table('consultant')->insert([
            'id' => self::REGISTERED,
            'webUser' => null,
            'personName' => 'Новый Партнёр',
            'activity' => 4,
            'email' => 'own@status.local',
            'dateCreated' => '2026-05-20 00:00:00',
            'dateDeterministicPlan' => '2026-10-05 00:00:00',
        ]);
    }
}
