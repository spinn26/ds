<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SMOKE-тесты admin-эндпоинтов (Этап 0).
 *
 * Это не проверка бизнес-логики, а страховка под Этап 3: именно
 * AdminDataController (4188 строк), AdminFinanceController и
 * ProductSalesMatrixController будут разбираться на сервисы, и нужен замок на
 * контракт ответа — код, набор верхнеуровневых ключей и число строк на
 * ФИКСИРОВАННОЙ выборке.
 *
 * Выборка одна на все тесты (см. seedDataset): 2 партнёра, 2 клиента,
 * 2 контракта, 2 транзакции, 2 комиссии. Любой сдвиг числа строк после
 * рефакторинга означает, что фильтр или join поехали.
 */
class AdminEndpointsSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_USER = 950001;
    private const PARTNER_USER = 950002;

    private const PARTNER_A = 950010;
    private const PARTNER_B = 950011;
    private const CLIENT_A = 950020;
    private const CLIENT_B = 950021;
    private const CONTRACT_A = 950030;
    private const CONTRACT_B = 950031;
    private const PRODUCT = 950050;
    private const PROGRAM = 950060;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDataset();
    }

    // ================================================================
    // Контракт ответа: код + ключи + число строк
    // ================================================================

    #[Test]
    public function partners_listing_returns_seeded_rows(): void
    {
        $r = $this->admin('/admin/partners');

        $r->assertOk()->assertJsonStructure(['data', 'total']);
        $this->assertSame(2, $r->json('total'), 'оба живых партнёра, удалённый исключён');
        $this->assertCount(2, $r->json('data'));
        $this->assertNotContains(
            'Удалённый Партнёр',
            array_column($r->json('data'), 'personName'),
            'мягко удалённый партнёр не должен попадать в выдачу'
        );
    }

    #[Test]
    public function clients_listing_returns_seeded_rows(): void
    {
        $r = $this->admin('/admin/clients');

        $r->assertOk()->assertJsonStructure(['data', 'total']);
        $this->assertSame(2, $r->json('total'));
    }

    #[Test]
    public function contracts_listing_returns_rows_and_sum(): void
    {
        $r = $this->admin('/admin/contracts');

        $r->assertOk()->assertJsonStructure(['data', 'total', 'amountSum']);
        $this->assertSame(2, $r->json('total'), 'удалённый контракт исключён');
    }

    #[Test]
    public function users_listing_returns_seeded_accounts(): void
    {
        $r = $this->admin('/admin/users');

        $r->assertOk()->assertJsonStructure(['data', 'total']);
        $this->assertSame(2, $r->json('total'), 'админ + партнёрский аккаунт');
    }

    #[Test]
    public function transactions_listing_returns_rows_and_aggregates(): void
    {
        $r = $this->admin('/admin/transactions');

        $r->assertOk()->assertJsonStructure(['data', 'total', 'aggregates']);
        $this->assertSame(2, $r->json('total'));
    }

    #[Test]
    public function commissions_listing_returns_rows(): void
    {
        $r = $this->admin('/admin/commissions');

        $r->assertOk()->assertJsonStructure(['data', 'total']);
        $this->assertSame(2, $r->json('total'));
    }

    #[Test]
    public function partner_statuses_returns_summary_and_rows(): void
    {
        $r = $this->admin('/admin/partner-statuses');

        $r->assertOk()->assertJsonStructure(['summary', 'data', 'total']);
        $this->assertSame(2, $r->json('total'));
    }

    #[Test]
    public function dashboard_returns_its_blocks(): void
    {
        $this->admin('/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['kpi', 'charts', 'recentActivity']);
    }

    #[Test]
    public function qualifications_returns_rows_and_month_labels(): void
    {
        $this->admin('/admin/qualifications')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'monthLabel', 'prevMonthLabel']);
    }

    #[Test]
    public function pool_page_returns_participants(): void
    {
        $this->admin('/admin/pool?year=2026&month=7')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    #[Test]
    public function payment_registry_returns_period_items_and_totals(): void
    {
        $this->admin('/admin/payment-registry?year=2026&month=7')
            ->assertOk()
            ->assertJsonStructure(['year', 'month', 'items', 'totals']);
    }

    #[Test]
    public function periods_and_requisites_respond(): void
    {
        $this->admin('/admin/periods')->assertOk()->assertJsonStructure(['data']);
        $this->admin('/admin/requisites')->assertOk()->assertJsonStructure(['data', 'total']);
    }

    #[Test]
    public function sales_matrix_fact_returns_its_shape(): void
    {
        $this->admin('/admin/reports/sales-matrix/fact?from=2026-06&to=2026-07')
            ->assertOk()
            ->assertJsonStructure(['period', 'rows', 'grandTotals']);
    }

    #[Test]
    public function partner_matrix_fact_returns_its_shape(): void
    {
        $this->admin('/admin/reports/partner-matrix/fact?from=2026-06&to=2026-07')
            ->assertOk()
            ->assertJsonStructure(['months', 'structures', 'grand']);
    }

    /**
     * Фильтр по структуре режет выдачу по ПОДДЕРЕВУ корня, а не по точному id.
     * Обход дерева здесь общий (ConsultantTreeService::subtreeIds), поэтому
     * тест сторожит и его.
     */
    #[Test]
    public function partner_matrix_structure_filter_uses_the_subtree(): void
    {
        $r = $this->admin(
            '/admin/reports/partner-matrix/fact?from=2026-06&to=2026-07'
            . '&structures[]=' . self::PARTNER_A
        );

        $r->assertOk();
        $fcIds = [];
        foreach ($r->json('structures') as $structure) {
            foreach ($structure['fcs'] ?? [] as $fc) {
                $fcIds[] = (int) $fc['fcId'];
            }
        }
        $this->assertContains(self::PARTNER_A, $fcIds, 'сам корень входит в поддерево');
        $this->assertNotContains(self::PARTNER_B, $fcIds, 'чужая структура отфильтрована');
    }

    // ================================================================
    // Фильтры: контракт «параметр → выборка» тоже под замком
    // ================================================================

    #[Test]
    public function partner_search_filter_narrows_the_result(): void
    {
        $r = $this->admin('/admin/partners?search=' . urlencode('Второй'));

        $r->assertOk();
        $this->assertSame(1, $r->json('total'), 'поиск сужает выдачу до одного партнёра');
    }

    #[Test]
    public function transactions_date_filter_narrows_the_result(): void
    {
        $r = $this->admin('/admin/transactions?date_from=2026-07-01&date_to=2026-07-31');

        $r->assertOk();
        $this->assertSame(1, $r->json('total'), 'в июле одна транзакция из двух');
    }

    // ================================================================
    // Правка транзакции: деньги + пересчёт
    // ================================================================

    /**
     * Правка суммы пересчитывает цепочку комиссий и честно об этом сообщает.
     */
    #[Test]
    public function editing_a_transaction_recalculates_commissions(): void
    {
        DB::table('programs_catalog')->where('id', self::PROGRAM)->update(['ds_percent' => 40]);

        $r = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/transactions/950041', ['amount' => 300_000]);

        $r->assertOk();
        $this->assertTrue($r->json('recalculated'), 'пересчёт состоялся');

        $this->assertEqualsWithDelta(
            300_000.0,
            (float) DB::table('transaction')->where('id', 950041)->value('amountRUB'),
            0.01,
            'amountRUB пересчитан от новой суммы'
        );
    }

    /**
     * Пересчёт не удался — эндпоинт сообщает об этом, а ранее начисленные
     * комиссии остаются на месте.
     *
     * ⚠ Регресс-тест: раньше комиссии сносились ДО вызова калькулятора, а его
     * ответ не проверялся вовсе — оператор видел «Транзакция обновлена» при
     * фактически обнулённых начислениях.
     */
    #[Test]
    public function failed_recalculation_is_reported_and_keeps_commissions(): void
    {
        // Тарифа %ДС нет ни в каталоге, ни в dsCommission → расчёт не пройдёт.
        $before = DB::table('commission')->whereNull('deletedAt')->count();

        $r = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/transactions/950041', ['amount' => 300_000]);

        $r->assertOk();
        $this->assertFalse($r->json('recalculated'));
        $this->assertStringContainsString('не пересчитаны', (string) $r->json('message'));

        $this->assertSame(
            $before,
            DB::table('commission')->whereNull('deletedAt')->count(),
            'начисленное не стёрто'
        );
    }

    // ================================================================
    // Доступ
    // ================================================================

    #[Test]
    public function guest_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/admin/partners')->assertStatus(401);
    }

    #[Test]
    public function partner_without_staff_role_is_forbidden(): void
    {
        $partner = User::find(self::PARTNER_USER);

        $this->actingAs($partner, 'sanctum')
            ->getJson('/api/v1/admin/partners')
            ->assertStatus(403);
    }

    // ================================================================

    private function admin(string $path): TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1' . $path);
    }

    private function seedDataset(): void
    {
        $this->admin = $this->makeUser(self::ADMIN_USER, 'admin@test.local', 'Админ', 'admin');
        $this->makeUser(self::PARTNER_USER, 'partner@test.local', 'Партнёр', 'consultant');

        DB::table('consultant')->insert([
            [
                'id' => self::PARTNER_A, 'webUser' => self::PARTNER_USER,
                'personName' => 'Первый Партнёр', 'activity' => 1, 'active' => true,
                'dateCreated' => '2026-01-01 00:00:00',
            ],
            [
                'id' => self::PARTNER_B, 'webUser' => null,
                'personName' => 'Второй Партнёр', 'activity' => 1, 'active' => true,
                'dateCreated' => '2026-02-01 00:00:00',
            ],
        ]);

        // Мягко удалённые строки-ловушки: во всех выдачах их быть НЕ должно.
        // Именно этот фильтр легче всего потерять, перенося запрос в сервис.
        DB::table('consultant')->insert([
            'id' => 950012, 'personName' => 'Удалённый Партнёр', 'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00', 'dateDeleted' => '2026-03-01 00:00:00',
        ]);
        DB::table('contract')->insert([
            'id' => 950032, 'consultant' => self::PARTNER_A, 'number' => 'SM-DEL',
            'deletedAt' => '2026-03-01 00:00:00',
        ]);

        DB::table('client')->insert([
            [
                'id' => self::CLIENT_A, 'consultant' => self::PARTNER_A,
                'consultantName' => 'Первый Партнёр', 'personName' => 'Клиент Первый',
            ],
            [
                'id' => self::CLIENT_B, 'consultant' => self::PARTNER_B,
                'consultantName' => 'Второй Партнёр', 'personName' => 'Клиент Второй',
            ],
        ]);

        // ⚠ legacy product/program — это ПРЕДСТАВЛЕНИЯ над каталогом, писать
        // надо в таблицы. Отчёты-матрицы джойнят их INNER JOIN'ом: без
        // продукта и программы контракт в выдачу не попадает вовсе.
        DB::table('products_catalog')->insert([
            'id' => self::PRODUCT, 'name' => 'Тестовый продукт',
        ]);
        DB::table('programs_catalog')->insert([
            'id' => self::PROGRAM, 'product_id' => self::PRODUCT,
            'name' => 'Тестовая программа', 'provider_name' => 'Тестовый поставщик',
        ]);

        DB::table('contract')->insert([
            [
                'id' => self::CONTRACT_A, 'consultant' => self::PARTNER_A,
                'client' => self::CLIENT_A, 'consultantName' => 'Первый Партнёр',
                'clientName' => 'Клиент Первый', 'number' => 'SM-0001',
                'product' => self::PRODUCT, 'program' => self::PROGRAM,
                'openDate' => '2026-06-01',
            ],
            [
                'id' => self::CONTRACT_B, 'consultant' => self::PARTNER_B,
                'client' => self::CLIENT_B, 'consultantName' => 'Второй Партнёр',
                'clientName' => 'Клиент Второй', 'number' => 'SM-0002',
                'product' => self::PRODUCT, 'program' => self::PROGRAM,
                'openDate' => '2026-07-01',
            ],
        ]);

        // Транзакции в РАЗНЫХ месяцах — на них проверяется фильтр по датам.
        DB::table('transaction')->insert([
            [
                'id' => 950040, 'contract' => self::CONTRACT_A,
                'amount' => 100_000, 'amountRUB' => 100_000, 'currency' => 67,
                'currencyRate' => 1, 'commissionsAmountRUB' => 40_000,
                'date' => '2026-06-15', 'dateMonth' => '2026-06', 'dateYear' => '2026',
            ],
            [
                'id' => 950041, 'contract' => self::CONTRACT_B,
                'amount' => 200_000, 'amountRUB' => 200_000, 'currency' => 67,
                'currencyRate' => 1, 'commissionsAmountRUB' => 80_000,
                'date' => '2026-07-15', 'dateMonth' => '2026-07', 'dateYear' => '2026',
            ],
        ]);

        DB::table('commission')->insert([
            [
                'transaction' => 950040, 'consultant' => self::PARTNER_A,
                'chainOrder' => 1, 'type' => 'transaction',
                'personalVolume' => 400, 'groupVolume' => 400,
                'groupBonus' => 100, 'groupBonusRub' => 10_000, 'amountRUB' => 10_000,
                'date' => '2026-06-15', 'dateMonth' => '2026-06', 'dateYear' => '2026',
                'createdAt' => now(),
            ],
            [
                'transaction' => 950041, 'consultant' => self::PARTNER_B,
                'chainOrder' => 1, 'type' => 'transaction',
                'personalVolume' => 800, 'groupVolume' => 800,
                'groupBonus' => 200, 'groupBonusRub' => 20_000, 'amountRUB' => 20_000,
                'date' => '2026-07-15', 'dateMonth' => '2026-07', 'dateYear' => '2026',
                'createdAt' => now(),
            ],
        ]);
    }

    private function makeUser(int $id, string $email, string $name, string $role): User
    {
        $user = new User();
        $user->id = $id;
        $user->email = $email;
        $user->firstName = $name;
        $user->lastName = 'Тестовый';
        $user->role = $role;
        $user->password = bcrypt('secret123');
        $user->save();

        return $user;
    }
}
