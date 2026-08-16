<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Превью черновика ручной транзакции (/admin/manual-tx/drafts).
 *
 * Сетка ПОД вынос. Превью обязано совпадать с фактическим начислением: по
 * нему оператор решает, фиксировать ли сделку. Каждое правило здесь — след
 * инцидента:
 *   - тариф не найден → нули и признак «тарифа нет», а НЕ 100 %: подстановка
 *     ста процентов давала при фиксации доход ДС размером во всю сумму без
 *     НДС, завышение в десятки раз (кейс «Брокер+»);
 *   - нет ставки НДС на дату → ошибка, а не расчёт по нулю: иначе оператор
 *     видит доход, завышенный ровно на ставку, и фиксирует вслепую;
 *   - курс и НДС берутся по дате СДЕЛКИ, не по сегодняшней;
 *   - терминированному партнёру начисления нет, но его процент остаётся
 *     базой для каскада вверх;
 *   - ошибка резолва курса возвращается строкой, а не 500-й: список
 *     черновиков сериализуется целиком, и один битый не должен уносить всю
 *     страницу.
 */
class ManualDraftPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2400001;
    private const UPLINE = 2400002;
    private const TOP = 2400003;
    private const CLIENT = 2400004;
    private const PRODUCT = 2400010;
    private const PROGRAM = 2400020;
    private const CONTRACT = 2400030;

    private const DATE = '2026-07-15';

    private User $admin;
    private int $seq = 2400100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- НДС и курс ----------------

    /** Сумма без НДС считается по ставке на дату сделки. */
    #[Test]
    public function the_vat_is_taken_for_the_deal_date(): void
    {
        $p = $this->preview(['amount' => 120_000]);

        $this->assertTrue($p['ready']);
        $this->assertEqualsWithDelta(120_000, $p['amountRUB'], 0.01);
        $this->assertEqualsWithDelta(100_000, $p['amountNoVat'], 0.01, 'ставка 20 % на дату сделки');
        $this->assertEqualsWithDelta(20_000, $p['vat'], 0.01);
    }

    /**
     * ⚠ Нет ставки НДС на дату — превью отдаёт ОШИБКУ, а не считает по нулю:
     * иначе оператор видит доход, завышенный ровно на ставку.
     */
    #[Test]
    public function a_missing_vat_rate_is_an_error_not_a_zero_rate(): void
    {
        DB::table('vat')->delete();

        $p = $this->preview(['amount' => 120_000]);

        $this->assertFalse($p['ready']);
        $this->assertStringContainsString('НДС', $p['error']);
    }

    /** Ошибка курса тоже возвращается строкой, а не роняет страницу. */
    #[Test]
    public function a_missing_currency_rate_comes_back_as_a_message(): void
    {
        $p = $this->preview(['amount' => 1_000, 'currency' => 2400050]);

        $this->assertFalse($p['ready']);
        $this->assertNotEmpty($p['error']);
    }

    // ---------------- Тариф ----------------

    /** %ДС из программы применяется к сумме без НДС. */
    #[Test]
    public function the_program_rate_drives_the_ds_income(): void
    {
        $p = $this->preview(['amount' => 120_000]);

        $this->assertEqualsWithDelta(10, $p['dsCommissionPercentage'], 0.0001);
        $this->assertEqualsWithDelta(10_000, $p['incomeDS'], 0.01, '100 000 без НДС × 10 %');
    }

    /** Явно заданный процент побеждает программный. */
    #[Test]
    public function an_explicit_override_wins(): void
    {
        $p = $this->preview(['amount' => 120_000, 'dsCommissionPercentage' => 25]);

        $this->assertEqualsWithDelta(25, $p['dsCommissionPercentage'], 0.0001);
        $this->assertEqualsWithDelta(25_000, $p['incomeDS'], 0.01);
    }

    /**
     * ⚠ Тариф не найден нигде — нули и признак, а НЕ 100 %. Подстановка ста
     * процентов делала доход ДС равным всей сумме без НДС.
     */
    #[Test]
    public function a_missing_tariff_yields_zero_and_a_flag(): void
    {
        DB::table('programs_catalog')->where('id', self::PROGRAM)->update(['ds_percent' => null]);

        $p = $this->preview(['amount' => 120_000]);

        $this->assertTrue($p['tariffMissing'], 'признак «тарифа нет» обязан подняться');
        $this->assertEqualsWithDelta(0, $p['dsCommissionPercentage'], 0.0001);
        $this->assertEqualsWithDelta(0, $p['incomeDS'], 0.01);
    }

    /** «Своя комиссия»: процент выводится обратным счётом из суммы дохода. */
    #[Test]
    public function a_custom_commission_back_computes_the_rate(): void
    {
        $p = $this->preview([
            'amount' => 120_000,
            'customCommission' => true,
            'dsCommissionAbsolute' => 5_000,
        ]);

        $this->assertEqualsWithDelta(5_000, $p['incomeDS'], 0.01);
        $this->assertEqualsWithDelta(5, $p['dsCommissionPercentage'], 0.0001, '5000 от 100 000 без НДС');
    }

    /**
     * ⚠ У сторно и сумма, и доход отрицательные. Сравнение с нулём идёт по
     * модулю — иначе превью показывало бы нулевой процент.
     */
    #[Test]
    public function a_reversal_keeps_its_negative_rate(): void
    {
        $p = $this->preview([
            'amount' => -120_000,
            'customCommission' => true,
            'dsCommissionAbsolute' => -5_000,
        ]);

        $this->assertEqualsWithDelta(-5_000, $p['incomeDS'], 0.01);
        $this->assertEqualsWithDelta(5, $p['dsCommissionPercentage'], 0.0001);
    }

    // ---------------- Цепочка ----------------

    /** Наставник получает МАРЖУ — разницу процентов, а не свой процент целиком. */
    #[Test]
    public function the_upline_is_paid_the_margin_only(): void
    {
        $chain = collect($this->preview(['amount' => 120_000])['chain'])->keyBy('consultantId');

        $this->assertCount(3, $chain, 'партнёр и два наставника');
        $this->assertTrue($chain[self::PARTNER]['isDirect']);
        // Проценты уровней: партнёр 15, наставник 25, верх 30 (см. фикстуру).
        $this->assertEqualsWithDelta(15, $chain[self::PARTNER]['percent'], 0.01);
        $this->assertEqualsWithDelta(25, $chain[self::UPLINE]['percent'], 0.01);
        $this->assertEqualsWithDelta(30, $chain[self::TOP]['percent'], 0.01);
        // Баллы наставника = ЛП × маржа / 100. Маржа = 25 − 15 и 30 − 25.
        $lp = $chain[self::PARTNER]['lp'];
        $this->assertEqualsWithDelta(round($lp * 10 / 100, 2), $chain[self::UPLINE]['points'], 0.01);
        $this->assertEqualsWithDelta(round($lp * 5 / 100, 2), $chain[self::TOP]['points'], 0.01);
    }

    /**
     * ⚠ Терминированному прямому партнёру начисления нет, но его процент
     * остаётся базой для каскада: наставник получает свой обычный инкремент,
     * а не увеличенный.
     */
    #[Test]
    public function a_terminated_partner_gets_nothing_but_still_sets_the_base(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)->update(['activity' => 3]);

        $chain = collect($this->preview(['amount' => 120_000])['chain'])->keyBy('consultantId');

        $this->assertTrue($chain[self::PARTNER]['inactive']);
        $this->assertEqualsWithDelta(0, $chain[self::PARTNER]['points'], 0.01, 'терминированному не начисляем');
        $this->assertEqualsWithDelta(15, $chain[self::PARTNER]['percent'], 0.01, 'но процент посчитан');

        $lp = $chain[self::PARTNER]['lp'];
        $this->assertEqualsWithDelta(round($lp * 10 / 100, 2), $chain[self::UPLINE]['points'], 0.01,
            'наставник получает ту же маржу, что и при активном партнёре');
    }

    /**
     * ⚠ Маржинальное сжатие: планка не опускается. Если наставник НИЖЕ
     * подопечного по уровню, следующий наставник получает разницу от уже
     * достигнутой планки, а не от процента «просевшего» звена — иначе он
     * получит лишнее, а сумма цепочки превысит доход ДС.
     */
    #[Test]
    public function the_margin_ratchet_never_steps_back_down(): void
    {
        // Партнёр 25 %, наставник ниже него (15 %), верх 30 %.
        DB::table('qualificationLog')->where('consultant', self::PARTNER)
            ->update(['nominalLevel' => 3, 'calculationLevel' => 3]);
        DB::table('qualificationLog')->where('consultant', self::UPLINE)
            ->update(['nominalLevel' => 1, 'calculationLevel' => 1]);

        $chain = collect($this->preview(['amount' => 120_000])['chain'])->keyBy('consultantId');
        $lp = $chain[self::PARTNER]['lp'];

        $this->assertEqualsWithDelta(0, $chain[self::UPLINE]['points'], 0.01,
            'наставник ниже подопечного — маржи нет');
        $this->assertEqualsWithDelta(round($lp * 5 / 100, 2), $chain[self::TOP]['points'], 0.01,
            'верх получает 30 − 25, а не 30 − 15');
    }

    /** Терминированный наставник маржу не получает, но планку сдвигает. */
    #[Test]
    public function a_terminated_upline_is_skipped_but_still_raises_the_bar(): void
    {
        DB::table('consultant')->where('id', self::UPLINE)->update(['activity' => 3]);

        $chain = collect($this->preview(['amount' => 120_000])['chain'])->keyBy('consultantId');
        $lp = $chain[self::PARTNER]['lp'];

        $this->assertTrue($chain[self::UPLINE]['inactive']);
        $this->assertEqualsWithDelta(0, $chain[self::UPLINE]['points'], 0.01,
            'терминированному наставнику маржа не выплачивается');
        $this->assertEqualsWithDelta(round($lp * 5 / 100, 2), $chain[self::TOP]['points'], 0.01,
            'а верхний получает свой обычный инкремент 30 − 25, его слой не расширяется');
    }

    /** «Прибыль ДС» = доход минус выплаты цепочке. */
    #[Test]
    public function the_company_profit_is_the_income_minus_the_chain(): void
    {
        $p = $this->preview(['amount' => 120_000]);

        $this->assertEqualsWithDelta(
            round($p['incomeDS'] - $p['partnersTotal'], 2),
            $p['profitDS'],
            0.01
        );
    }

    // ================================================================

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function preview(array $attrs): array
    {
        $id = $this->seq++;
        DB::table('transaction_draft')->insert(array_merge([
            'id' => $id,
            'contract' => self::CONTRACT,
            'consultant' => self::PARTNER,
            'amount' => 0,
            'currency' => null,
            'date' => self::DATE,
            'customCommission' => false,
            'createdBy' => $this->admin->id,
            'createdAt' => now(), 'updatedAt' => now(),
        ], $attrs));

        // Превью считается по кнопке «Рассчитать» и складывается в черновик;
        // список отдаёт уже сохранённый результат, сам ничего не считает.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/manual-tx/calc', ['ids' => [$id]])
            ->assertOk();

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/manual-tx/drafts')
            ->assertOk()->json('data');

        $row = collect($rows)->firstWhere('id', $id);

        return $row['preview'] ?? [];
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 2400900;
        $this->admin->email = 'drafts@test.local';
        $this->admin->firstName = 'Черновики';
        $this->admin->lastName = 'Тестовые';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('vat')->delete();
        DB::table('vat')->insert([
            'id' => 1, 'value' => 20,
            'dateFrom' => '2000-01-01', 'dateTo' => '2099-12-31',
        ]);
        $cache = new \ReflectionProperty(\App\Support\VatRate::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        DB::table('products_catalog')->insert([
            'id' => self::PRODUCT, 'name' => 'Ручной продукт', 'active' => true,
            'legacy_product_id' => self::PRODUCT,
        ]);
        DB::table('programs_catalog')->insert([
            'id' => self::PROGRAM, 'product_id' => self::PRODUCT,
            'name' => 'Ручная программа', 'active' => true,
            'legacy_program_id' => self::PROGRAM, 'ds_percent' => 10,
        ]);

        // Уровни: Старт 15 % → Эксперт 25 % → ФК 30 % снизу вверх.
        // Маржа наставников получается 10 и 5 процентных пунктов.
        $this->partner(self::TOP, 'Верхний Наставник', null, 4);
        $this->partner(self::UPLINE, 'Средний Наставник', self::TOP, 3);
        $this->partner(self::PARTNER, 'Прямой Партнёр', self::UPLINE, 1);

        DB::table('client')->insert([
            'id' => self::CLIENT, 'consultant' => self::PARTNER, 'personName' => 'Ручной Клиент',
        ]);
        DB::table('contract')->insert([
            'id' => self::CONTRACT,
            'consultant' => self::PARTNER, 'client' => self::CLIENT,
            'product' => self::PRODUCT, 'program' => self::PROGRAM,
            'number' => 'MT-0001', 'status' => 1, 'ammount' => 120_000,
            'createDate' => '2026-07-01 00:00:00', 'openDate' => '2026-07-01 00:00:00',
        ]);
    }

    /**
     * Уровень партнёра резолвится не из карточки, а из ОТКРЫВАЮЩЕЙ строки
     * qualificationLog — той, что датирована первым числом месяца сделки
     * (Directual писал её со сдвигом МСК, отсюда 03:00).
     */
    private function partner(int $id, string $name, ?int $inviter, int $level): void
    {
        $levelId = (int) DB::table('status_levels')->where('level', $level)->value('id');

        DB::table('consultant')->insert([
            'id' => $id, 'personName' => $name, 'inviter' => $inviter,
            'activity' => 1, 'status_and_lvl' => $levelId,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('qualificationLog')->insert([
            'id' => $this->seq++,
            'consultant' => $id,
            'date' => '2026-07-01 03:00:00',
            'nominalLevel' => $levelId,
            'calculationLevel' => $levelId,
            'personalVolume' => 0, 'groupVolume' => 0,
            'createdAt' => '2026-07-01 03:00:00',
        ]);
    }
}
