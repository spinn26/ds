<?php

namespace Tests\Feature\Characterization;

use App\Services\CommissionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ХАРАКТЕРИЗУЮЩИЙ тест каскада комиссий (Этап 0 рефакторинга).
 *
 * Задача — не «проверить, что правильно», а ЗАФИКСИРОВАТЬ текущее поведение
 * до того, как код начнут двигать. Числа ниже посчитаны вручную по формулам
 * CommissionCalculator и сверены с фактическим прогоном; если рефакторинг их
 * сдвинет — тест обязан покраснеть.
 *
 * Разбираемый сценарий (ЛП/ГП/маржа — спека ✅Бизнес-логика):
 *   сумма 105 000 ₽, НДС 5%           → база без НДС = 100 000
 *   %ДС = 40                          → доход ДС = 40 000
 *   ЛП = amountNoVat × %ДС / 10 000   = 400 баллов (метод по умолчанию)
 *
 *   цепочка: продавец (Эксперт 25%) → наставник (Топ ФК 35%) → корень (30%)
 *   продавец: 400 × 25 / 100      = 100 баллов = 10 000 ₽
 *   наставник: маржа 35 − 25 = 10  → 400 × 10 / 100 = 40 баллов = 4 000 ₽
 *   корень:   маржа 30 − 35 < 0    → строка есть, деньги 0 (проходной)
 *   Σ цепочке = 14 000 ₽
 *
 *   netRevenueRUB = 100 000 − 14 000 = 86 000
 *   profitRUB     =  40 000 − 14 000 = 26 000
 */
class CommissionCascadeCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const SELLER = 900001;
    private const MENTOR = 900002;
    private const ROOT = 900003;

    private const CONTRACT = 900010;
    private const TRANSACTION = 900020;
    private const PROGRAM = 900030;
    private const PRODUCT = 900040;

    /** Уровни берём из настоящего справочника status_levels (он в фикстуре). */
    private int $levelExpert;
    private int $levelTopFc;
    private int $levelRoot;

    protected function setUp(): void
    {
        parent::setUp();
        // Статические кэши справочников живут дольше одного теста (процесс
        // общий на класс) — сбрасываем, чтобы тесты не влияли друг на друга.
        \App\Support\VatRate::flush();
        \App\Support\CurrencyRates::flush();
        $this->seedScenario();
    }

    #[Test]
    public function cascade_writes_expected_chain(): void
    {
        $result = app(CommissionCalculator::class)
            ->calculateForTransaction(self::TRANSACTION);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? 'нет success');
        $this->assertSame(self::SELLER, $result['consultantId']);
        $this->assertEqualsWithDelta(400.0, $result['personalVolume'], 0.000001, 'ЛП сделки');
        $this->assertSame(3, $result['commissionsCount'], 'строк в цепочке');

        $rows = DB::table('commission')
            ->where('transaction', self::TRANSACTION)
            ->whereNull('deletedAt')
            ->orderBy('chainOrder')
            ->get();

        $this->assertCount(3, $rows);

        // chainOrder=1 — продавец: ЛП целиком его, процент = его квалификация.
        $seller = $rows[0];
        $this->assertSame(self::SELLER, (int) $seller->consultant);
        $this->assertEqualsWithDelta(400.0, (float) $seller->personalVolume, 0.000001);
        $this->assertEqualsWithDelta(400.0, (float) $seller->groupVolume, 0.000001);
        $this->assertEqualsWithDelta(25.0, (float) $seller->percent, 0.0001);
        $this->assertEqualsWithDelta(10_000.0, (float) $seller->amountRUB, 0.01);
        // `amount` у первой строки — весь доход ДС без НДС, не выплата.
        $this->assertEqualsWithDelta(40_000.0, (float) $seller->amount, 0.01);

        // chainOrder=2 — наставник: платится ТОЛЬКО маржа над нижестоящим.
        $mentor = $rows[1];
        $this->assertSame(self::MENTOR, (int) $mentor->consultant);
        $this->assertSame(self::SELLER, (int) $mentor->commissionFromOtherConsultant);
        $this->assertEqualsWithDelta(0.0, (float) $mentor->personalVolume, 0.000001, 'ЛП наставнику не идёт');
        $this->assertEqualsWithDelta(400.0, (float) $mentor->groupVolume, 0.000001, 'ГП = объём сделки');
        $this->assertEqualsWithDelta(10.0, (float) $mentor->percent, 0.0001, 'маржа 35−25');
        $this->assertEqualsWithDelta(4_000.0, (float) $mentor->amountRUB, 0.01);

        // chainOrder=3 — корень: маржа отрицательная, строка остаётся с нулём.
        // Это осознанное поведение: «Цепочка выплат» показывает всю цепочку.
        $root = $rows[2];
        $this->assertSame(self::ROOT, (int) $root->consultant);
        $this->assertEqualsWithDelta(0.0, (float) $root->percent, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $root->amountRUB, 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $root->groupVolume, 0.000001, 'ГП есть даже без денег');
    }

    /**
     * Компрессия процентов вверх по цепочке.
     *
     * Между продавцом и старшим наставником стоит партнёр С МЕНЬШИМ процентом.
     * Каскад обязан нести вверх МАКСИМУМ пройденного процента, иначе старший
     * получит завышенную маржу (посчитанную от «просевшего» звена).
     *
     *   продавец 25% → промежуточный 20% → старший 35%
     *   промежуточный: маржа 20 − 25 < 0     → 0 ₽
     *   старший:       маржа 35 − 25 = 10    → 400 × 10 / 100 = 40 баллов = 4 000 ₽
     *
     * Без max() старший получил бы 35 − 20 = 15 → 6 000 ₽, то есть переплату.
     */
    #[Test]
    public function upline_percent_is_compressed_by_maximum(): void
    {
        $mid = 900004;
        $top = 900005;
        $levelJunior = $this->levelWithPercent(20.0);

        // Достраиваем ветку над наставником: MENTOR(35) больше не корень.
        DB::table('consultant')->where('id', self::MENTOR)->update([
            'status_and_lvl' => $levelJunior,
        ]);
        DB::table('qualificationLog')->where('consultant', self::MENTOR)->update([
            'nominalLevel' => $levelJunior,
            'calculationLevel' => $levelJunior,
        ]);

        $this->makeConsultant($top, self::ROOT, 'Старший Тестовый', $this->levelTopFc);
        DB::table('consultant')->where('id', self::MENTOR)->update(['inviter' => $top]);

        app(CommissionCalculator::class)->calculateForTransaction(self::TRANSACTION);

        $rows = DB::table('commission')
            ->where('transaction', self::TRANSACTION)
            ->whereNull('deletedAt')
            ->orderBy('chainOrder')
            ->get()
            ->keyBy('consultant');

        $this->assertEqualsWithDelta(0.0, (float) $rows[self::MENTOR]->amountRUB, 0.01,
            'промежуточный с меньшим процентом не получает ничего');
        $this->assertEqualsWithDelta(10.0, (float) $rows[$top]->percent, 0.0001,
            'маржа старшего считается от МАКСИМУМА пройденного процента (25), а не от 20');
        $this->assertEqualsWithDelta(4_000.0, (float) $rows[$top]->amountRUB, 0.01);

        unset($mid);
    }

    #[Test]
    public function cascade_writes_expected_transaction_denorm(): void
    {
        app(CommissionCalculator::class)->calculateForTransaction(self::TRANSACTION);

        $tx = DB::table('transaction')->where('id', self::TRANSACTION)->first();

        $this->assertEqualsWithDelta(40.0, (float) $tx->dsCommissionPercentage, 0.0001);
        $this->assertEqualsWithDelta(40_000.0, (float) $tx->commissionsAmountRUB, 0.01, 'доход ДС без НДС');
        $this->assertEqualsWithDelta(86_000.0, (float) $tx->netRevenueRUB, 0.01, 'база − выплаты цепочке');
        $this->assertEqualsWithDelta(26_000.0, (float) $tx->profitRUB, 0.01, 'доход ДС − выплаты цепочке');
        $this->assertEqualsWithDelta(14_000.0, (float) $tx->commissionAmountRubBeforeGapReduction, 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $tx->personalVolume, 0.000001);
    }

    #[Test]
    public function recalculation_is_idempotent(): void
    {
        $calc = app(CommissionCalculator::class);
        $calc->calculateForTransaction(self::TRANSACTION);
        $calc->calculateForTransaction(self::TRANSACTION);

        $live = DB::table('commission')
            ->where('transaction', self::TRANSACTION)
            ->whereNull('deletedAt')
            ->count();

        // Повторный расчёт мягко удаляет прежние строки и пишет новые —
        // живых остаётся ровно 3, дублей нет (регресс ловили на проде).
        $this->assertSame(3, $live);
    }

    #[Test]
    public function missing_vat_rate_is_an_error_not_zero_percent(): void
    {
        DB::table('vat')->delete();
        // Ставки кэшируются статикой на время запроса; в тестах процесс один
        // на весь класс, поэтому кэш надо сбросить явно.
        \App\Support\VatRate::flush();

        $result = app(CommissionCalculator::class)
            ->calculateForTransaction(self::TRANSACTION);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('НДС', $result['error']);
        $this->assertSame(
            0,
            DB::table('commission')->where('transaction', self::TRANSACTION)->whereNull('deletedAt')->count(),
            'при ошибке строки не создаются'
        );
    }

    #[Test]
    public function missing_ds_rate_is_an_error(): void
    {
        DB::table('dsCommission')->where('program', self::PROGRAM)->delete();
        DB::table('programs_catalog')->where('id', self::PROGRAM)->update(['ds_percent' => null]);

        $result = app(CommissionCalculator::class)
            ->calculateForTransaction(self::TRANSACTION);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('тариф', $result['error']);
    }

    // ================================================================
    // Фикстура сценария
    // ================================================================

    private function seedScenario(): void
    {
        $levels = DB::table('status_levels')->orderBy('level')->get();
        $this->assertNotEmpty($levels, 'status_levels пуст — проверь database/schema/pgsql-schema.sql');

        $this->levelExpert = $this->levelWithPercent(25.0);
        $this->levelTopFc = $this->levelWithPercent(35.0);
        $this->levelRoot = $this->levelWithPercent(30.0);

        // НДС 5% на всё окно сценария.
        DB::table('vat')->delete();
        DB::table('vat')->insert([
            'id' => 900100,
            'value' => 5,
            'dateFrom' => '2020-01-01',
            'dateTo' => '2050-01-01',
        ]);

        // Цепочка: SELLER → MENTOR → ROOT. activity=1 «Активен».
        // Порядок вставки — от корня вниз: consultant.inviter ссылается на
        // саму таблицу, и FK проверяется сразу (он не DEFERRABLE).
        foreach ([
            [self::ROOT, null, 'Корень Тестовый', $this->levelRoot],
            [self::MENTOR, self::ROOT, 'Наставник Тестовый', $this->levelTopFc],
            [self::SELLER, self::MENTOR, 'Продавец Тестовый', $this->levelExpert],
        ] as [$id, $inviter, $name, $levelId]) {
            $this->makeConsultant($id, $inviter, $name, $levelId);
        }

        // ⚠ legacy `product`/`program` на проде — ПРЕДСТАВЛЕНИЯ над
        // products_catalog/programs_catalog (слияние каталогов, 2026-08).
        // Писать надо в таблицы: у programs_catalog.product_id NOT NULL,
        // через вью такую строку не вставить.
        DB::table('products_catalog')->insert([
            'id' => self::PRODUCT,
            'name' => 'Тестовый продукт',
        ]);
        DB::table('programs_catalog')->insert([
            'id' => self::PROGRAM,
            'product_id' => self::PRODUCT,
            'name' => 'Тестовая программа',
            'ds_percent' => 40,
        ]);

        DB::table('contract')->insert([
            'id' => self::CONTRACT,
            'consultant' => self::SELLER,
            'product' => self::PRODUCT,
            'program' => self::PROGRAM,
            'number' => 'CHAR-0001',
            'openDate' => '2026-07-01',
        ]);

        DB::table('transaction')->insert([
            'id' => self::TRANSACTION,
            'contract' => self::CONTRACT,
            'amount' => 105_000,
            'amountRUB' => 105_000,
            'currency' => 67,          // RUB
            'currencyRate' => 1,
            'date' => '2026-07-15',
            'dateMonth' => '2026-07',
            'dateYear' => '2026',
        ]);
    }

    /** Партнёр + его открывающая строка qualificationLog на месяц сделки. */
    private function makeConsultant(int $id, ?int $inviter, string $name, int $levelId): void
    {
        DB::table('consultant')->insert([
            'id' => $id,
            'inviter' => $inviter,
            'personName' => $name,
            'activity' => 1,
            'status_and_lvl' => $levelId,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);

        // Уровень месяца сделки резолвится из qualificationLog: открывающая
        // строка на 1-е число месяца (см. getQualificationLevel).
        DB::table('qualificationLog')->insert([
            'consultant' => $id,
            'date' => '2026-07-01 00:00:00',
            'nominalLevel' => $levelId,
            'calculationLevel' => $levelId,
            'consultantPersonName' => $name,
        ]);
    }

    /**
     * Уровень с нужным процентом. Проценты в справочнике реальные, поэтому
     * подбираем ближайший и фиксируем ожидание от него, а не хардкодим id.
     */
    private function levelWithPercent(float $percent): int
    {
        $row = DB::table('status_levels')->where('percent', $percent)->first();
        $this->assertNotNull(
            $row,
            "В status_levels нет уровня с percent={$percent} — сценарий теста надо пересобрать"
        );

        return (int) $row->id;
    }
}
