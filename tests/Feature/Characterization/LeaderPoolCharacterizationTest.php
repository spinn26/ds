<?php

namespace Tests\Feature\Characterization;

use App\Services\PoolRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ХАРАКТЕРИЗУЮЩИЙ тест лидерского пула (спека ✅Расчет пула).
 *
 * Главное, что здесь закрепляется — НАКОПИТЕЛЬНЫЙ делитель: стоимость доли
 * уровня L = фонд / число партнёров уровня L И ВЫШЕ. Это место уже переписывали
 * дважды: аудит 2026-07-13 прочитал счётчики спеки как «ровно уровня» (июнь
 * вырос со 129 566 ₽ до 268 998 ₽), 2026-07-16 бизнес подтвердил эталонной
 * таблицей накопительный вариант и его вернули. Тест ставит на это замок.
 *
 * Сценарий:
 *   выручка ДС 30 000 000 ₽ → фонд уровня = 1% = 300 000 ₽
 *   два ТОП ФК (уровень 6) и один Голд ДС (уровень 8)
 *
 *   доля 6 = 300 000 / 3 (все лидеры 6+)        = 100 000
 *   доля 7 = 300 000 / 1 (только Голд)          = 300 000
 *   доля 8 = 300 000 / 1                        = 300 000
 *   доли 9 и 10 = 0 (партнёров нет)
 *
 *   выплата ТОП ФК  = доля 6                    = 100 000
 *   выплата Голд ДС = доля 6 + доля 7 + доля 8  = 700 000  (матрёшка)
 */
class LeaderPoolCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const TOP_A = 920001;
    private const TOP_B = 920002;
    private const GOLD = 920003;

    private const YEAR = 2026;
    private const MONTH = 7;

    private const REVENUE = 30_000_000.0;
    private const FUND = 300_000.0;

    private int $level6;
    private int $level8;
    private float $mandatory6;
    private float $mandatory8;

    protected function setUp(): void
    {
        parent::setUp();

        $l6 = DB::table('status_levels')->where('level', 6)->first();
        $l8 = DB::table('status_levels')->where('level', 8)->first();
        $this->assertNotNull($l6);
        $this->assertNotNull($l8);

        $this->level6 = (int) $l6->id;
        $this->level8 = (int) $l8->id;
        $this->mandatory6 = (float) $l6->mandatoryGP;
        $this->mandatory8 = (float) $l8->mandatoryGP;

        $this->seedRevenue();
    }

    #[Test]
    public function share_divisor_is_cumulative_by_level(): void
    {
        $this->seedLeaders();

        $r = app(PoolRunner::class)->run(self::YEAR, self::MONTH);

        $this->assertEqualsWithDelta(self::REVENUE, $r['revenue'], 0.01, 'выручка = Σ дохода ДС');
        $this->assertEqualsWithDelta(self::FUND, $r['fund'], 0.01, 'фонд = 1% выручки');

        $this->assertEqualsWithDelta(100_000.0, $r['shareValues'][6], 0.01, 'фонд / 3 (уровни 6+)');
        $this->assertEqualsWithDelta(300_000.0, $r['shareValues'][7], 0.01, 'фонд / 1 (уровни 7+)');
        $this->assertEqualsWithDelta(300_000.0, $r['shareValues'][8], 0.01, 'фонд / 1 (уровни 8+)');
        $this->assertEqualsWithDelta(0.0, $r['shareValues'][9], 0.01, 'партнёров уровня 9+ нет');
        $this->assertEqualsWithDelta(0.0, $r['shareValues'][10], 0.01);
    }

    #[Test]
    public function payout_stacks_all_shares_up_to_own_level(): void
    {
        $this->seedLeaders();

        $r = app(PoolRunner::class)->run(self::YEAR, self::MONTH);
        $byId = collect($r['participants'])->keyBy('id');

        $this->assertEqualsWithDelta(100_000.0, $byId[self::TOP_A]['payoutRub'], 0.01);
        $this->assertEqualsWithDelta(100_000.0, $byId[self::TOP_B]['payoutRub'], 0.01);
        $this->assertEqualsWithDelta(700_000.0, $byId[self::GOLD]['payoutRub'], 0.01,
            'матрёшка: доли 6 + 7 + 8');

        $this->assertEqualsWithDelta(900_000.0, $r['totalPaid'], 0.01);
    }

    /**
     * Дисквалификация по ОП НЕ перераспределяется между остальными: делитель
     * номинальный, доля выбывшего остаётся компании (спека §6.5).
     */
    #[Test]
    public function forfeited_share_is_not_redistributed(): void
    {
        $this->seedLeaders();
        // ТОП ФК B не добрал обязательный ГП → в делителе остаётся, денег нет.
        DB::table('qualificationLog')
            ->where('consultant', self::TOP_B)
            ->update(['groupVolume' => $this->mandatory6 - 1]);

        $r = app(PoolRunner::class)->run(self::YEAR, self::MONTH);
        $byId = collect($r['participants'])->keyBy('id');

        $this->assertEqualsWithDelta(0.0, $byId[self::TOP_B]['payoutRub'], 0.01);
        $this->assertFalse($byId[self::TOP_B]['opOk']);
        $this->assertSame('ОП не выполнен', $byId[self::TOP_B]['disqualifyReason']);

        // Ключевое: доли остальных НЕ выросли — делитель прежний (3 и 1).
        $this->assertEqualsWithDelta(100_000.0, $r['shareValues'][6], 0.01, 'делитель остался 3');
        $this->assertEqualsWithDelta(100_000.0, $byId[self::TOP_A]['payoutRub'], 0.01);
        $this->assertEqualsWithDelta(700_000.0, $byId[self::GOLD]['payoutRub'], 0.01);
        $this->assertEqualsWithDelta(800_000.0, $r['totalPaid'], 0.01);
    }

    /** Снятая вручную галочка «Участвует» — тоже без перераспределения. */
    #[Test]
    public function manual_exclusion_keeps_the_divisor(): void
    {
        $this->seedLeaders();
        DB::table('pool_moderation')->insert([
            'year' => self::YEAR,
            'month' => self::MONTH,
            'consultant' => self::TOP_B,
            'participates' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = app(PoolRunner::class)->run(self::YEAR, self::MONTH);
        $byId = collect($r['participants'])->keyBy('id');

        $this->assertEqualsWithDelta(0.0, $byId[self::TOP_B]['payoutRub'], 0.01);
        $this->assertSame('Снята галочка «Участвует»', $byId[self::TOP_B]['disqualifyReason']);
        $this->assertEqualsWithDelta(100_000.0, $r['shareValues'][6], 0.01, 'делитель не изменился');
    }

    /** Отрыв строго больше 90% дисквалифицирует; ровно 90% — платится. */
    #[Test]
    public function detachment_above_ninety_percent_disqualifies(): void
    {
        $this->seedLeaders();
        DB::table('qualificationLog')->where('consultant', self::TOP_A)
            ->update(['gapValuePercentage' => 90.0]);
        DB::table('qualificationLog')->where('consultant', self::TOP_B)
            ->update(['gapValuePercentage' => 90.01]);

        $r = app(PoolRunner::class)->run(self::YEAR, self::MONTH);
        $byId = collect($r['participants'])->keyBy('id');

        $this->assertTrue($byId[self::TOP_A]['gapOk'], 'ровно 90% — не дисквалификация');
        $this->assertEqualsWithDelta(100_000.0, $byId[self::TOP_A]['payoutRub'], 0.01);

        $this->assertFalse($byId[self::TOP_B]['gapOk']);
        $this->assertEqualsWithDelta(0.0, $byId[self::TOP_B]['payoutRub'], 0.01);
    }

    /** Фиксация пишет poolLog и заводит начисление в баланс месяца. */
    #[Test]
    public function apply_write_persists_pool_log_and_balance(): void
    {
        $this->seedLeaders();

        $r = app(PoolRunner::class)->run(self::YEAR, self::MONTH, applyWrite: true);

        $this->assertSame(3, $r['written'], 'строки пишутся только получателям');

        $log = DB::table('poolLog')->orderBy('consultant')->get()->keyBy('consultant');
        $this->assertCount(3, $log);
        $this->assertEqualsWithDelta(700_000.0, (float) $log[self::GOLD]->poolBonus, 0.01);

        $balance = DB::table('consultantBalance')
            ->where('consultant', self::GOLD)
            ->where('dateMonth', '2026-07')
            ->first();
        $this->assertNotNull($balance, 'строка баланса за месяц создаётся');
        $this->assertEqualsWithDelta(700_000.0, (float) $balance->accruedPool, 0.01);
        $this->assertEqualsWithDelta(700_000.0, (float) $balance->accruedTotal, 0.01);
        $this->assertEqualsWithDelta(700_000.0, (float) $balance->remaining, 0.01);
    }

    /** Повторная фиксация после снятия галочки зануляет прежнее начисление. */
    #[Test]
    public function refixing_zeroes_out_a_partner_who_dropped_out(): void
    {
        $this->seedLeaders();
        $runner = app(PoolRunner::class);
        $runner->run(self::YEAR, self::MONTH, applyWrite: true);

        DB::table('pool_moderation')->insert([
            'year' => self::YEAR,
            'month' => self::MONTH,
            'consultant' => self::GOLD,
            'participates' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runner->run(self::YEAR, self::MONTH, applyWrite: true);

        $this->assertSame(
            0,
            DB::table('poolLog')->where('consultant', self::GOLD)->count(),
            'строка пула удалена'
        );
        $balance = DB::table('consultantBalance')
            ->where('consultant', self::GOLD)->where('dateMonth', '2026-07')->first();
        $this->assertEqualsWithDelta(0.0, (float) $balance->accruedPool, 0.01, 'начисление занулено');
    }

    // ================================================================

    /**
     * Выручка пула = Σ commissionsAmountRUB транзакций месяца (доход ДС),
     * а НЕ netRevenueRUB: на нём фонд июня был завышен примерно в 26 раз.
     */
    private function seedRevenue(): void
    {
        DB::table('transaction')->insert([
            'id' => 920100,
            'amountRUB' => self::REVENUE * 3,
            'commissionsAmountRUB' => self::REVENUE,
            'date' => '2026-07-15',
            'dateMonth' => '2026-07',
            'dateYear' => '2026',
        ]);
    }

    private function seedLeaders(): void
    {
        foreach ([
            [self::TOP_A, 'Топ ФК А', $this->level6, $this->mandatory6],
            [self::TOP_B, 'Топ ФК Б', $this->level6, $this->mandatory6],
            [self::GOLD, 'Голд ДС', $this->level8, $this->mandatory8],
        ] as [$id, $name, $levelId, $mandatory]) {
            DB::table('consultant')->insert([
                'id' => $id,
                'personName' => $name,
                'activity' => 1,          // Активен — иначе в пул не попадает
                'status_and_lvl' => $levelId,
                'dateCreated' => '2026-01-01 00:00:00',
            ]);

            // Уровень и выполнение ОП пул берёт из снимка ЗА РАСЧЁТНЫЙ МЕСЯЦ.
            // Дата — конец месяца: финализация пишет именно её, и нижняя
            // граница окна у пула это учитывает.
            DB::table('qualificationLog')->insert([
                'consultant' => $id,
                'date' => '2026-07-31 23:59:59',
                'nominalLevel' => $levelId,
                'calculationLevel' => $levelId,
                'groupVolume' => $mandatory + 1,   // ОП выполнен
                'gapValuePercentage' => 0,
                'consultantPersonName' => $name,
            ]);
        }
    }
}
