<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Раздел «Квалификации» (/admin/qualifications).
 *
 * Сетка ПОД вынос. Здесь три места, каждое из которых уже приводило к
 * неправильным цифрам на экране:
 *   - поиск идёт по ЖИВОМУ имени из consultant, а не по денормализованному
 *     имени в логе: у части legacy-строк денорм-имя указывает на другого
 *     человека, и получалось «ищем X — показан Y»;
 *   - НГП держится carry-forward: строка финализа Отрыв/ОП приходит с датой
 *     конца месяца и ПУСТЫМ накопительным ГП, и, будучи самой свежей, иначе
 *     обнуляла бы НГП на экране;
 *   - открытый месяц показывается ЖИВЫМ (считается из транзакций), закрытый
 *     и исторический — зафиксированным снимком.
 */
class QualificationsListingTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2200001;
    private const OTHER = 2200002;

    /** Открытый месяц: позже HISTORICAL_CUTOFF и не заморожен. */
    private const OPEN_MONTH = '2026-07';
    /** Исторический месяц: раньше HISTORICAL_CUTOFF. */
    private const OLD_MONTH = '2026-04';

    private User $admin;
    private int $seq = 2200100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    // ---------------- Поиск и фильтры ----------------

    /**
     * ⚠ Поиск идёт по имени из карточки партнёра, а не по имени, записанному
     * в самом логе: у legacy-строк они расходятся, и поиск показывал не того.
     */
    #[Test]
    public function the_search_uses_the_live_partner_name(): void
    {
        $this->log(self::PARTNER, self::OLD_MONTH . '-15', [
            'consultantPersonName' => 'Чужое Имя Из Лога',
        ]);

        $this->assertSame([self::PARTNER], $this->ids(['search' => 'Квалификаций']),
            'нашли по имени из карточки');
        $this->assertSame([], $this->ids(['search' => 'Чужое']),
            'по денорм-имени из лога не ищем — оно врёт');
    }

    /** Фильтр по статусу активности применяется в запросе, вместе с total. */
    #[Test]
    public function the_activity_filter_is_applied_server_side(): void
    {
        $this->log(self::PARTNER, self::OLD_MONTH . '-15');
        $this->log(self::OTHER, self::OLD_MONTH . '-15');

        $body = $this->list(['month' => self::OLD_MONTH, 'activity' => 'terminated']);

        $this->assertSame(1, $body['total'], 'total считается после фильтра, а не по всей выборке');
        $this->assertSame(self::OTHER, $body['data'][0]['consultant']);
    }

    /** «Только ненулевые» убирает партнёров с пустыми объёмами. */
    #[Test]
    public function the_non_zero_filter_drops_empty_logs(): void
    {
        $this->log(self::PARTNER, self::OLD_MONTH . '-15', ['personalVolume' => 0, 'groupVolume' => 0]);
        $this->log(self::OTHER, self::OLD_MONTH . '-15', ['personalVolume' => 5]);

        $this->assertSame([self::OTHER],
            $this->ids(['month' => self::OLD_MONTH, 'non_zero_only' => 1]));
    }

    // ---------------- НГП ----------------

    /**
     * ⚠ Строка финализа Отрыв/ОП приходит последней и с пустым накопительным
     * ГП. НГП обязан остаться прежним, а не обнулиться.
     */
    #[Test]
    public function the_cumulative_group_volume_survives_a_penalty_row(): void
    {
        $this->log(self::PARTNER, self::OLD_MONTH . '-10', ['groupVolumeCumulative' => 4_200]);
        // Финализ: более свежая строка, накопительный ГП пуст.
        $this->log(self::PARTNER, self::OLD_MONTH . '-20', ['groupVolumeCumulative' => null]);

        $row = $this->row(self::OLD_MONTH);

        $this->assertEqualsWithDelta(4_200, $row['current']['groupVolumeCumulative'], 0.01);
    }

    /** Порядок строк значения не имеет — правило устойчиво к нему. */
    #[Test]
    public function the_carry_forward_does_not_depend_on_row_order(): void
    {
        $this->log(self::PARTNER, self::OLD_MONTH . '-20', ['groupVolumeCumulative' => null]);
        $this->log(self::PARTNER, self::OLD_MONTH . '-10', ['groupVolumeCumulative' => 4_200]);

        $this->assertEqualsWithDelta(4_200,
            $this->row(self::OLD_MONTH)['current']['groupVolumeCumulative'], 0.01);
    }

    /**
     * 🐞 Строка, датированная ПОСЛЕДНИМ днём месяца, попадает в колонку
     * ПРЕДЫДУЩЕГО месяца, а не текущего.
     *
     * Причина: принадлежность месяцу определяется сравнением СТРОК —
     * `$l->date >= $start && $l->date <= $end`, где `$end` это «2026-04-30»
     * без времени, а в колонке лежит timestamp «2026-04-30 00:00:00». Строка
     * с временем длиннее, поэтому оказывается «больше» границы.
     *
     * Задевает это ровно те строки, которые пишет финализ Отрыв/ОП: он
     * датирует их концом месяца. На проде так датированы 1714 из 1716 строк
     * июля и 1826 из 3693 строк июня.
     *
     * Тест фиксирует поведение как есть: починка сдвигает цифры на финансовом
     * экране, это решение владельца, а не рефакторинга.
     */
    #[Test]
    public function a_row_dated_the_last_day_lands_in_the_previous_month(): void
    {
        $this->log(self::PARTNER, self::OLD_MONTH . '-30', ['personalVolume' => 111]);

        $row = $this->row(self::OLD_MONTH);

        $this->assertNull($row['current'], 'в текущем месяце пусто');
        $this->assertEqualsWithDelta(111, $row['previous']['personalVolume'], 0.01,
            'а строка ушла в предыдущий');
    }

    // ---------------- Уровень ----------------

    /** Из номинального и расчётного уровня показывается СТАРШИЙ. */
    #[Test]
    public function the_higher_of_the_two_levels_wins(): void
    {
        $this->log(self::PARTNER, self::OLD_MONTH . '-15', [
            'nominalLevel' => 2,      // Про
            'calculationLevel' => 4,  // ФК
        ]);

        $row = $this->row(self::OLD_MONTH);

        $this->assertSame(4, $row['current']['levelNum']);
        $this->assertSame('ФК', $row['current']['levelTitle']);
    }

    /** Если заполнен только один из двух — берётся он. */
    #[Test]
    public function a_single_level_is_used_as_is(): void
    {
        $this->log(self::PARTNER, self::OLD_MONTH . '-15', [
            'nominalLevel' => null, 'calculationLevel' => 3,
        ]);

        $this->assertSame(3, $this->row(self::OLD_MONTH)['current']['levelNum']);
    }

    // ---------------- Живой и закрытый месяц ----------------

    /**
     * Исторический месяц (раньше отсечки) показывается снимком: цифры лога
     * остаются как есть, признака live нет.
     */
    #[Test]
    public function a_historical_month_stays_a_snapshot(): void
    {
        $this->log(self::PARTNER, self::OLD_MONTH . '-15', ['personalVolume' => 111]);

        $current = $this->row(self::OLD_MONTH)['current'];

        $this->assertEqualsWithDelta(111, $current['personalVolume'], 0.01);
        $this->assertArrayNotHasKey('live', $current);
    }

    /** Открытый месяц пересчитывается живьём и помечается признаком. */
    #[Test]
    public function an_open_month_is_recomputed_live(): void
    {
        $this->log(self::PARTNER, self::OPEN_MONTH . '-15', ['personalVolume' => 111]);

        $current = $this->row(self::OPEN_MONTH)['current'];

        $this->assertTrue($current['live'] ?? false, 'открытый месяц идёт живым');
        $this->assertEqualsWithDelta(0, $current['personalVolume'], 0.01,
            'транзакций нет — живой объём нулевой, снимок не подставляется');
    }

    /** Замороженный месяц снова становится снимком. */
    #[Test]
    public function a_frozen_month_falls_back_to_the_snapshot(): void
    {
        $this->log(self::PARTNER, self::OPEN_MONTH . '-15', ['personalVolume' => 111]);
        DB::table('period_closures')->insert([
            'year' => 2026, 'month' => 7,
            'closed_at' => now(), 'closed_by' => $this->admin->id,
        ]);

        $current = $this->row(self::OPEN_MONTH)['current'];

        $this->assertEqualsWithDelta(111, $current['personalVolume'], 0.01);
        $this->assertArrayNotHasKey('live', $current);
    }

    // ================================================================

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function list(array $params = []): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/qualifications?' . http_build_query($params))
            ->assertOk()->json();
    }

    /**
     * @param array<string, mixed> $params
     * @return list<int>
     */
    private function ids(array $params = []): array
    {
        $params += ['month' => self::OLD_MONTH];

        return array_map(fn ($r) => (int) $r['consultant'], $this->list($params)['data']);
    }

    /** @return array<string, mixed> */
    private function row(string $month): array
    {
        $rows = collect($this->list(['month' => $month])['data'])->keyBy('consultant');

        return $rows[self::PARTNER];
    }

    /** @param array<string, mixed> $attrs */
    private function log(int $consultant, string $date, array $attrs = []): void
    {
        DB::table('qualificationLog')->insert(array_merge([
            'id' => $this->seq++,
            'consultant' => $consultant,
            'date' => $date,
            'personalVolume' => 10,
            'groupVolume' => 20,
            'groupVolumeCumulative' => 30,
            'nominalLevel' => 1,
            'calculationLevel' => 1,
            'createdAt' => $date . ' 00:00:00',
        ], $attrs));
    }

    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 2200900;
        $this->admin->email = 'quals@test.local';
        $this->admin->firstName = 'Квалификации';
        $this->admin->lastName = 'Тестовые';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('consultant')->insert([
            ['id' => self::PARTNER, 'personName' => 'Квалификаций Партнёр',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00'],
            ['id' => self::OTHER, 'personName' => 'Второй Партнёр',
                'activity' => 3, 'dateCreated' => '2026-01-01 00:00:00'],
        ]);
    }
}
