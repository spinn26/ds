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
 *     один балл равен ста рублям.
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
