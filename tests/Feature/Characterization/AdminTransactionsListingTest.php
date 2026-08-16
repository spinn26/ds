<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Список транзакций (/admin/transactions) — 445 строк, самый большой метод
 * проекта и путь ДЕНЕГ: из него читают итоги «Прибыль», «Комиссия»,
 * «Удержание ДС».
 *
 * Сетка ПОД вынос. Главное, что она держит, — честный подсчёт цепочки
 * комиссий. На этом уже был инцидент: ярлык «netRevenueRUB - profitRUB»
 * вместо суммы по цепочке давал 343к вместо 7к.
 *
 * Дедуп DISTINCT ON, который стоит во всех трёх подсчётах цепочки, сегодня
 * подстраховка: активные дубли невозможны на уровне БД - есть частичный
 * уникальный индекс. Это тоже проверяется, чтобы дедуп не сняли «за
 * ненадобностью», не заметив, что держится всё на индексе.
 *
 * Семантика колонок утверждена владельцем 2026-07-13 и здесь закреплена:
 *   «Комиссия» (dsWithholdingRUB) = Σ по ВСЕЙ цепочке, включая отрыв;
 *   «Комиссия ФК» (partnerCommissionRUB) = только прямой партнёр (chainOrder=1).
 */
class AdminTransactionsListingTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2100001;
    private const UPLINE = 2100002;
    private const CLIENT = 2100003;
    private const PRODUCT = 2100010;
    private const PROGRAM = 2100020;
    private const CONTRACT = 2100030;
    private const TX = 2100040;

    private User $admin;
    private int $seq = 2100100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- Дедуп цепочки ----------------

    /**
     * «Комиссия» суммирует ВСЮ цепочку, «Комиссия ФК» - только прямого
     * партнёра. Разница между ними и есть отрыв вышестоящих наставников.
     */
    #[Test]
    public function the_chain_total_and_the_direct_partner_differ_by_the_upline(): void
    {
        $this->commission(['chainOrder' => 1, 'consultant' => self::PARTNER, 'amountRUB' => 1_200]);
        // Наставник в отрыве.
        $this->commission(['chainOrder' => 2, 'consultant' => self::UPLINE, 'amountRUB' => 300]);

        $body = $this->list();
        $row = $body['data'][0];

        $this->assertEqualsWithDelta(1_500, $row['dsWithholdingRUB'], 0.01, 'вся цепочка: 1200 + 300');
        $this->assertEqualsWithDelta(1_200, $row['partnerCommissionRUB'], 0.01, 'прямой партнёр — только свежая строка');
        $this->assertEqualsWithDelta(1_500, $body['aggregates']['dsWithholdingRUB'], 0.01);
        $this->assertEqualsWithDelta(1_200, $body['aggregates']['partnerCommissionRUB'], 0.01);
    }

    /**
     * ⚠ Активных дублей в цепочке быть не может - держит частичный уникальный
     * индекс по (транзакция, партнёр, порядок) среди неудалённых. Дедуп
     * DISTINCT ON в запросах остаётся подстраховкой; снимая его, помните, что
     * защита от задвоения итогов лежит здесь.
     */
    #[Test]
    public function active_duplicates_are_impossible_at_the_database_level(): void
    {
        $this->commission(['chainOrder' => 1, 'consultant' => self::PARTNER, 'amountRUB' => 1_000]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->commission(['chainOrder' => 1, 'consultant' => self::PARTNER, 'amountRUB' => 1_200]);
    }

    /** Мягко удалённые строки цепочки в расчёт не идут. */
    #[Test]
    public function soft_deleted_commission_rows_are_ignored(): void
    {
        $this->commission(['chainOrder' => 1, 'consultant' => self::PARTNER, 'amountRUB' => 1_000]);
        $this->commission(['chainOrder' => 2, 'consultant' => self::UPLINE, 'amountRUB' => 900,
            'deletedAt' => '2026-03-10 00:00:00']);

        $body = $this->list();

        $this->assertEqualsWithDelta(1_000, $body['data'][0]['dsWithholdingRUB'], 0.01, 'строка');
        // Итоги считаются отдельными запросами — их фильтр по deletedAt
        // приходится проверять отдельно, иначе он снимается незаметно.
        $this->assertEqualsWithDelta(1_000, $body['aggregates']['dsWithholdingRUB'], 0.01, 'итог по цепочке');
        $this->assertEqualsWithDelta(1_000, $body['aggregates']['partnerCommissionRUB'], 0.01, 'итог прямого партнёра');
    }

    /**
     * «Прибыль» считается живьём: доход ДС минус Σ цепочки. Денормализованное
     * поле profitRUB для этого не годится — оно отстаёт после ночных штрафов.
     */
    #[Test]
    public function profit_is_computed_live_not_taken_from_the_column(): void
    {
        DB::table('transaction')->where('id', self::TX)->update([
            'commissionsAmountRUB' => 10_000,
            'profitRUB' => 999_999,   // заведомо неверное денорм-значение
        ]);
        $this->commission(['chainOrder' => 1, 'consultant' => self::PARTNER, 'amountRUB' => 4_000]);

        $body = $this->list();

        $this->assertEqualsWithDelta(6_000, $body['data'][0]['profitRUB'], 0.01);
        $this->assertEqualsWithDelta(6_000, $body['aggregates']['profitRUB'], 0.01);
    }

    /** Без цепочки колонки партнёра нулевые, а не отсутствуют. */
    #[Test]
    public function a_transaction_without_a_chain_shows_zeros(): void
    {
        $row = $this->list()['data'][0];

        $this->assertSame(0, $row['partnerCommissionRUB']);
        $this->assertNull($row['partnerPV']);
        $this->assertEqualsWithDelta(0, $row['dsWithholdingRUB'], 0.01);
    }

    // ---------------- Итоги считаются по фильтру ----------------

    /** Агрегаты берутся по всему отфильтрованному набору, а не по странице. */
    #[Test]
    public function aggregates_follow_the_filter_not_the_page(): void
    {
        $this->transaction(['amountRUB' => 100, 'commissionsAmountRUB' => 10, 'comment' => 'первая']);
        $this->transaction(['amountRUB' => 200, 'commissionsAmountRUB' => 20, 'comment' => 'вторая']);

        $all = $this->list(['per_page' => 1]);
        $this->assertCount(1, $all['data'], 'страница из одной строки');
        $this->assertEqualsWithDelta(100_300, $all['aggregates']['amountRUB'], 0.01,
            'а итог — по всем трём строкам, включая основную из фикстуры');

        $filtered = $this->list(['comment' => 'перв']);
        $this->assertEqualsWithDelta(100, $filtered['aggregates']['amountRUB'], 0.01);
    }

    // ---------------- Фильтры ----------------

    /** Общий поиск — партнёр и номер контракта, но НЕ клиент. */
    #[Test]
    public function the_generic_search_leaves_the_client_alone(): void
    {
        $this->assertSame(1, $this->list(['search' => 'Транзакций'])['total']);
        $this->assertSame(1, $this->list(['search' => 'TX-0001'])['total']);
        $this->assertSame(0, $this->list(['search' => 'Клиентов'])['total'],
            'у клиента отдельный фильтр — в общий поиск он не входит');
        $this->assertSame(1, $this->list(['client' => 'Клиентов'])['total']);
    }

    /** Верхняя граница даты включает весь день. */
    #[Test]
    public function the_upper_date_bound_covers_the_whole_day(): void
    {
        DB::table('transaction')->where('id', self::TX)->update(['date' => '2026-03-15 18:30:00']);

        $this->assertSame(1, $this->list(['date_to' => '2026-03-15'])['total']);
        $this->assertSame(0, $this->list(['date_to' => '2026-03-14'])['total']);
    }

    /** hide_zero убирает нулевые строки аплайна. */
    #[Test]
    public function hide_zero_drops_the_zero_upline_rows(): void
    {
        $this->transaction(['amountRUB' => 0, 'commissionsAmountRUB' => 0]);

        $this->assertSame(2, $this->list()['total']);
        $this->assertSame(1, $this->list(['hide_zero' => 1])['total']);
    }

    /**
     * Фильтр «Партнёр в цепочке» спускается ВНИЗ по структуре: у наставника
     * показываются транзакции его нижестоящих.
     */
    #[Test]
    public function the_chain_partner_filter_walks_down_the_structure(): void
    {
        $this->assertSame(1, $this->list(['chain_partner' => 'Наставников'])['total'],
            'транзакция нижестоящего видна по имени наставника');
        $this->assertSame(0, $this->list(['chain_partner' => 'Неизвестный'])['total']);
    }

    // ---------------- Названия из каталога ----------------

    /**
     * Имя продукта приходит из каталога.
     *
     * ⚠ Порядок фолбэка «каталог, потом legacy» сейчас НЕНАБЛЮДАЕМ: legacy
     * `product` стал представлением над products_catalog, так что оба
     * источника отдают одну и ту же строку. Тест держит только сам факт, что
     * имя подставляется; если представление когда-нибудь снимут, порядок
     * фолбэка снова станет значимым — и его придётся проверять отдельно.
     */
    #[Test]
    public function the_product_name_comes_from_the_catalog(): void
    {
        $this->assertSame('Каталожный продукт', $this->list()['data'][0]['productName']);
    }

    // ================================================================

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function list(array $params = []): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/transactions?' . http_build_query($params))
            ->assertOk()->json();
    }

    /** @param array<string, mixed> $attrs */
    private function commission(array $attrs): void
    {
        DB::table('commission')->insert(array_merge([
            'id' => $this->seq++,
            'transaction' => self::TX,
            'personalVolume' => 0, 'groupVolume' => 0, 'groupBonus' => 0,
            'createdAt' => '2026-03-01 00:00:00',
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function transaction(array $attrs): int
    {
        $id = $this->seq++;
        DB::table('transaction')->insert(array_merge([
            'id' => $id,
            'contract' => self::CONTRACT,
            'date' => '2026-03-15 12:00:00',
            'dateMonth' => '2026-03', 'dateYear' => 2026,
            'amountRUB' => 0, 'commissionsAmountRUB' => 0,
            'netRevenueRUB' => 0, 'profitRUB' => 0, 'personalVolume' => 0,
        ], $attrs));

        return $id;
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 2100900;
        $this->admin->email = 'txlist@test.local';
        $this->admin->firstName = 'Транзакции';
        $this->admin->lastName = 'Тестовые';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('products_catalog')->insert([
            'id' => self::PRODUCT, 'name' => 'Каталожный продукт', 'active' => true,
            'legacy_product_id' => self::PRODUCT,
        ]);
        DB::table('programs_catalog')->insert([
            'id' => self::PROGRAM, 'product_id' => self::PRODUCT,
            'name' => 'Каталожная программа', 'active' => true,
            'legacy_program_id' => self::PROGRAM, 'provider_name' => 'Поставщик',
        ]);

        DB::table('consultant')->insert([
            ['id' => self::UPLINE, 'personName' => 'Наставников Наставник',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00'],
        ]);
        DB::table('consultant')->insert([
            ['id' => self::PARTNER, 'personName' => 'Транзакций Партнёр',
                'inviter' => self::UPLINE, 'inviterName' => 'Наставников Наставник',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00'],
        ]);
        DB::table('client')->insert([
            'id' => self::CLIENT, 'consultant' => self::PARTNER, 'personName' => 'Клиентов Клиент',
        ]);
        DB::table('contract')->insert([
            'id' => self::CONTRACT,
            'consultant' => self::PARTNER, 'consultantName' => 'Транзакций Партнёр',
            'client' => self::CLIENT, 'clientName' => 'Клиентов Клиент',
            'product' => self::PRODUCT, 'program' => self::PROGRAM,
            'number' => 'TX-0001', 'status' => 1, 'ammount' => 100_000,
            'createDate' => '2026-03-01 00:00:00', 'openDate' => '2026-03-01 00:00:00',
        ]);

        DB::table('transaction')->insert([
            'id' => self::TX,
            'contract' => self::CONTRACT,
            'date' => '2026-03-15 12:00:00',
            'dateMonth' => '2026-03', 'dateYear' => 2026,
            'amountRUB' => 100_000, 'commissionsAmountRUB' => 5_000,
            'netRevenueRUB' => 90_000, 'profitRUB' => 0, 'personalVolume' => 50,
            'comment' => 'основная',
        ]);
    }
}
