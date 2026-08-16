<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Список контрактов (/admin/contracts).
 *
 * Сетка ПОД вынос метода в сервис. Здесь девятнадцать фильтров, и у трёх из
 * них нетривиальное поведение, которое легко потерять при переносе:
 *   - общий поиск идёт по ФИО партнёра и номеру, но НЕ по клиенту (у клиента
 *     свой фильтр) — иначе выдача «поплывёт»;
 *   - продукт и программа матчатся по ИМЕНИ (историчесские контракты хранят
 *     productName/programName, а не FK), причём отрицательный id означает
 *     запись только в каталоге;
 *   - прогноз активации — колонка БЕЗ времени, поэтому к верхней границе
 *     диапазона ' 23:59:59' НЕ дописывается, в отличие от остальных трёх.
 */
class ContractsListingTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 1500001;
    private const OTHER_PARTNER = 1500002;
    private const CLIENT = 1500010;
    private const OTHER_CLIENT = 1500011;

    private const PRODUCT = 1500020;
    private const PROGRAM = 1500030;

    /** Контракт с продуктом/программой из каталога и полным набором дат. */
    private const CONTRACT_A = 1500040;
    /** Контракт другого партнёра, без продукта. */
    private const CONTRACT_B = 1500041;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- Поиск и имена ----------------

    /** Общий поиск: ФИО партнёра ИЛИ номер контракта. Клиент — не сюда. */
    #[Test]
    public function generic_search_covers_partner_and_number_but_not_client(): void
    {
        $this->assertOnly('search=' . urlencode('Первый Партнёр'), [self::CONTRACT_A]);
        $this->assertOnly('search=CT-0002', [self::CONTRACT_B]);
        $this->assertOnly('search=' . urlencode('Клиент Первый'), [],
            'общий поиск не должен цеплять клиента — у него отдельный фильтр');
    }

    #[Test]
    public function dedicated_client_filters(): void
    {
        $this->assertOnly('client=' . self::CLIENT, [self::CONTRACT_A]);
        $this->assertOnly('client_name=' . urlencode('Клиент Первый'), [self::CONTRACT_A]);
        $this->assertOnly('consultant_name=' . urlencode('Второй'), [self::CONTRACT_B]);
    }

    #[Test]
    public function number_comment_and_status_filters(): void
    {
        $this->assertOnly('number=CT-0001', [self::CONTRACT_A]);
        $this->assertOnly('comment=' . urlencode('заливка'), [self::CONTRACT_A]);
        // status принимает массив.
        $this->assertOnly('status[]=1', [self::CONTRACT_A]);
    }

    // ---------------- Продукт и программа ----------------

    /**
     * Матч по ИМЕНИ, а не по FK: у исторических контрактов FK пуст, зато
     * заполнены productName/programName.
     */
    #[Test]
    public function product_and_program_match_by_name(): void
    {
        $this->assertOnly('product=' . self::PRODUCT, [self::CONTRACT_A]);
        $this->assertOnly('program=' . self::PROGRAM, [self::CONTRACT_A]);
    }

    /**
     * Отрицательный id = запись, которая есть только в каталоге. Резолвится
     * из products_catalog/programs_catalog по модулю значения.
     */
    #[Test]
    public function negative_id_resolves_from_the_catalog(): void
    {
        $this->assertOnly('product=-' . self::PRODUCT, [self::CONTRACT_A]);
        $this->assertOnly('program=-' . self::PROGRAM, [self::CONTRACT_A]);
    }

    /** Имя не разрезолвилось — падаем на FK, а не показываем всё подряд. */
    #[Test]
    public function unresolvable_product_falls_back_to_the_foreign_key(): void
    {
        $this->assertOnly('product=999999', []);
    }

    // ---------------- Диапазоны дат ----------------

    #[Test]
    public function date_ranges_use_their_own_columns(): void
    {
        $this->assertOnly('created_from=2026-06-01', [self::CONTRACT_B]);
        $this->assertOnly('opened_from=2026-07-01', [self::CONTRACT_B]);
        $this->assertOnly('closed_to=2026-05-31', [self::CONTRACT_A]);
    }

    /** Верхняя граница трёх «временных» колонок включает весь день. */
    #[Test]
    public function upper_bound_includes_the_whole_day(): void
    {
        // Контракт A создан 2026-01-15 10:30 — с границей-полуночью он бы выпал.
        $this->assertOnly('created_to=2026-01-15', [self::CONTRACT_A]);
    }

    /**
     * Прогноз активации — колонка БЕЗ времени, и ' 23:59:59' к верхней границе
     * не дописывается, в отличие от трёх остальных диапазонов.
     *
     * Проверено: дописать его было бы безобидно (Postgres приводит строку к
     * date, и хвост времени просто отбрасывается), так что тест здесь ловит не
     * поломку выдачи, а сам факт, что колонка date-only.
     */
    #[Test]
    public function activation_forecast_range_is_date_only(): void
    {
        $this->assertOnly('forecast_to=2026-03-10', [self::CONTRACT_A]);
        $this->assertOnly('forecast_from=2026-03-10', [self::CONTRACT_A, self::CONTRACT_B]);
    }

    // ---------------- Итоги и структура ----------------

    /** Сумма считается по ОТФИЛЬТРОВАННОМУ набору и до пагинации. */
    #[Test]
    public function amount_sum_follows_the_filters(): void
    {
        $all = $this->list('');
        $this->assertEqualsWithDelta(300_000.0, (float) $all->json('amountSum'), 0.01);

        $filtered = $this->list('number=CT-0001');
        $this->assertEqualsWithDelta(100_000.0, (float) $filtered->json('amountSum'), 0.01);
    }

    #[Test]
    public function row_carries_names_and_status(): void
    {
        $rows = collect($this->list('')->json('data'))->keyBy('id');

        $this->assertSame('Первый Партнёр', $rows[self::CONTRACT_A]['consultantName']);
        $this->assertSame('Клиент Первый', $rows[self::CONTRACT_A]['clientName']);
        $this->assertSame('Тестовый продукт', $rows[self::CONTRACT_A]['productName']);
        $this->assertSame('Активирован', $rows[self::CONTRACT_A]['statusName']);
    }

    // ================================================================

    /** @param list<int> $expected */
    private function assertOnly(string $query, array $expected, string $why = ''): void
    {
        $ids = array_column($this->list($query)->json('data'), 'id');
        sort($ids);
        sort($expected);

        $this->assertSame($expected, $ids, $why ?: ('фильтр: ' . $query));
    }

    private function list(string $query)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/contracts' . ($query ? '?' . $query : ''))
            ->assertOk();
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 1500900;
        $this->admin->email = 'contracts@test.local';
        $this->admin->firstName = 'Контракты';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('consultant')->insert([
            ['id' => self::PARTNER, 'personName' => 'Первый Партнёр',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00'],
            ['id' => self::OTHER_PARTNER, 'personName' => 'Второй Партнёр',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00'],
        ]);

        DB::table('client')->insert([
            ['id' => self::CLIENT, 'consultant' => self::PARTNER, 'personName' => 'Клиент Первый'],
            ['id' => self::OTHER_CLIENT, 'consultant' => self::OTHER_PARTNER, 'personName' => 'Клиент Второй'],
        ]);

        // legacy product/program — представления над каталогом.
        DB::table('products_catalog')->insert([
            'id' => self::PRODUCT, 'name' => 'Тестовый продукт',
        ]);
        DB::table('programs_catalog')->insert([
            'id' => self::PROGRAM, 'product_id' => self::PRODUCT,
            'name' => 'Тестовая программа', 'provider_name' => 'Тестовый поставщик',
        ]);

        // ⚠ contractStatus НЕ заводим: справочник уже лежит в schema-фикстуре
        // (11 строк с прода), id=1 — «Активирован».

        DB::table('contract')->insert([
            'id' => self::CONTRACT_A,
            'consultant' => self::PARTNER, 'consultantName' => 'Первый Партнёр',
            'client' => self::CLIENT, 'clientName' => 'Клиент Первый',
            'number' => 'CT-0001', 'comment' => 'Ручная заливка',
            'status' => 1, 'ammount' => 100_000,
            // FK пуст, имена заполнены — как у исторических контрактов.
            'productName' => 'Тестовый продукт', 'programName' => 'Тестовая программа',
            'createDate' => '2026-01-15 10:30:00',
            'openDate' => '2026-02-01 00:00:00',
            'closeDate' => '2026-05-01 00:00:00',
            'activation_forecast' => '2026-03-10',
        ]);
        DB::table('contract')->insert([
            'id' => self::CONTRACT_B,
            'consultant' => self::OTHER_PARTNER, 'consultantName' => 'Второй Партнёр',
            'client' => self::OTHER_CLIENT, 'clientName' => 'Клиент Второй',
            'number' => 'CT-0002', 'comment' => 'Импорт',
            'status' => 2, 'ammount' => 200_000,
            'createDate' => '2026-06-20 00:00:00',
            'openDate' => '2026-07-05 00:00:00',
            'closeDate' => '2026-08-01 00:00:00',
            'activation_forecast' => '2026-09-15',
        ]);
    }
}
