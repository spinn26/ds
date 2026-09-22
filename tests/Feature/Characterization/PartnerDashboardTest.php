<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Кабинет партнёра (GET /dashboard).
 *
 * Сетка ПОД вынос. Это витрина показателей, по которым партнёр судит о своём
 * месяце, поэтому закреплены источники цифр:
 *   - ЛП, ГП и НГП берутся из журнала квалификаций за период, а не из
 *     денормализованных колонок карточки: те устаревают, и на проде в них
 *     встречались суммы в миллионы при пороге активации 500;
 *   - объём первой линии считается по личным баллам приглашённых, а
 *     каскадные строки цепочки (порядок ≥ 2) пишут ноль и потому не
 *     задваивают сумму;
 *   - уровень месяца — старший из номинального и расчётного;
 *   - деньги первой линии считаются по каноничному курсу платформы:
 *     один балл равен ста рублям;
 *   - счётчик «Набрано N / 500» живёт по ПЕРИОДУ, а не по месяцу: это число,
 *     по которому расторгают агентский договор, и обнулять его в месяце без
 *     продаж нельзя.
 */
class PartnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2800001;
    private const FIRST_LINE = 2800002;
    private const DEEP = 2800003;

    private const MONTH = '2026-07';

    private User $user;
    private int $seq = 2800100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- Объёмы ----------------

    /** ЛП, ГП и НГП приходят из журнала квалификаций за выбранный месяц. */
    #[Test]
    public function the_volumes_come_from_the_qualification_log(): void
    {
        $this->qlog(self::PARTNER, self::MONTH . '-15', [
            'personalVolume' => 500, 'groupVolume' => 1_200, 'groupVolumeCumulative' => 4_200,
        ]);

        $v = $this->dashboard()['volumes'];

        $this->assertEqualsWithDelta(500, $v['personalVolume'], 0.01);
        $this->assertEqualsWithDelta(1_200, $v['groupVolume'], 0.01);
        $this->assertEqualsWithDelta(4_200, $v['groupVolumeCumulative'], 0.01);
    }

    /**
     * ⚠ Денормализованная колонка карточки на показатели не влияет: она
     * устаревает, и на проде в ней встречались суммы в миллионы при пороге
     * активации 500.
     */
    #[Test]
    public function the_denormalised_card_column_is_ignored(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)
            ->update(['personalVolume' => 9_999_999]);
        $this->qlog(self::PARTNER, self::MONTH . '-15', ['personalVolume' => 500]);

        $this->assertEqualsWithDelta(500,
            $this->dashboard()['volumes']['personalVolume'], 0.01);
    }

    /** Показатели предыдущего месяца берутся из его же записи. */
    #[Test]
    public function the_previous_month_has_its_own_figures(): void
    {
        $this->qlog(self::PARTNER, self::MONTH . '-15', ['personalVolume' => 500]);
        $this->qlog(self::PARTNER, '2026-06-15', ['personalVolume' => 300]);

        $v = $this->dashboard()['volumes'];

        $this->assertEqualsWithDelta(500, $v['personalVolume'], 0.01);
        $this->assertEqualsWithDelta(300, $v['prevPersonalVolume'], 0.01);
    }

    // ---------------- Первая линия ----------------

    /**
     * ⚠ Объём первой линии — это личные баллы приглашённых. Каскадные строки
     * цепочки пишут ноль в личных баллах, поэтому сумма не задваивается.
     */
    #[Test]
    public function the_first_line_volume_counts_personal_points_only(): void
    {
        // Личная продажа приглашённого.
        $this->commission(self::FIRST_LINE, ['chainOrder' => 1, 'personalVolume' => 100]);
        // Каскадная строка того же приглашённого — личных баллов нет.
        $this->commission(self::FIRST_LINE, ['chainOrder' => 2, 'personalVolume' => 0]);
        // Партнёр второй линии в объём первой не входит.
        $this->commission(self::DEEP, ['chainOrder' => 1, 'personalVolume' => 700]);

        $v = $this->dashboard()['volumes'];

        $this->assertEqualsWithDelta(100, $v['firstLineVolume'], 0.01);
        $this->assertEqualsWithDelta(10_000, $v['firstLineVolumeRub'], 0.01,
            'один балл равен ста рублям');
    }

    /** У прошлого месяца свой объём первой линии — он считается отдельно. */
    #[Test]
    public function the_previous_first_line_volume_is_counted_separately(): void
    {
        $this->commission(self::FIRST_LINE, ['chainOrder' => 1, 'personalVolume' => 100]);
        $this->commission(self::FIRST_LINE, [
            'chainOrder' => 1, 'personalVolume' => 40,
            'dateMonth' => '2026-06', 'createdAt' => '2026-06-15 00:00:00',
        ]);

        $v = $this->dashboard()['volumes'];

        $this->assertEqualsWithDelta(100, $v['firstLineVolume'], 0.01);
        $this->assertEqualsWithDelta(40, $v['prevFirstLineVolume'], 0.01);
    }

    // ---------------- Команда ----------------

    /**
     * В команду считаются все потомки, а первая линия — только прямые.
     *
     * ⚠ «Всего партнёров» включает и самого смотрящего: обход дерева отдаёт
     * поддерево вместе с корнем.
     */
    #[Test]
    public function the_team_counts_all_descendants(): void
    {
        $team = $this->dashboard()['team'];

        $this->assertSame(3, $team['totalPartners'],
            'сам партнёр, приглашённый и его подопечный');
        $this->assertSame(1, $team['firstLineAll'], 'первая линия — только прямые');
    }

    // ---------------- Уровень ----------------

    /** Из номинального и расчётного уровня показывается старший. */
    #[Test]
    public function the_higher_level_is_shown(): void
    {
        $this->qlog(self::PARTNER, self::MONTH . '-15', [
            'nominalLevel' => 2, 'calculationLevel' => 4,
        ]);

        $level = $this->dashboard()['qualification']['level'];

        $this->assertSame(4, $level['level']);
        $this->assertSame('ФК', $level['title']);
    }

    /** Период возвращается тем же, что и запрошен. */
    #[Test]
    public function the_period_echoes_the_request(): void
    {
        $this->assertSame(self::MONTH, $this->dashboard()['period']);
    }

    // ---------------- Счётчик периода (Набрано N / 500) ----------------

    /**
     * ⚠ Счётчик считает ВЕСЬ период, а не выбранный месяц.
     *
     * Кейс Чекана (1528): продажи в июне и июле, в сентябре пусто — карточка
     * показывала «0 из 500», хотя за годовой период набрано 11,17 и до
     * расторжения договора ещё далеко.
     */
    #[Test]
    public function the_period_counter_survives_a_month_without_sales(): void
    {
        $this->activePeriod();
        $this->ownDeal('2026-06-17 13:33:15', 3.69);

        // Смотрим июль — в нём продаж нет.
        $info = $this->dashboard()['statusInfo'];

        $this->assertEqualsWithDelta(3.69, $info['currentPoints'], 0.01,
            'месяц без продаж не обнуляет счётчик периода');
        $this->assertSame(500, $info['requiredPoints']);
    }

    /** Сделки прошлого периода в счётчик не входят: год обнуляется. */
    #[Test]
    public function deals_before_the_period_start_do_not_count(): void
    {
        $this->activePeriod();
        $this->ownDeal('2025-11-27 13:33:19', 1.23);
        $this->ownDeal('2026-06-17 13:33:15', 3.69);

        $this->assertEqualsWithDelta(3.69,
            $this->dashboard()['statusInfo']['currentPoints'], 0.01);
    }

    /** Денормализованная колонка карточки на счётчик не влияет. */
    #[Test]
    public function the_period_counter_ignores_the_card_column(): void
    {
        $this->activePeriod();
        DB::table('consultant')->where('id', self::PARTNER)
            ->update(['personalVolume' => 9_999_999]);
        $this->ownDeal('2026-06-17 13:33:15', 3.69);

        $this->assertEqualsWithDelta(3.69,
            $this->dashboard()['statusInfo']['currentPoints'], 0.01);
    }

    // ================================================================

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/dashboard?month=' . self::MONTH)
            ->assertOk()->json();
    }

    /** @param array<string, mixed> $attrs */
    private function qlog(int $consultant, string $date, array $attrs = []): void
    {
        DB::table('qualificationLog')->insert(array_merge([
            'id' => $this->seq++,
            'consultant' => $consultant,
            'date' => $date,
            'personalVolume' => 0, 'groupVolume' => 0, 'groupVolumeCumulative' => 0,
            'nominalLevel' => 1, 'calculationLevel' => 1,
            'createdAt' => $date . ' 00:00:00',
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function commission(int $consultant, array $attrs): void
    {
        DB::table('commission')->insert(array_merge([
            'id' => $this->seq++,
            'consultant' => $consultant,
            'transaction' => null,
            'dateMonth' => self::MONTH,
            'dateYear' => '2026',
            'amountRUB' => 0,
            'personalVolume' => 0, 'groupVolume' => 0,
            'createdAt' => self::MONTH . '-15 00:00:00',
        ], $attrs));
    }

    /** Активный партнёр с годовым периодом 01.06.2026 → 01.06.2027. */
    private function activePeriod(): void
    {
        DB::table('consultant')->where('id', self::PARTNER)->update([
            'dateActivity' => '2026-06-01 00:00:00',
            'yearPeriodEnd' => '2027-06-01 00:00:00',
        ]);
    }

    /** Собственная сделка партнёра: контракт на него + транзакция с баллами. */
    private function ownDeal(string $date, float $points): void
    {
        $contract = $this->seq++;

        DB::table('contract')->insert([
            'id' => $contract,
            'consultant' => self::PARTNER,
            'number' => 'PD-' . $contract,
            'status' => 1,
            'ammount' => 1_000,
            'createDate' => $date,
        ]);

        DB::table('transaction')->insert([
            'id' => $this->seq++,
            'contract' => $contract,
            'date' => $date,
            'dateMonth' => substr($date, 0, 7),
            'dateYear' => (int) substr($date, 0, 4),
            'amountRUB' => 1_000,
            'commissionsAmountRUB' => 0,
            'netRevenueRUB' => 0,
            'profitRUB' => 0,
            'personalVolume' => $points,
        ]);
    }

    private function seedFixture(): void
    {
        $this->user = new User();
        $this->user->id = 2800900;
        $this->user->email = 'dashboard@test.local';
        $this->user->firstName = 'Кабинет';
        $this->user->lastName = 'Тестовый';
        $this->user->role = 'consultant';
        $this->user->password = bcrypt('secret123');
        $this->user->save();

        // Раздельными вставками: набор ключей в строках разный, а батч
        // требует одинакового — иначе значения молча съезжают по колонкам.
        DB::table('consultant')->insert([
            'id' => self::PARTNER, 'webUser' => $this->user->id,
            'personName' => 'Кабинетов Партнёр', 'activity' => 1, 'active' => true,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('consultant')->insert([
            ['id' => self::FIRST_LINE, 'inviter' => self::PARTNER,
                'personName' => 'Первая Линия', 'activity' => 1, 'active' => true,
                'dateCreated' => '2026-01-01 00:00:00'],
            ['id' => self::DEEP, 'inviter' => self::FIRST_LINE,
                'personName' => 'Вторая Линия', 'activity' => 1, 'active' => true,
                'dateCreated' => '2026-01-01 00:00:00'],
        ]);
    }
}
