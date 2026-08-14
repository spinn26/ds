<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Фильтры и поля списка партнёров (/admin/partners).
 *
 * Сетка ПОД разбор AdminDataController: метод partners() уезжает в сервис, и
 * каждый фильтр должен пережить переезд дословно. Особенно контакты — они
 * берутся то из WebUser, то из собственных колонок партнёра, и это правило
 * уже ломали.
 */
class PartnerListingFiltersTest extends TestCase
{
    use RefreshDatabase;

    /** С логином, контакты на WebUser. */
    private const WITH_LOGIN = 1200001;
    /** Без логина, контакты в собственных колонках (импортированные ФК). */
    private const NO_LOGIN = 1200002;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    #[Test]
    public function search_matches_person_name_case_insensitively(): void
    {
        $this->assertOnly('search=иванов', [self::WITH_LOGIN]);
        $this->assertOnly('search=ИВАНОВ', [self::WITH_LOGIN], 'ilike — регистр не важен');
    }

    #[Test]
    public function activity_and_active_filters(): void
    {
        $this->assertOnly('activity=1', [self::WITH_LOGIN]);
        $this->assertOnly('activity=4', [self::NO_LOGIN]);
        // ⚠ active сравнивается со СТРОКОЙ 'true', а не булевым приведением.
        $this->assertOnly('active=true', [self::WITH_LOGIN]);
    }

    #[Test]
    public function partner_id_and_inviter_name_filters(): void
    {
        $this->assertOnly('partner_id=' . self::NO_LOGIN, [self::NO_LOGIN]);
        $this->assertOnly('inviter_name=' . urlencode('Наставник'), [self::NO_LOGIN]);
    }

    /** Почта ищется и в WebUser, и в собственной колонке партнёра. */
    #[Test]
    public function email_filter_covers_both_sources(): void
    {
        $this->assertOnly('email=' . urlencode('login@test.local'), [self::WITH_LOGIN]);
        $this->assertOnly('email=' . urlencode('own@test.local'), [self::NO_LOGIN]);
    }

    /** Телефон ищется по ЦИФРАМ: форматирование в колонке роли не играет. */
    #[Test]
    public function phone_filter_ignores_formatting(): void
    {
        $this->assertOnly('phone=' . urlencode('+7 (911) 111-11-11'), [self::WITH_LOGIN]);
        $this->assertOnly('phone=9222222222', [self::NO_LOGIN], 'своя колонка отформатирована, ищем по цифрам');
    }

    /** Контакты: WebUser важнее собственных колонок, без логина — свои. */
    #[Test]
    public function contacts_prefer_web_user_then_own_columns(): void
    {
        $rows = collect($this->list('')->json('data'))->keyBy('id');

        $this->assertSame('login@test.local', $rows[self::WITH_LOGIN]['email']);
        $this->assertSame('own@test.local', $rows[self::NO_LOGIN]['email'], 'без логина — своя колонка');
        $this->assertTrue($rows[self::WITH_LOGIN]['platformAccess'], 'логин есть и не заблокирован');
        $this->assertFalse($rows[self::NO_LOGIN]['platformAccess'], 'без логина доступа нет');
    }

    /** Признак «партнёр является и клиентом» — по явной связи, не через person. */
    #[Test]
    public function is_client_flag_comes_from_explicit_link(): void
    {
        $rows = collect($this->list('')->json('data'))->keyBy('id');

        $this->assertTrue($rows[self::WITH_LOGIN]['isClient']);
        $this->assertFalse($rows[self::NO_LOGIN]['isClient']);
    }

    /**
     * «Дата смены статуса»: активному — +год от активации, зарегистрированному —
     * его activationDeadline.
     */
    #[Test]
    public function status_change_date_depends_on_activity(): void
    {
        $rows = collect($this->list('')->json('data'))->keyBy('id');

        $this->assertSame('2027-03-01', $rows[self::WITH_LOGIN]['statusChangeDate']);
        $this->assertSame('2026-09-15', $rows[self::NO_LOGIN]['statusChangeDate']);
    }

    /**
     * Сортировка идёт в БД, порядок задаёт коллация Postgres.
     * ⚠ Параметры именно snake_case — sort_by/sort_dir (см. AppliesSorting);
     * camelCase из фронта сюда приходит уже преобразованным.
     */
    #[Test]
    public function sorting_by_person_name_flips_with_direction(): void
    {
        $asc = array_column($this->list('sort_by=personName&sort_dir=asc')->json('data'), 'id');
        $desc = array_column($this->list('sort_by=personName&sort_dir=desc')->json('data'), 'id');

        $this->assertSame(array_reverse($asc), $desc, 'направление разворачивает выдачу');
        $this->assertCount(2, $asc);
    }

    // ================================================================

    /** @param list<int> $expected */
    private function assertOnly(string $query, array $expected, string $why = ''): void
    {
        $ids = array_column($this->list($query)->json('data'), 'id');
        sort($ids);
        sort($expected);

        $this->assertSame($expected, $ids, $why ?: ('фильтр: ' . $query));
    }

    private function list(string $query)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/partners' . ($query ? '?' . $query : ''))
            ->assertOk();
    }

    /** Имя `seed` занято базовым TestCase. */
    private function seedFixture(): void
    {
        $this->admin = new User();
        $this->admin->id = 1200900;
        $this->admin->email = 'listing@test.local';
        $this->admin->firstName = 'Список';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        $webUser = new User();
        $webUser->id = 1200800;
        $webUser->email = 'login@test.local';
        $webUser->firstName = 'Логин';
        $webUser->lastName = 'Партнёра';
        $webUser->phone = '+7 (911) 111-11-11';
        $webUser->role = 'consultant';
        $webUser->password = bcrypt('secret123');
        $webUser->save();

        // ⚠ Двумя вставками, а не одной пачкой: у строк разный набор колонок,
        // и Postgres на batch-insert отвечает «списки VALUES должны иметь
        // одинаковую длину».
        DB::table('consultant')->insert([
            'id' => self::WITH_LOGIN,
            'webUser' => $webUser->id,
            'personName' => 'Иванов Иван',
            'activity' => 1,
            'active' => true,
            'dateActivity' => '2026-03-01 00:00:00',
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
        DB::table('consultant')->insert([
            'id' => self::NO_LOGIN,
            'webUser' => null,
            'personName' => 'Петров Пётр',
            'inviterName' => 'Наставник Первый',
            'activity' => 4,
            'active' => false,
            'email' => 'own@test.local',
            'phone' => '+7 (922) 222-22-22',
            'activationDeadline' => '2026-09-15 00:00:00',
            'dateCreated' => '2026-05-01 00:00:00',
        ]);

        // Явная связь «этот партнёр — ещё и клиент».
        DB::table('client')->insert([
            'id' => 1200010,
            'consultant' => self::WITH_LOGIN,
            'partner_consultant_id' => self::WITH_LOGIN,
            'personName' => 'Иванов Иван',
        ]);
    }
}
