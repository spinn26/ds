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

    /**
     * Плановая и фактическая терминация — разные вещи. Плановая считается
     * (yearPeriodEnd активного), фактическая лежит колонкой dateDeterministic.
     *
     * ⚠ Раньше здесь ожидался REGISTERED: фильтр ходил в
     * consultant.dateDeterministicPlan, и сетка закрепляла ровно тот баг, на
     * который пожаловались 03.09.2026 — выбор ноября возвращал строки с
     * июнем следующего года в колонке «Будет терминирован».
     */
    #[Test]
    public function plan_and_term_ranges_use_different_columns(): void
    {
        $this->assertOnly('plan_from=2026-10-01', [self::ACTIVE]);
        $this->assertOnly('term_from=2026-07-01', [self::ACTIVE]);
    }

    /**
     * Главное свойство фильтра: он отбирает по ТОЙ ЖЕ дате, которую строка
     * показывает в колонке «Будет терминирован». Иначе оператор выбирает
     * ноябрь, а в выдаче видит июнь — с чего и начался разбор.
     */
    #[Test]
    public function plan_filter_matches_the_deadline_shown_in_the_row(): void
    {
        $this->assertOnly('plan_from=2026-11-01&plan_to=2026-11-30', [self::ACTIVE]);

        $rows = collect($this->list('plan_from=2026-11-01&plan_to=2026-11-30')->json('data'))->keyBy('id');
        $this->assertSame('2026-11-20', $rows[self::ACTIVE]['willTerminate'],
            'в выдаче обязана быть та же дата, по которой фильтровали');
    }

    /**
     * Legacy-колонка dateDeterministicPlan (окно активации из самоактивации)
     * в фильтре больше не участвует: у активного она стоит на 2027-01-01, и
     * запрос по январю не должен возвращать никого.
     */
    #[Test]
    public function the_legacy_plan_column_is_no_longer_used(): void
    {
        $this->assertOnly('plan_from=2027-01-01&plan_to=2027-01-31', []);
    }

    /**
     * Сортировка по колонке «Будет терминирован» ходит тем же выражением.
     * Проверяем в первую очередь, что оно вообще валидный SQL в ORDER BY:
     * сломанный CASE упал бы пятисоткой (ключ willTerminate до этого в
     * whitelist не входил, и сортировка молча уезжала на ФИО).
     */
    #[Test]
    public function rows_sort_by_the_computed_deadline(): void
    {
        foreach (['asc', 'desc'] as $dir) {
            $ids = array_column(
                $this->list("sort_by=willTerminate&sort_dir={$dir}")->json('data'), 'id'
            );
            // У зарегистрированного дедлайна нет → NULLS LAST уводит его в конец
            // при любом направлении.
            $this->assertSame([self::ACTIVE, self::REGISTERED], $ids, "направление {$dir}");
        }
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
        $this->assertSame('2026-11-20', $rows[self::ACTIVE]['willTerminate'], 'активному — конец годового периода');
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
            // Годовой период сдвинут раннером — плановая терминация в ноябре,
            // а не «активация + год» (2027-03-01).
            'yearPeriodEnd' => '2026-11-20 00:00:00',
            // Legacy-колонка окна активации: намеренно расходится с реальным
            // дедлайном, чтобы поймать возврат фильтра на неё.
            'dateDeterministicPlan' => '2027-01-01 00:00:00',
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
