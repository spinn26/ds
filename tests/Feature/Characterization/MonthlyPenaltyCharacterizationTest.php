<?php

namespace Tests\Feature\Characterization;

use App\Services\MonthlyPenaltyRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ХАРАКТЕРИЗУЮЩИЙ тест месячной финализации: отрыв (§5.1) и недобор ОП (§5.2).
 *
 * Фиксирует ТЕКУЩЕЕ поведение перед рефакторингом. Пороги берём из настоящего
 * справочника status_levels (он в schema-фикстуре), чтобы тест не разошёлся с
 * продом при правке матрицы квалификаций:
 *   ТОП ФК (level 6): percent 40, mandatoryGP 12 000, otrif 70.
 *
 * Структура во всех сценариях одна:
 *
 *      MENTOR (ТОП ФК)
 *        ├── BRANCH_A ── SELLER_A
 *        └── BRANCH_B ── SELLER_B
 *
 * Комиссии наставника (chainOrder=2) бакетируются по первой линии: строка от
 * SELLER_A попадает в ветку BRANCH_A, от SELLER_B — в BRANCH_B.
 */
class MonthlyPenaltyCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const MENTOR = 910001;
    private const BRANCH_A = 910002;
    private const SELLER_A = 910003;
    private const BRANCH_B = 910004;
    private const SELLER_B = 910005;

    private const YEAR = 2026;
    private const MONTH = 7;
    private const DATE_MONTH = '2026-07';

    /** ТОП ФК: единственный уровень с otrif > 0 и понятным mandatoryGP. */
    private int $levelTopFc;
    private float $mandatoryGp;

    protected function setUp(): void
    {
        parent::setUp();

        $top = DB::table('status_levels')->where('level', 6)->first();
        $this->assertNotNull($top, 'В status_levels нет уровня 6 — фикстура сломана');
        $this->assertGreaterThan(0, (float) $top->otrif, 'У уровня 6 должен быть порог отрыва');

        $this->levelTopFc = (int) $top->id;
        $this->mandatoryGp = (float) $top->mandatoryGP;

        $this->seedStructure();
    }

    /**
     * Отрыв: ветка больше 70% от ПОЛНОГО ГП → её комиссии режутся вдвое.
     * Вторая ветка не трогается, ОП при этом выполнен.
     *
     *   ветка A = 10 000 (83.3%)  → множитель 0.5
     *   ветка B =  2 000 (16.7%)  → множитель 1.0
     *   ГП = 12 000 = mandatoryGP → ОП выполнен, opMult = 1.0
     */
    #[Test]
    public function detachment_halves_only_the_offending_branch(): void
    {
        $this->seedCommission(self::SELLER_A, groupVolume: 10_000, rub: 5_000);
        $this->seedCommission(self::SELLER_B, groupVolume: 2_000, rub: 1_000);

        $result = app(MonthlyPenaltyRunner::class)->run(self::YEAR, self::MONTH, applyWrite: true);

        $this->assertFalse($result['frozen']);
        $this->assertSame(1, $result['affected'], 'режется ровно одна строка — ветки A');

        $rows = DB::table('commission')
            ->whereIn('commissionFromOtherConsultant', [self::SELLER_A, self::SELLER_B])
            ->get()
            ->keyBy('commissionFromOtherConsultant');

        $a = $rows[self::SELLER_A];
        $this->assertTrue((bool) $a->reduction, 'ветка A помечена как урезанная');
        $this->assertEqualsWithDelta(2_500.0, (float) $a->amountRUB, 0.01, '5 000 × 0.5');
        $this->assertEqualsWithDelta(2_500.0, (float) $a->groupBonusRub, 0.01);
        $this->assertEqualsWithDelta(5_000.0, (float) $a->groupBonusRubBeforeGapReduction, 0.01, 'снимок «до»');
        $this->assertEqualsWithDelta(50.0, (float) $a->withheldPercent, 0.01);
        $this->assertEqualsWithDelta(2_500.0, (float) $a->withheldForGap, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $a->withheldForCommission, 0.01, 'ОП выполнен — удержания по нему нет');

        $b = $rows[self::SELLER_B];
        $this->assertNotTrue((bool) $b->reduction, 'ветка B не трогается');
        $this->assertEqualsWithDelta(1_000.0, (float) $b->amountRUB, 0.01);
    }

    /** Снимок квалификации за месяц: процент отрыва считается от ПОЛНОГО ГП. */
    #[Test]
    public function detachment_writes_qualification_snapshot(): void
    {
        $this->seedCommission(self::SELLER_A, groupVolume: 10_000, rub: 5_000);
        $this->seedCommission(self::SELLER_B, groupVolume: 2_000, rub: 1_000);

        app(MonthlyPenaltyRunner::class)->run(self::YEAR, self::MONTH, applyWrite: true);

        $log = $this->monthEndSnapshot(self::MENTOR);

        $this->assertTrue((bool) $log->gap);
        // ⚠ Фиксируем как есть: сюда пишется id КОНСУЛЬТАНТА (ветка первой
        // линии, не продавец), хотя колонка объявлена FK на qualificationLog.
        // Это баг схемы/кода, описан в отчёте по Этапу 0 — тест его не чинит,
        // а закрепляет текущее поведение, чтобы рефакторинг его не сдвинул.
        $this->assertSame(self::BRANCH_A, (int) $log->branchWithGap, 'ветка-нарушитель — первая линия, не продавец');
        $this->assertEqualsWithDelta(83.33, (float) $log->gapValuePercentage, 0.01, '10 000 / 12 000');
        $this->assertEqualsWithDelta(10_000.0, (float) $log->gapValue, 0.01);
        $this->assertEqualsWithDelta(12_000.0, (float) $log->groupVolume, 0.01, 'ГП = ЛП + все ветки');
        $this->assertStringContainsString('Отрыв', (string) $log->result);
        $this->assertStringNotContainsString('ОП', (string) $log->result, 'ОП выполнен');
    }

    /**
     * Недобор ОП: ГП меньше mandatoryGP → все групповые комиссии × 0.8.
     * Ветки при этом по 50% — отрыва нет.
     */
    #[Test]
    public function op_shortfall_cuts_every_group_commission(): void
    {
        $this->seedCommission(self::SELLER_A, groupVolume: 3_000, rub: 3_000);
        $this->seedCommission(self::SELLER_B, groupVolume: 3_000, rub: 1_000);

        $result = app(MonthlyPenaltyRunner::class)->run(self::YEAR, self::MONTH, applyWrite: true);

        $this->assertSame(2, $result['affected'], 'режутся обе ветки');

        $rows = DB::table('commission')
            ->whereIn('commissionFromOtherConsultant', [self::SELLER_A, self::SELLER_B])
            ->get()
            ->keyBy('commissionFromOtherConsultant');

        $this->assertEqualsWithDelta(2_400.0, (float) $rows[self::SELLER_A]->amountRUB, 0.01, '3 000 × 0.8');
        $this->assertEqualsWithDelta(800.0, (float) $rows[self::SELLER_B]->amountRUB, 0.01, '1 000 × 0.8');
        $this->assertEqualsWithDelta(600.0, (float) $rows[self::SELLER_A]->withheldForCommission, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $rows[self::SELLER_A]->withheldForGap, 0.01, 'отрыва нет');

        $log = $this->monthEndSnapshot(self::MENTOR);
        $this->assertFalse((bool) $log->gap);
        $this->assertNull($log->gapValuePercentage);
        $this->assertStringContainsString('ОП', (string) $log->result);
    }

    /**
     * Комбинация §5.3: сначала отрыв по ветке, затем ОП на результат.
     * Ветка A: 5 000 × 0.5 × 0.8 = 2 000.
     */
    #[Test]
    public function detachment_and_op_multiply(): void
    {
        // A = 8 000 (80% > 70%), B = 2 000 → ГП 10 000 < mandatoryGP 12 000.
        $this->seedCommission(self::SELLER_A, groupVolume: 8_000, rub: 5_000);
        $this->seedCommission(self::SELLER_B, groupVolume: 2_000, rub: 1_000);

        app(MonthlyPenaltyRunner::class)->run(self::YEAR, self::MONTH, applyWrite: true);

        $a = DB::table('commission')->where('commissionFromOtherConsultant', self::SELLER_A)->first();
        $b = DB::table('commission')->where('commissionFromOtherConsultant', self::SELLER_B)->first();

        $this->assertEqualsWithDelta(2_000.0, (float) $a->amountRUB, 0.01, '5 000 × 0.5 × 0.8');
        $this->assertEqualsWithDelta(60.0, (float) $a->withheldPercent, 0.01, '1 − 0.5×0.8');
        $this->assertEqualsWithDelta(2_500.0, (float) $a->withheldForGap, 0.01, 'доля отрыва');
        $this->assertEqualsWithDelta(500.0, (float) $a->withheldForCommission, 0.01, 'доля ОП уже от урезанного');

        $this->assertEqualsWithDelta(800.0, (float) $b->amountRUB, 0.01, 'ветка без отрыва — только ОП');

        $log = $this->monthEndSnapshot(self::MENTOR);
        $this->assertStringContainsString('Отрыв', (string) $log->result);
        $this->assertStringContainsString('ОП', (string) $log->result);
    }

    /**
     * База доли ветки — ПОЛНЫЙ ГП (ЛП наставника + все ветки), а не сумма одних
     * веток. Разница не косметическая: собственные баллы наставника «уводят»
     * отрыв, и раньше знаменатель был занижен — отрыв срабатывал чаще, а
     * добавленные партнёру баллы не помогали.
     *
     *   ЛП наставника = 4 000, ветка A = 8 000, ветка B = 2 000
     *   полный ГП = 14 000 → доля A = 57.1%  → отрыва НЕТ
     *   сумма веток = 10 000 → доля A = 80%  → отрыв был бы
     *   ОП: 14 000 >= 12 000 → выполнен
     */
    #[Test]
    public function branch_share_is_measured_against_full_group_volume(): void
    {
        $this->seedPersonalVolume(4_000);
        $this->seedCommission(self::SELLER_A, groupVolume: 8_000, rub: 5_000);
        $this->seedCommission(self::SELLER_B, groupVolume: 2_000, rub: 1_000);

        $result = app(MonthlyPenaltyRunner::class)->run(self::YEAR, self::MONTH, applyWrite: true);

        $this->assertSame(0, $result['affected'], 'ни отрыва, ни недобора ОП');

        $a = DB::table('commission')->where('commissionFromOtherConsultant', self::SELLER_A)->first();
        $this->assertEqualsWithDelta(5_000.0, (float) $a->amountRUB, 0.01, 'ветка A не урезана');

        $log = $this->monthEndSnapshot(self::MENTOR);
        $this->assertFalse((bool) $log->gap);
        $this->assertEqualsWithDelta(14_000.0, (float) $log->groupVolume, 0.01, 'ГП = ЛП + ветки');
        $this->assertEqualsWithDelta(4_000.0, (float) $log->personalVolume, 0.01);
        $this->assertSame('OK', (string) $log->result);
    }

    /**
     * Граница порога: отрыв срабатывает СТРОГО больше 70%, ровно 70% — нет.
     *
     *   ЛП 2 000 + ветки 9 800 и 2 200 = полный ГП 14 000
     *   доля ветки A = 9 800 / 14 000 = ровно 70% → штрафа нет
     *   ОП: 14 000 >= 12 000 → выполнен, ничего не мешает
     */
    #[Test]
    public function branch_exactly_at_threshold_is_not_penalised(): void
    {
        $this->seedPersonalVolume(2_000);
        $this->seedCommission(self::SELLER_A, groupVolume: 9_800, rub: 5_000);
        $this->seedCommission(self::SELLER_B, groupVolume: 2_200, rub: 1_000);

        $result = app(MonthlyPenaltyRunner::class)->run(self::YEAR, self::MONTH, applyWrite: true);

        $this->assertSame(0, $result['affected'], 'ровно порог — не отрыв');

        $a = DB::table('commission')->where('commissionFromOtherConsultant', self::SELLER_A)->first();
        $this->assertEqualsWithDelta(5_000.0, (float) $a->amountRUB, 0.01);

        $log = $this->monthEndSnapshot(self::MENTOR);
        $this->assertFalse((bool) $log->gap);
        $this->assertSame('OK', (string) $log->result);
    }

    /** Повторный прогон не режет повторно: флаг reduction делает шаг идемпотентным. */
    #[Test]
    public function second_run_does_not_penalise_twice(): void
    {
        $this->seedCommission(self::SELLER_A, groupVolume: 10_000, rub: 5_000);
        $this->seedCommission(self::SELLER_B, groupVolume: 2_000, rub: 1_000);

        $runner = app(MonthlyPenaltyRunner::class);
        $runner->run(self::YEAR, self::MONTH, applyWrite: true);
        $second = $runner->run(self::YEAR, self::MONTH, applyWrite: true);

        $this->assertSame(0, $second['affected'], 'второй прогон ничего не режет');

        $a = DB::table('commission')->where('commissionFromOtherConsultant', self::SELLER_A)->first();
        $this->assertEqualsWithDelta(2_500.0, (float) $a->amountRUB, 0.01, 'сумма не уехала дальше');

        $this->assertSame(
            1,
            DB::table('qualificationLog')
                ->where('consultant', self::MENTOR)
                ->where('date', $this->monthEnd())
                ->count(),
            'penalty-строка снимка не дублируется'
        );
    }

    /** ГП месяца накапливается в НГП поверх базы предыдущих месяцев. */
    #[Test]
    public function cumulative_group_volume_adds_month_on_top_of_carry(): void
    {
        DB::table('qualificationLog')->insert([
            'consultant' => self::MENTOR,
            'date' => '2026-06-30 23:59:59',
            'groupVolumeCumulative' => 100_000,
            'groupVolume' => 0,
            'nominalLevel' => $this->levelTopFc,
            'calculationLevel' => $this->levelTopFc,
            'consultantPersonName' => 'Наставник',
        ]);

        $this->seedCommission(self::SELLER_A, groupVolume: 10_000, rub: 5_000);
        $this->seedCommission(self::SELLER_B, groupVolume: 2_000, rub: 1_000);

        app(MonthlyPenaltyRunner::class)->run(self::YEAR, self::MONTH, applyWrite: true);

        $log = $this->monthEndSnapshot(self::MENTOR);
        $this->assertEqualsWithDelta(112_000.0, (float) $log->groupVolumeCumulative, 0.01,
            'НГП = база до месяца (100 000) + ГП месяца (12 000)');
    }

    /** Превью ничего не пишет. */
    #[Test]
    public function preview_does_not_touch_the_ledger(): void
    {
        $this->seedCommission(self::SELLER_A, groupVolume: 10_000, rub: 5_000);
        $this->seedCommission(self::SELLER_B, groupVolume: 2_000, rub: 1_000);

        $result = app(MonthlyPenaltyRunner::class)->run(self::YEAR, self::MONTH, applyWrite: false);

        $this->assertSame(1, $result['affected'], 'превью считает то же самое');

        $a = DB::table('commission')->where('commissionFromOtherConsultant', self::SELLER_A)->first();
        $this->assertEqualsWithDelta(5_000.0, (float) $a->amountRUB, 0.01, 'деньги не тронуты');
        $this->assertNotTrue((bool) $a->reduction);
        $this->assertSame(0, DB::table('qualificationLog')->where('date', $this->monthEnd())->count());
    }

    /** Закрытый период не финализируется даже с applyWrite. */
    #[Test]
    public function frozen_period_is_refused(): void
    {
        DB::table('period_closures')->insert([
            'year' => self::YEAR,
            'month' => self::MONTH,
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedCommission(self::SELLER_A, groupVolume: 10_000, rub: 5_000);

        $result = app(MonthlyPenaltyRunner::class)->run(self::YEAR, self::MONTH, applyWrite: true);

        $this->assertTrue($result['frozen']);
        $this->assertSame(0, $result['affected']);
        $this->assertStringContainsString('закрыт', $result['error']);

        $a = DB::table('commission')->where('commissionFromOtherConsultant', self::SELLER_A)->first();
        $this->assertEqualsWithDelta(5_000.0, (float) $a->amountRUB, 0.01);
    }

    // ================================================================

    private function monthEnd(): string
    {
        return \Carbon\Carbon::create(self::YEAR, self::MONTH, 1)->endOfMonth()->toDateTimeString();
    }

    private function monthEndSnapshot(int $consultantId): object
    {
        $log = DB::table('qualificationLog')
            ->where('consultant', $consultantId)
            ->where('date', $this->monthEnd())
            ->first();
        $this->assertNotNull($log, 'снимок квалификации за месяц не записан');

        return $log;
    }

    private function seedStructure(): void
    {
        $start = (int) DB::table('status_levels')->where('level', 1)->value('id');

        // От корня вниз: consultant.inviter ссылается на саму таблицу.
        foreach ([
            [self::MENTOR, null, 'Наставник', $this->levelTopFc],
            [self::BRANCH_A, self::MENTOR, 'Ветка А', $start],
            [self::SELLER_A, self::BRANCH_A, 'Продавец А', $start],
            [self::BRANCH_B, self::MENTOR, 'Ветка Б', $start],
            [self::SELLER_B, self::BRANCH_B, 'Продавец Б', $start],
        ] as [$id, $inviter, $name, $level]) {
            DB::table('consultant')->insert([
                'id' => $id,
                'inviter' => $inviter,
                'personName' => $name,
                'activity' => 1,
                'status_and_lvl' => $level,
                'dateCreated' => '2026-01-01 00:00:00',
            ]);
        }

        // ⚠ КОСТЫЛЬ ПОД ЖИВОЙ БАГ, а не под тест.
        //
        // qualificationLog.branchWithGap объявлен внешним ключом на саму
        // qualificationLog(id), а MonthlyPenaltyRunner пишет туда id
        // КОНСУЛЬТАНТА (ветку первой линии). На проде это проходит случайно:
        // id консультантов (до ~2100) почти всегда существуют среди id журнала
        // (1..54566), и FK совпадает по совпадению, сохраняя бессмысленную
        // ссылку. В чистой тестовой БД журнала нет — и вставка честно падает.
        //
        // Чтобы тест характеризовал РАСЧЁТ, а не спотыкался о схему, повторяем
        // прод-ситуацию: заводим строки журнала с id, равными id веток.
        // Убрать сразу, как только баг будет починен (см. отчёт по Этапу 0).
        foreach ([self::BRANCH_A, self::BRANCH_B] as $branchId) {
            DB::table('qualificationLog')->insert([
                'id' => $branchId,
                'consultant' => $branchId,
                'date' => '2026-01-31 23:59:59',
                'consultantPersonName' => 'FK-заглушка',
            ]);
        }
    }

    /** Личные продажи наставника: строка chainOrder=1 на него самого. */
    private function seedPersonalVolume(float $points): void
    {
        DB::table('commission')->insert([
            'consultant' => self::MENTOR,
            'chainOrder' => 1,
            'type' => 'transaction',
            'personalVolume' => $points,
            'groupVolume' => $points,
            'date' => '2026-07-10',
            'dateMonth' => self::DATE_MONTH,
            'dateYear' => (string) self::YEAR,
            'createdAt' => now(),
        ]);
    }

    /**
     * Групповая комиссия наставника от конкретного продавца. Ветку рантайм
     * выводит сам: поднимается от продавца вверх до прямого ребёнка наставника.
     */
    private function seedCommission(int $sellerId, float $groupVolume, float $rub): void
    {
        DB::table('commission')->insert([
            'consultant' => self::MENTOR,
            'commissionFromOtherConsultant' => $sellerId,
            'chainOrder' => 2,
            'type' => 'transaction',
            'groupVolume' => $groupVolume,
            'groupBonus' => $rub / 100,
            'groupBonusRub' => $rub,
            'amountRUB' => $rub,
            'date' => '2026-07-15',
            'dateMonth' => self::DATE_MONTH,
            'dateYear' => (string) self::YEAR,
            'createdAt' => now(),
        ]);
    }
}
