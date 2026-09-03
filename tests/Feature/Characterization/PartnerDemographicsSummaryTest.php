<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use App\Services\Reports\PartnerDemographicsSummaryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Сводка «Демография сети»: проценты по полу и распределение по возрасту.
 *
 * Это ответ на исходный вопрос («какой процент мужчин и женщин, диаграмма по
 * возрасту»), поэтому арифметику закрепляем на фикстуре, где доли считаются
 * в уме: 4 партнёра — 2 мужчины, 1 женщина, 1 без пола.
 */
class PartnerDemographicsSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-03 12:00:00');
        $this->seedFixture();
    }

    /** @return array<string, array<int, mixed>> строки по «Раздел|Показатель» */
    private function summary(): array
    {
        $report = $this->app->make(PartnerDemographicsSummaryReport::class);
        $out = [];
        foreach ($report->rows('2026-09-03', '2026-09-03', []) as $row) {
            $out[$row[0] . '|' . $row[1]] = $row;
        }

        return $out;
    }

    #[Test]
    public function gender_shares_are_counted(): void
    {
        $s = $this->summary();

        $this->assertSame(2, $s['Пол|Мужской'][2]);
        $this->assertSame(50.0, $s['Пол|Мужской'][3]);
        $this->assertSame(1, $s['Пол|Женский'][2]);
        $this->assertSame(25.0, $s['Пол|Женский'][3]);
        $this->assertSame(1, $s['Пол|Не определён'][2]);
        $this->assertSame(25.0, $s['Пол|Не определён'][3]);
    }

    /** Группы идут по возрастанию — так их и рисует гистограмма. */
    #[Test]
    public function age_buckets_keep_their_order_and_counts(): void
    {
        $report = $this->app->make(PartnerDemographicsSummaryReport::class);
        $ageRows = array_values(array_filter(
            $report->rows('2026-09-03', '2026-09-03', []),
            fn ($row) => $row[0] === 'Возраст',
        ));

        $this->assertSame(
            ['до 25', '25–34', '35–44', '45–54', '55–64', '65 и старше', 'Не указан'],
            array_column($ageRows, 1),
        );

        $byLabel = array_column($ageRows, null, 1);
        // 1988 → 38 лет, 1995 → 31 год, 1980 → 46 лет, у четвёртого даты нет.
        $this->assertSame(1, $byLabel['25–34'][2]);
        $this->assertSame(1, $byLabel['35–44'][2]);
        $this->assertSame(1, $byLabel['45–54'][2]);
        $this->assertSame(1, $byLabel['Не указан'][2]);
        $this->assertSame(0, $byLabel['65 и старше'][2]);
    }

    /** В группе видно, сколько там мужчин и женщин — для разбивки на графике. */
    #[Test]
    public function each_bucket_splits_by_gender(): void
    {
        $s = $this->summary();

        // 35–44 — это мужчина 1988 года.
        $this->assertSame(1, $s['Возраст|35–44'][4]);
        $this->assertSame(0, $s['Возраст|35–44'][5]);
        // 25–34 — женщина 1995 года.
        $this->assertSame(0, $s['Возраст|25–34'][4]);
        $this->assertSame(1, $s['Возраст|25–34'][5]);
    }

    #[Test]
    public function totals_carry_the_average_and_median_age(): void
    {
        $s = $this->summary();

        $this->assertSame(4, $s['Итого|Всего партнёров'][2]);
        $this->assertSame(100.0, $s['Итого|Всего партнёров'][3]);
        // Возрасты 38, 31, 46 — средний 38.3, медиана 38. Партнёр без даты
        // рождения в расчёт возраста не входит.
        $this->assertSame(38.3, $s['Итого|Средний возраст'][2]);
        $this->assertSame(38.0, $s['Итого|Медианный возраст'][2]);
    }

    /**
     * Строки о качестве данных обязательны: без них проценты выглядят
     * точнее, чем они есть.
     */
    #[Test]
    public function data_quality_rows_expose_the_guesswork(): void
    {
        $s = $this->summary();

        // Пол по отчеству определён у женщины и у партнёра без логина.
        $this->assertSame(2, $s['Качество данных|Пол определён по отчеству, а не указан в профиле'][2]);
        $this->assertSame(50.0, $s['Качество данных|Пол определён по отчеству, а не указан в профиле'][3]);
        $this->assertSame(1, $s['Качество данных|Дата рождения не заполнена'][2]);
    }

    /** Пустая сеть не должна ронять отчёт делением на ноль. */
    #[Test]
    public function an_empty_network_yields_a_single_zero_row(): void
    {
        DB::table('consultant')->update(['dateDeleted' => now()]);

        $rows = $this->app->make(PartnerDemographicsSummaryReport::class)
            ->rows('2026-09-03', '2026-09-03', []);

        $this->assertSame([['Итого', 'Всего партнёров', 0, 0, 0, 0]], $rows);
    }

    // ================================================================

    private function seedFixture(): void
    {
        // Мужчина, пол указан в профиле (легаси-написание), 38 лет.
        $this->webUser(1410801, 'Иванов', 'Иван', 'Петрович', ['gender' => 'Мужской', 'birthDate' => '1988-03-14 00:00:00']);
        // Женщина, пол пуст — определится по отчеству, 31 год.
        $this->webUser(1410802, 'Петрова', 'Мария', 'Сергеевна', ['gender' => null, 'birthDate' => '1995-06-01 00:00:00']);
        // Ни пола, ни отчества, ни даты рождения.
        $this->webUser(1410804, 'Ким', 'Саша', '', ['gender' => '', 'birthDate' => null]);

        DB::table('consultant')->insert([
            'id' => 1410001, 'webUser' => 1410801, 'personName' => 'Иванов Иван Петрович',
            'activity' => 1, 'dateCreated' => '2025-04-10 00:00:00', 'birthDate' => null,
        ]);
        DB::table('consultant')->insert([
            'id' => 1410002, 'webUser' => 1410802, 'personName' => 'Петрова Мария Сергеевна',
            'activity' => 1, 'dateCreated' => '2026-02-20 00:00:00', 'birthDate' => null,
        ]);
        // Без логина: пол по отчеству из ФИО, дата рождения из карточки, 46 лет.
        DB::table('consultant')->insert([
            'id' => 1410003, 'webUser' => null, 'personName' => 'Безлогинов Олег Николаевич',
            'activity' => 4, 'dateCreated' => '2026-03-01 00:00:00', 'birthDate' => '18.02.1980',
        ]);
        DB::table('consultant')->insert([
            'id' => 1410004, 'webUser' => 1410804, 'personName' => 'Ким Саша',
            'activity' => 4, 'dateCreated' => '2026-03-02 00:00:00', 'birthDate' => null,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function webUser(int $id, string $last, string $first, string $patronymic, array $extra): void
    {
        $u = new User();
        $u->id = $id;
        $u->email = "demo{$id}@test.local";
        $u->role = 'consultant';
        $u->lastName = $last;
        $u->firstName = $first;
        $u->patronymic = $patronymic;
        $u->password = bcrypt('secret123');
        foreach ($extra as $k => $v) {
            $u->{$k} = $v;
        }
        $u->save();
    }
}
