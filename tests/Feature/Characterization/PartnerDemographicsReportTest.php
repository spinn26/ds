<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use App\Services\Reports\PartnerDemographicsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Выгрузка «Демография сети»: пол и возраст по каждому партнёру.
 *
 * Главное, что закрепляется: пол из профиля и пол, вычисленный по отчеству,
 * попадают в разные значения колонки «Источник пола». Смешать их нельзя —
 * иначе по выгрузке не отличить факт от догадки.
 */
class PartnerDemographicsReportTest extends TestCase
{
    use RefreshDatabase;

    /** Пол заполнен в профиле, по-русски (наследство Directual). */
    private const STORED = 1400001;
    /** Пол пуст — определяется по отчеству из WebUser. */
    private const BY_PATRONYMIC = 1400002;
    /** Логина нет вовсе: отчество берётся из денормализованного ФИО. */
    private const NO_LOGIN = 1400003;
    /** Ни пола, ни распознаваемого отчества, ни даты рождения. */
    private const UNKNOWN = 1400004;

    protected function setUp(): void
    {
        parent::setUp();
        // Возраст считается «на сегодня», поэтому дату фиксируем: иначе
        // ожидаемые группы поедут через несколько лет сами собой.
        Carbon::setTestNow('2026-09-03 12:00:00');
        $this->seedFixture();
    }

    /** @return array<string, array<int, mixed>> строки по ФИО партнёра */
    private function rows(string $from = '2020-01-01', string $to = '2030-12-31'): array
    {
        $report = $this->app->make(PartnerDemographicsReport::class);
        $out = [];
        foreach ($report->rows($from, $to, []) as $row) {
            $out[$row[0]] = $row;
        }

        return $out;
    }

    #[Test]
    public function stored_gender_is_normalized_and_marked_as_profile_data(): void
    {
        $row = $this->rows()['Иванов Иван Петрович'];

        $this->assertSame('Мужской', $row[2]);
        $this->assertSame('Профиль', $row[3], 'значение из профиля нельзя выдавать за вычисленное');
    }

    #[Test]
    public function empty_gender_is_resolved_from_the_patronymic(): void
    {
        $row = $this->rows()['Петрова Мария Сергеевна'];

        $this->assertSame('Женский', $row[2]);
        $this->assertSame('По отчеству', $row[3]);
    }

    /** У партнёра без логина отчество есть только внутри personName. */
    #[Test]
    public function a_partner_without_a_login_still_gets_a_gender(): void
    {
        $row = $this->rows()['Безлогинов Олег Николаевич'];

        $this->assertSame('Мужской', $row[2]);
        $this->assertSame('По отчеству', $row[3]);
    }

    /** Нечего распознавать — «Не определён», без догадок по имени. */
    #[Test]
    public function unresolvable_rows_stay_honest(): void
    {
        $row = $this->rows()['Ким Саша'];

        $this->assertSame('Не определён', $row[2]);
        $this->assertSame('Нет данных', $row[3]);
        $this->assertSame('', $row[5], 'возраста без даты рождения быть не должно');
        $this->assertSame('Не указан', $row[6]);
    }

    /** Возраст и группа считаются, дата берётся из WebUser. */
    #[Test]
    public function age_and_bucket_are_filled(): void
    {
        $row = $this->rows()['Иванов Иван Петрович'];

        $this->assertSame('1988-03-14', $row[4]);
        $this->assertSame(38, $row[5], 'полных лет на 03.09.2026');
        $this->assertSame('35–44', $row[6]);
    }

    /** У партнёра без логина дата рождения лежит в его собственной колонке. */
    #[Test]
    public function birth_date_falls_back_to_the_partner_card(): void
    {
        $row = $this->rows()['Безлогинов Олег Николаевич'];

        // Формат «d.m.Y» из карточки приводится к единому Y-m-d — иначе
        // в колонке смешиваются два формата и сводная не строится.
        $this->assertSame('1980-02-18', $row[4]);
        $this->assertSame(46, $row[5]);
        $this->assertSame('45–54', $row[6]);
    }

    /** Период фильтрует дату регистрации партнёра. */
    #[Test]
    public function the_period_filters_by_registration_date(): void
    {
        $names = array_keys($this->rows('2026-01-01', '2026-12-31'));

        $this->assertContains('Петрова Мария Сергеевна', $names);
        $this->assertNotContains('Иванов Иван Петрович', $names, 'зарегистрирован в 2025-м');
    }

    // ================================================================

    private function seedFixture(): void
    {
        $this->webUser(1400801, 'Иванов', 'Иван', 'Петрович', ['gender' => 'Мужской', 'birthDate' => '1988-03-14 00:00:00']);
        $this->webUser(1400802, 'Петрова', 'Мария', 'Сергеевна', ['gender' => null, 'birthDate' => '1995-06-01 00:00:00']);
        $this->webUser(1400804, 'Ким', 'Саша', '', ['gender' => '', 'birthDate' => null]);

        DB::table('consultant')->insert([
            'id' => self::STORED, 'webUser' => 1400801,
            'personName' => 'Иванов Иван Петрович', 'activity' => 1,
            'dateCreated' => '2025-04-10 00:00:00', 'birthDate' => null,
        ]);
        DB::table('consultant')->insert([
            'id' => self::BY_PATRONYMIC, 'webUser' => 1400802,
            'personName' => 'Петрова Мария Сергеевна', 'activity' => 1,
            'dateCreated' => '2026-02-20 00:00:00', 'birthDate' => null,
        ]);
        DB::table('consultant')->insert([
            'id' => self::NO_LOGIN, 'webUser' => null,
            'personName' => 'Безлогинов Олег Николаевич', 'activity' => 4,
            'dateCreated' => '2026-03-01 00:00:00',
            // consultant.birthDate — varchar, и в нём встречается «d.m.Y».
            'birthDate' => '18.02.1980',
        ]);
        DB::table('consultant')->insert([
            'id' => self::UNKNOWN, 'webUser' => 1400804,
            'personName' => 'Ким Саша', 'activity' => 4,
            'dateCreated' => '2026-03-02 00:00:00', 'birthDate' => null,
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
