<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Матрицы продаж: факт (/sales-matrix/fact) и прогноз (/sales-matrix/forecast).
 *
 * Это путь ДЕНЕГ, и сеткой он не был покрыт вовсе. Тесты фиксируют формулы и
 * границы ровно в том виде, в каком они работают на проде — менять их нельзя,
 * можно только заметить, если правка их сдвинет.
 *
 * Что закреплено в первую очередь (каждое уже стоило инцидента):
 *   - «Выручка Факт» = commissionsAmountRUB. НЕ netRevenueRUB: та завышала
 *     выручку примерно в четырнадцать раз;
 *   - выручка прогноза = сумма БЕЗ НДС × %ДС / 100, баллы = выручка / 100;
 *   - контракты без даты активации показываются ВСЕГДА, даже когда задан
 *     период — иначе прогноз теряет всё недатированное;
 *   - правая граница периода прогноза исключительная, то есть месяц `to`
 *     входит целиком.
 */
class SalesMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT = 2000010;
    private const PROGRAM = 2000020;
    private const PARTNER = 2000030;
    private const CLIENT = 2000040;

    private User $admin;
    private int $seq = 2000100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
        // Карта ставок кэшируется в static и переживает RefreshDatabase.
        \App\Services\ForecastDsRate::flush();
    }

    // ---------------- Факт ----------------

    /**
     * ⚠ «Выручка» факта берётся из commissionsAmountRUB — это доход ДС.
     * netRevenueRUB здесь НЕ при чём: подстановка её завышала выручку ~14×.
     */
    #[Test]
    public function fact_revenue_comes_from_the_ds_income_column(): void
    {
        $this->transaction(['dateMonth' => '2026-03', 'amountRUB' => 1_000_000,
            'commissionsAmountRUB' => 50_000, 'netRevenueRUB' => 700_000,
            'personalVolume' => 500]);

        $grand = $this->fact('2026-03', '2026-03')['grandTotals'];

        $this->assertEqualsWithDelta(50_000, $grand['revenue'], 0.01, 'выручка = доход ДС');
        $this->assertEqualsWithDelta(1_000_000, $grand['volume'], 0.01, 'объём = сумма контрактов');
        $this->assertEqualsWithDelta(500, $grand['points'], 0.01, 'баллы = personalVolume');
        // Средний чек — производное поле: объём делится на число транзакций.
        $this->assertEqualsWithDelta(1_000_000, $grand['avgCheck'], 0.01);
    }

    /** Строки раскладываются по месяцу транзакции, а не контракта. */
    #[Test]
    public function fact_rows_bucket_by_the_transaction_month(): void
    {
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 10_000]);
        $this->transaction(['dateMonth' => '2026-04', 'commissionsAmountRUB' => 20_000]);

        $monthly = $this->fact('2026-03', '2026-04')['grandTotals']['monthly'];

        $this->assertEqualsWithDelta(10_000, $monthly['2026-03']['revenue'], 0.01);
        $this->assertEqualsWithDelta(20_000, $monthly['2026-04']['revenue'], 0.01);
    }

    /** Обе границы периода включительные. */
    #[Test]
    public function fact_period_bounds_are_inclusive(): void
    {
        $this->transaction(['dateMonth' => '2026-02', 'commissionsAmountRUB' => 1_000]);
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 2_000]);
        $this->transaction(['dateMonth' => '2026-04', 'commissionsAmountRUB' => 4_000]);

        $this->assertEqualsWithDelta(7_000, $this->fact('2026-02', '2026-04')['grandTotals']['revenue'], 0.01);
        $this->assertEqualsWithDelta(2_000, $this->fact('2026-03', '2026-03')['grandTotals']['revenue'], 0.01);
    }

    /** Удалённое и неоткрытое в факт не идёт. */
    #[Test]
    public function fact_ignores_deleted_and_unopened(): void
    {
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 1_000]);
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 9_000,
            'deletedAt' => '2026-03-05 00:00:00']);

        $unopened = $this->contract(['openDate' => null]);
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 9_000], $unopened);

        $deletedContract = $this->contract(['deletedAt' => '2026-03-05 00:00:00']);
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 9_000], $deletedContract);

        $this->assertEqualsWithDelta(1_000, $this->fact('2026-03', '2026-03')['grandTotals']['revenue'], 0.01);
    }

    /** Фильтр по продукту сужает выдачу. */
    #[Test]
    public function fact_filters_by_product(): void
    {
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 1_000]);

        $this->assertEqualsWithDelta(1_000,
            $this->fact('2026-03', '2026-03', ['products' => [self::PRODUCT]])['grandTotals']['revenue'], 0.01);
        $this->assertEqualsWithDelta(0,
            $this->fact('2026-03', '2026-03', ['products' => [999999]])['grandTotals']['revenue'], 0.01);
    }

    // ---------------- Прогноз ----------------

    /**
     * Прогнозная выручка ДС = сумма БЕЗ НДС × %ДС / 100, баллы = выручка / 100.
     * Проверяем на 1 200 000 ₽ при НДС 20 % и %ДС 10: без НДС это
     * 1 000 000, выручка 100 000, баллы 1 000.
     *
     * ⚠ Формула живёт в матрице «В работе» (/inwork): там транзакций ещё нет
     * и выручка считается прямо из контрактов. В /forecast её нет вовсе —
     * та матрица про объём и количество.
     */
    #[Test]
    public function in_work_revenue_strips_vat_and_applies_the_ds_percent(): void
    {
        $this->setVat(20);
        $this->contract([
            'status' => 2, 'ammount' => 1_200_000,
            'activation_forecast' => '2026-05-10',
            'createDate' => '2026-05-01 00:00:00',
        ]);

        // Ячейка месяца СОЗДАНИЯ, а внутри неё разбивка по месяцу прогноза
        // активации — объём и выручка живут в разных измерениях.
        $bucket = $this->inWork('2026-05', '2026-05')['grandTotals']['monthly']['2026-05']['forecast'][0];

        $this->assertSame('2026-05', $bucket['month']);
        $this->assertEqualsWithDelta(100_000, $bucket['revenue'], 0.01);
        $this->assertEqualsWithDelta(1_000, $bucket['points'], 0.01);
    }

    /** Без ставки в справочнике сумма не делится — фолбэк ноль процентов. */
    #[Test]
    public function in_work_revenue_without_a_vat_row_keeps_the_full_amount(): void
    {
        $this->setVat(0);
        $this->contract([
            'status' => 2, 'ammount' => 1_200_000,
            'activation_forecast' => '2026-05-10',
            'createDate' => '2026-05-01 00:00:00',
        ]);

        $this->assertEqualsWithDelta(120_000,
            $this->inWork('2026-05', '2026-05')['grandTotals']['monthly']['2026-05']['forecast'][0]['revenue'], 0.01);
    }

    /**
     * Собственная выручка ячейки «В работе» считается тем же правилом, что и
     * разбивка: сумма без НДС × %ДС / 100. Это отдельный проход по контрактам,
     * и он умеет разойтись с разбивкой, если правку внести только в одном.
     */
    #[Test]
    public function in_work_cell_revenue_matches_the_breakdown(): void
    {
        $this->setVat(20);
        $this->contract([
            'status' => 2, 'ammount' => 1_200_000,
            'activation_forecast' => '2026-05-10',
            'createDate' => '2026-05-01 00:00:00',
        ]);

        $grand = $this->inWork('2026-05', '2026-05')['grandTotals'];

        $this->assertEqualsWithDelta(100_000, $grand['revenue'], 0.01);
        $this->assertEqualsWithDelta(100_000, $grand['monthly']['2026-05']['revenue'], 0.01);
    }

    /**
     * Валютный контракт пересчитывается управленческим курсом. Без него
     * доллары считались бы как рубли — то есть примерно в восемьдесят раз
     * дешевле.
     */
    #[Test]
    public function in_work_converts_currency_by_the_management_rate(): void
    {
        $this->setVat(0);
        DB::table('currency')->updateOrInsert(['id' => 2000050],
            ['symbol' => '$', 'nameRu' => 'Доллар тестовый', 'selectable' => false]);
        DB::table('management_currency_rate')->insert([
            'currency' => 2000050, 'date' => '2026-05-01', 'rate' => 80,
        ]);

        $this->contract([
            'status' => 2, 'ammount' => 1_000, 'currency' => 2000050,
            'activation_forecast' => '2026-05-10',
            'createDate' => '2026-05-01 00:00:00',
        ]);

        $bucket = $this->inWork('2026-05', '2026-05')['grandTotals']['monthly']['2026-05']['forecast'][0];

        $this->assertEqualsWithDelta(80_000, $bucket['volume'], 0.01, '1000 × курс 80');
        $this->assertEqualsWithDelta(8_000, $bucket['revenue'], 0.01, 'выручка от рублёвой суммы');
    }

    /** По умолчанию берутся только статусы «Сбор документов» и «Комплайнс». */
    #[Test]
    public function forecast_defaults_to_the_two_pipeline_statuses(): void
    {
        $this->contract(['status' => 2, 'ammount' => 100, 'activation_forecast' => '2026-05-10']);
        $this->contract(['status' => 3, 'ammount' => 200, 'activation_forecast' => '2026-05-10']);
        $this->contract(['status' => 1, 'ammount' => 900, 'activation_forecast' => '2026-05-10']);

        $this->assertSame(2, $this->forecast(['from' => '2026-05', 'to' => '2026-05'])['grandTotals']['count']);
    }

    /**
     * ⚠ Контракт без даты активации показывается ВСЕГДА, даже когда период
     * задан: иначе прогноз молча теряет всё недатированное.
     */
    #[Test]
    public function forecast_always_shows_undated_contracts(): void
    {
        $this->contract(['status' => 2, 'ammount' => 100, 'activation_forecast' => null]);
        $this->contract(['status' => 2, 'ammount' => 200, 'activation_forecast' => '2026-05-10']);

        $this->assertSame(2, $this->forecast(['from' => '2026-05', 'to' => '2026-05'])['grandTotals']['count'],
            'недатированный контракт остаётся в выдаче вместе с датированным');
        $this->assertSame(1, $this->forecast(['from' => '2026-09', 'to' => '2026-09'])['grandTotals']['count'],
            'в чужом месяце остаётся только недатированный');
    }

    /** Правая граница исключительная — месяц `to` входит целиком. */
    #[Test]
    public function forecast_includes_the_whole_closing_month(): void
    {
        $this->contract(['status' => 2, 'ammount' => 100, 'activation_forecast' => '2026-05-31']);
        $this->contract(['status' => 2, 'ammount' => 100, 'activation_forecast' => '2026-06-01']);

        $this->assertSame(1, $this->forecast(['from' => '2026-05', 'to' => '2026-05'])['grandTotals']['count']);
        $this->assertSame(2, $this->forecast(['from' => '2026-05', 'to' => '2026-06'])['grandTotals']['count']);
    }

    /** Статусы вне пайплайна форма не принимает. */
    #[Test]
    public function forecast_rejects_statuses_outside_the_pipeline(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reports/sales-matrix/forecast?statuses[]=1')
            ->assertStatus(422);
    }

    // ---------------- Активировано (/period) ----------------

    /**
     * ⚠ Контракт, по которому УЖЕ есть транзакция, в «Активировано» не идёт:
     * он учитывается в «Факте». Правило исключает двойной счёт — сними его,
     * и выручка задвоится в режиме «Итого».
     */
    #[Test]
    public function activated_skips_contracts_that_already_have_a_transaction(): void
    {
        $this->contract(['status' => 1, 'openDate' => '2026-03-10 00:00:00']);
        $paid = $this->contract(['status' => 1, 'openDate' => '2026-03-10 00:00:00']);
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 1_000], $paid);

        $grand = $this->period('2026-03', '2026-03')['grandTotals'];

        $this->assertSame(1, $grand['count'],
            'из двух активированных контрактов остался только неоплаченный');
        // Средний чек — производное поле, при правках теряется незаметно.
        $this->assertEqualsWithDelta($grand['volume'], $grand['avgCheck'], 0.01,
            'один контракт — средний чек равен его объёму');
    }

    /** Удалённая транзакция контракт не «занимает» — он снова активированный. */
    #[Test]
    public function a_deleted_transaction_does_not_hold_the_contract(): void
    {
        $c = $this->contract(['status' => 1, 'openDate' => '2026-03-10 00:00:00']);
        $this->transaction([
            'dateMonth' => '2026-03', 'commissionsAmountRUB' => 1_000,
            'deletedAt' => '2026-03-11 00:00:00',
        ], $c);

        $this->assertSame(1, $this->period('2026-03', '2026-03')['grandTotals']['count']);
    }

    /** Берутся только активированные, по дате активации, правая граница исключительная. */
    #[Test]
    public function activated_uses_the_open_date_window(): void
    {
        $this->contract(['status' => 1, 'openDate' => '2026-03-31 23:00:00']);
        $this->contract(['status' => 1, 'openDate' => '2026-04-01 00:00:00']);
        $this->contract(['status' => 2, 'openDate' => '2026-03-10 00:00:00']);

        $this->assertSame(1, $this->period('2026-03', '2026-03')['grandTotals']['count']);
        $this->assertSame(2, $this->period('2026-03', '2026-04')['grandTotals']['count']);
    }

    // ---------------- Итого (/total) ----------------

    /**
     * «Итого» складывает три слоя, и ни один контракт не должен попасть в два
     * сразу: «в работе» — неактивированные по дате создания, «активировано» —
     * status=1 без транзакций, «факт» — транзакции.
     */
    #[Test]
    public function the_total_layers_do_not_double_count(): void
    {
        // Один и тот же контракт: активирован и оплачен → только «Факт».
        $paid = $this->contract(['status' => 1, 'openDate' => '2026-03-10 00:00:00']);
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 5_000], $paid);

        $total = $this->total('2026-03', '2026-03')['grandTotals'];

        $this->assertEqualsWithDelta(5_000, $total['revenue'], 0.01,
            'выручка учтена один раз, слоем «Факт»');
    }

    // ---------------- Сводная матрица (/sales-matrix) ----------------

    /**
     * Сводная матрица за год: строки по продуктам с выручкой, баллами и
     * счётчиками уникальных ФК и клиентов.
     *
     * ⚠ Счётчики уникальных считаются ОТДЕЛЬНЫМ запросом и подмешиваются к
     * строкам по продукту — при правках их легко потерять, а из выдачи это
     * видно только как нули.
     */
    #[Test]
    public function the_overview_carries_per_product_totals_and_counts(): void
    {
        // ⚠ Сводка фильтрует по dateYear, а не по dateMonth — колонки разные.
        $this->transaction(['dateMonth' => '2026-03', 'dateYear' => '2026',
            'amountRUB' => 500_000, 'commissionsAmountRUB' => 20_000,
            'personalVolume' => 200]);

        $body = $this->overview(2026);
        $row = collect($body['rows'])->firstWhere('productId', self::PRODUCT);

        $this->assertNotNull($row, 'продукт есть в сводке');
        $this->assertEqualsWithDelta(20_000, $row['revenue'], 0.01);
        $this->assertEqualsWithDelta(200, $row['points'], 0.01);
        $this->assertSame(1, $row['fcCount'], 'уникальный ФК посчитан');
        $this->assertSame(1, $row['clientCount'], 'уникальный клиент посчитан');

        $this->assertEqualsWithDelta(500_000, $body['grandTotals']['volume'], 0.01);
        $this->assertEqualsWithDelta(500_000, $body['grandTotals']['avgCheck'], 0.01);
    }

    // ---------------- Матрица ФК (/fc) ----------------

    /**
     * ⚠ Партнёр БЕЗ логина в матрице ФК показывается наравне с остальными.
     *
     * Раньше не показывался: WebUser подключался внутренним join, и продажи
     * импортированных ФК (а их сотни) выпадали из отчёта целиком, хотя в
     * «Факте» они были. Имя в таком случае берётся из карточки партнёра.
     */
    #[Test]
    public function the_fc_matrix_keeps_partners_without_a_login(): void
    {
        $this->transaction(['dateMonth' => '2026-03', 'commissionsAmountRUB' => 7_000]);

        $fact = $this->fact('2026-03', '2026-03')['grandTotals'];
        $fc = $this->fc('2026-03', '2026-03');

        $this->assertEqualsWithDelta(7_000, $fact['revenue'], 0.01, 'в «Факте» продажа есть');
        $this->assertCount(1, $fc['rows'], 'и в матрице ФК тоже');
        $this->assertSame('Матричный Партнёр', $fc['rows'][0]['fcName'],
            'имя взято из карточки партнёра, раз логина нет');
    }

    // ================================================================

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function fact(string $from, string $to, array $extra = []): array
    {
        $query = http_build_query(array_merge(['from' => $from, 'to' => $to], $extra));

        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reports/sales-matrix/fact?' . $query)
            ->assertOk()->json();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function forecast(array $params = []): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reports/sales-matrix/forecast?' . http_build_query($params))
            ->assertOk()->json();
    }

    /** Ставка НДС живёт в справочнике с интервалом дат и статически кэшируется. */
    /** @return array<string, mixed> */
    private function period(string $from, string $to): array
    {
        return $this->matrix('period', $from, $to);
    }

    /** @return array<string, mixed> */
    private function total(string $from, string $to): array
    {
        return $this->matrix('total', $from, $to);
    }

    /** @return array<string, mixed> */
    private function fc(string $from, string $to): array
    {
        return $this->matrix('fc', $from, $to);
    }

    /** @return array<string, mixed> */
    private function overview(int $year): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reports/sales-matrix?year=' . $year)
            ->assertOk()->json();
    }

    /** @return array<string, mixed> */
    private function matrix(string $slug, string $from, string $to): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reports/sales-matrix/' . $slug . '?' . http_build_query([
                'from' => $from, 'to' => $to,
            ]))->assertOk()->json();
    }

    /** @return array<string, mixed> */
    private function inWork(string $from, string $to): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reports/sales-matrix/inwork?' . http_build_query([
                'from' => $from, 'to' => $to,
            ]))->assertOk()->json();
    }

    private function setVat(float $percent): void
    {
        DB::table('vat')->delete();
        if ($percent > 0) {
            DB::table('vat')->insert([
                'id' => 1, 'value' => $percent,
                'dateFrom' => '2000-01-01', 'dateTo' => '2099-12-31',
            ]);
        }

        // Кэш статический и переживает запрос — иначе ставка «залипает»
        // между тестами внутри одного процесса.
        $cache = new \ReflectionProperty(\App\Support\VatRate::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    /** @param array<string, mixed> $attrs */
    private function contract(array $attrs = []): int
    {
        $id = $this->seq++;
        DB::table('contract')->insert(array_merge([
            'id' => $id,
            'consultant' => self::PARTNER, 'client' => self::CLIENT,
            'product' => self::PRODUCT, 'program' => self::PROGRAM,
            'number' => 'SM-' . $id, 'status' => 1, 'ammount' => 100_000,
            'createDate' => '2026-03-01 00:00:00',
            'openDate' => '2026-03-01 00:00:00',
        ], $attrs));

        return $id;
    }

    /** @param array<string, mixed> $attrs */
    private function transaction(array $attrs, ?int $contractId = null): int
    {
        $id = $this->seq++;
        DB::table('transaction')->insert(array_merge([
            'id' => $id,
            'contract' => $contractId ?? $this->contractForTransactions(),
            'dateMonth' => '2026-03',
            'amountRUB' => 0,
            'commissionsAmountRUB' => 0,
            'netRevenueRUB' => 0,
            'personalVolume' => 0,
        ], $attrs));

        return $id;
    }

    private ?int $sharedContract = null;

    private function contractForTransactions(): int
    {
        return $this->sharedContract ??= $this->contract();
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 2000900;
        $this->admin->email = 'matrix@test.local';
        $this->admin->firstName = 'Матрица';
        $this->admin->lastName = 'Тестовая';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('products_catalog')->insert([
            'id' => self::PRODUCT, 'name' => 'Матричный продукт', 'active' => true,
            'legacy_product_id' => self::PRODUCT,
        ]);
        // ds_percent = 10 — на нём и проверяется формула выручки прогноза.
        // ⚠ legacy_program_id обязателен: ForecastDsRate строит карту ставок
        // именно по нему (contract.program — legacy id), а строки без него
        // отбрасывает — тогда %ДС = null и вся выручка прогноза уходит в ноль.
        DB::table('programs_catalog')->insert([
            'id' => self::PROGRAM, 'product_id' => self::PRODUCT,
            'legacy_program_id' => self::PROGRAM,
            'name' => 'Матричная программа', 'active' => true,
            'provider_name' => 'Матричный поставщик', 'ds_percent' => 10,
        ]);

        DB::table('consultant')->insert([
            'id' => self::PARTNER, 'personName' => 'Матричный Партнёр',
            'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('client')->insert([
            'id' => self::CLIENT, 'consultant' => self::PARTNER, 'personName' => 'Матричный Клиент',
        ]);
    }
}
