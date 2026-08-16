<?php

namespace Tests\Feature\Characterization;

use App\Jobs\RecomputeTransferChainJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Редактирование карточки партнёра (PUT /admin/partners/{id}).
 *
 * Сетка ПОД вынос метода в сервис. Это путь ЗАПИСИ, причём с историей
 * инцидентов, поэтому сетка плотнее обычной: здесь и права (роль/пароль/
 * блокировка — только админу), и две принципиально разные ветки хранения
 * контактов (партнёр с логином пишет в WebUser, без логина — в собственные
 * колонки), и каскад ФИО по денорм-копиям, и перестановка наставника, которая
 * обязана попасть в Историю перестановок вместе с пересчётом цепочки.
 *
 * Отдельно закреплено то, из-за чего уже ломались данные: присланы только
 * те поля, что реально пришли в запросе — пустая форма ничего не затирает.
 */
class UpdatePartnerTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 1800001;
    private const NO_LOGIN = 1800002;
    private const INVITER_OLD = 1800003;
    private const INVITER_NEW = 1800004;
    private const INVITEE = 1800005;

    private const PARTNER_WEBUSER = 1800100;

    private const CONTRACT = 1800200;
    private const CLIENT = 1800300;

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->seedFixture();
    }

    // ---------------- Права ----------------

    /**
     * ⚠ Роль, пароль и блокировка — только админу. У остального staff поля
     * молча отбрасываются, а запрос всё равно проходит: иначе любой staff
     * выдал бы себе роль admin.
     */
    #[Test]
    public function critical_fields_are_stripped_for_non_admins(): void
    {
        $before = DB::table('WebUser')->where('id', self::PARTNER_WEBUSER)->first();

        $this->save($this->staff, self::PARTNER, [
            'role' => 'admin', 'isBlocked' => true, 'newPassword' => 'НовыйPass123',
            'phone' => '+79990000111',
        ])->assertOk();

        $after = DB::table('WebUser')->where('id', self::PARTNER_WEBUSER)->first();

        $this->assertSame($before->role, $after->role);
        $this->assertSame($before->password, $after->password);
        $this->assertFalse((bool) $after->isBlocked);
        // Обычное поле из того же запроса при этом сохранилось.
        $this->assertSame('+79990000111', $after->phone);
    }

    /** Админу те же поля доступны. */
    #[Test]
    public function admin_may_change_role_and_password(): void
    {
        $this->save($this->admin, self::PARTNER, [
            'role' => 'support', 'newPassword' => 'НовыйPass123',
        ])->assertOk();

        $after = DB::table('WebUser')->where('id', self::PARTNER_WEBUSER)->first();

        $this->assertSame('support', $after->role);
        $this->assertTrue(Hash::check('НовыйPass123', $after->password));
    }

    /** Блокировка отзывает токены — иначе партнёр работает до их истечения. */
    #[Test]
    public function blocking_revokes_the_tokens(): void
    {
        User::find(self::PARTNER_WEBUSER)->createToken('test');
        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_id', self::PARTNER_WEBUSER)->count());

        $this->save($this->admin, self::PARTNER, ['isBlocked' => true])->assertOk();

        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_id', self::PARTNER_WEBUSER)->count());
    }

    // ---------------- Валидация ----------------

    /** ФИО — только кириллица, с русским текстом ошибки. */
    #[Test]
    public function names_are_cyrillic_only(): void
    {
        $this->save($this->admin, self::PARTNER, ['firstName' => 'John'])
            ->assertStatus(422)
            ->assertJsonPath('errors.firstName.0', 'Имя — только русские буквы');
    }

    /** Легаси-пол из Directual канонизируется до валидации. */
    #[Test]
    public function legacy_gender_is_normalized(): void
    {
        $this->save($this->admin, self::PARTNER, ['gender' => 'Мужской'])->assertOk();

        $this->assertSame('male', DB::table('WebUser')
            ->where('id', self::PARTNER_WEBUSER)->value('gender'));
    }

    /** Пустая форма ничего не затирает: обновляются только присланные поля. */
    #[Test]
    public function an_empty_payload_wipes_nothing(): void
    {
        $before = DB::table('WebUser')->where('id', self::PARTNER_WEBUSER)->first();

        $this->save($this->admin, self::PARTNER, [])->assertOk();

        $after = DB::table('WebUser')->where('id', self::PARTNER_WEBUSER)->first();

        $this->assertSame($before->phone, $after->phone);
        $this->assertSame($before->nicTG, $after->nicTG);
        $this->assertSame($before->birthDate, $after->birthDate);
    }

    // ---------------- Партнёр без логина ----------------

    /**
     * У партнёра без логина WebUser'а нет, и контакты ведутся в собственных
     * колонках consultant. Раньше этой ветки не было, и правка карточки такого
     * партнёра молча не сохранялась.
     */
    #[Test]
    public function a_partner_without_a_login_keeps_contacts_on_the_card(): void
    {
        $this->save($this->admin, self::NO_LOGIN, [
            'email' => 'nologin@test.local', 'phone' => '+79995550000',
        ])->assertOk();

        $row = DB::table('consultant')->where('id', self::NO_LOGIN)->first();

        $this->assertSame('nologin@test.local', $row->email);
        $this->assertSame('+79995550000', $row->phone);
    }

    /** ФИО у него собирается из частей поверх личного personName. */
    #[Test]
    public function a_partner_without_a_login_rebuilds_the_full_name(): void
    {
        $this->save($this->admin, self::NO_LOGIN, ['lastName' => 'Новофамильев'])->assertOk();

        $this->assertSame('Новофамильев Безлогинович Отчествович',
            DB::table('consultant')->where('id', self::NO_LOGIN)->value('personName'));
    }

    // ---------------- Каскад ФИО ----------------

    /**
     * Новое ФИО расходится по видимым денорм-копиям: пригласитель у
     * приглашённых, консультант в контрактах и клиентах.
     */
    #[Test]
    public function the_new_name_propagates_to_denormalized_copies(): void
    {
        $this->save($this->admin, self::PARTNER, ['lastName' => 'Переименов'])->assertOk();

        $expected = 'Переименов Партнёр Партнёрович';

        $this->assertSame($expected, DB::table('consultant')->where('id', self::PARTNER)->value('personName'));
        $this->assertSame($expected, DB::table('consultant')->where('id', self::INVITEE)->value('inviterName'));
        $this->assertSame($expected, DB::table('contract')->where('id', self::CONTRACT)->value('consultantName'));
        $this->assertSame($expected, DB::table('client')->where('id', self::CLIENT)->value('consultantName'));
    }

    // ---------------- Смена наставника ----------------

    /**
     * Смена наставника через форму = перестановка: пишем в Историю
     * перестановок и запускаем пересчёт цепочки. Иначе перевод «теряется»
     * (инцидент Салькова, август 2026).
     */
    #[Test]
    public function changing_the_inviter_is_logged_and_recomputed(): void
    {
        $this->save($this->admin, self::PARTNER, ['inviter' => self::INVITER_NEW])->assertOk();

        $log = DB::table('changeConsultantInviterLog')->where('consultant', self::PARTNER)->first();

        $this->assertNotNull($log, 'перестановка обязана попасть в историю');
        $this->assertSame(self::INVITER_OLD, $log->inviterOld);
        $this->assertSame(self::INVITER_NEW, $log->inviterNew);
        $this->assertSame('Наставник Новый', $log->inviterNewName);
        $this->assertSame('Форма партнёра', $log->triggeredBy);

        // Денорм-имя наставника держится в синхроне с FK.
        $this->assertSame('Наставник Новый',
            DB::table('consultant')->where('id', self::PARTNER)->value('inviterName'));

        Bus::assertDispatched(RecomputeTransferChainJob::class);
    }

    /** Тот же наставник — ни записи в историю, ни пересчёта. */
    #[Test]
    public function keeping_the_same_inviter_changes_nothing(): void
    {
        $this->save($this->admin, self::PARTNER, ['inviter' => self::INVITER_OLD])->assertOk();

        $this->assertSame(0, DB::table('changeConsultantInviterLog')
            ->where('consultant', self::PARTNER)->count());
        Bus::assertNotDispatched(RecomputeTransferChainJob::class);
    }

    // ---------------- Журнал ----------------

    /** В audit_log идёт per-field diff — и только когда что-то поменялось. */
    #[Test]
    public function the_audit_entry_carries_a_per_field_diff(): void
    {
        $this->save($this->admin, self::PARTNER, ['phone' => '+79991112233'])->assertOk();

        $row = DB::table('audit_log')->where('entity', 'consultant')
            ->where('entity_id', (string) self::PARTNER)->first();
        $diff = json_decode($row->payload, true)['diff'];

        // Порядок ключей в сохранённом payload не гарантирован — сверяем по значениям.
        $this->assertSame('+79990000000', $diff['phone']['from']);
        $this->assertSame('+79991112233', $diff['phone']['to']);
    }

    /** «Нажал Сохранить», ничего не поменяв — записи в журнале нет. */
    #[Test]
    public function a_no_op_save_writes_no_audit_entry(): void
    {
        $this->save($this->admin, self::PARTNER, ['phone' => '+79990000000'])->assertOk();

        $this->assertSame(0, DB::table('audit_log')->where('entity', 'consultant')
            ->where('entity_id', (string) self::PARTNER)->count());
    }

    /** Пароль в журнале маскируется. */
    #[Test]
    public function the_password_is_masked_in_the_journal(): void
    {
        $this->save($this->admin, self::PARTNER, ['newPassword' => 'НовыйPass123'])->assertOk();

        $row = DB::table('audit_log')->where('entity', 'consultant')
            ->where('entity_id', (string) self::PARTNER)->first();
        $diff = json_decode($row->payload, true)['diff'];

        $this->assertSame('***', $diff['password']['from']);
        $this->assertSame('***', $diff['password']['to']);
    }

    // ================================================================

    /** @param array<string, mixed> $payload */
    private function save(User $as, int $id, array $payload)
    {
        return $this->actingAs($as, 'sanctum')->putJson('/api/v1/admin/partners/' . $id, $payload);
    }

    private function seedFixture(): void
    {
        $this->admin = $this->webUser(1800900, 'admin', 'admin@test.local', 'Админов', 'Админ');
        $this->staff = $this->webUser(1800901, 'backoffice', 'staff@test.local', 'Стаффов', 'Стафф');

        $this->webUser(self::PARTNER_WEBUSER, 'consultant', 'partner@test.local',
            'Партнёров', 'Партнёр', 'Партнёрович', [
                'phone' => '+79990000000', 'nicTG' => '@partner', 'birthDate' => '1990-01-01',
            ]);

        DB::table('consultant')->insert([
            [
                'id' => self::INVITER_OLD, 'personName' => 'Наставник Старый',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
            ],
            [
                'id' => self::INVITER_NEW, 'personName' => 'Наставник Новый',
                'activity' => 1, 'dateCreated' => '2026-01-01 00:00:00',
            ],
        ]);
        DB::table('consultant')->insert([
            [
                'id' => self::PARTNER, 'personName' => 'Партнёров Партнёр Партнёрович',
                'webUser' => self::PARTNER_WEBUSER, 'activity' => 1,
                'inviter' => self::INVITER_OLD, 'inviterName' => 'Наставник Старый',
                'dateCreated' => '2026-01-01 00:00:00',
            ],
            [
                'id' => self::NO_LOGIN, 'personName' => 'Безлогинов Безлогинович Отчествович',
                'webUser' => null, 'activity' => 1,
                'inviter' => null, 'inviterName' => null,
                'dateCreated' => '2026-01-01 00:00:00',
            ],
            [
                'id' => self::INVITEE, 'personName' => 'Приглашённый Партнёр',
                'webUser' => null, 'activity' => 1,
                'inviter' => self::PARTNER, 'inviterName' => 'Партнёров Партнёр Партнёрович',
                'dateCreated' => '2026-01-01 00:00:00',
            ],
        ]);

        DB::table('client')->insert([
            'id' => self::CLIENT, 'consultant' => self::PARTNER,
            'consultantName' => 'Партнёров Партнёр Партнёрович', 'personName' => 'Клиент Клиентов',
        ]);
        DB::table('contract')->insert([
            'id' => self::CONTRACT, 'consultant' => self::PARTNER,
            'consultantName' => 'Партнёров Партнёр Партнёрович',
            'client' => self::CLIENT, 'number' => 'CT-8001',
            'status' => 1, 'ammount' => 100_000, 'createDate' => '2026-01-01 00:00:00',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function webUser(int $id, string $role, string $email, string $last, string $first,
        string $patronymic = '', array $extra = []): User
    {
        $u = new User();
        $u->id = $id;
        $u->email = $email;
        $u->role = $role;
        $u->lastName = $last;
        $u->firstName = $first;
        $u->patronymic = $patronymic;
        $u->password = bcrypt('secret123');
        foreach ($extra as $k => $v) {
            $u->{$k} = $v;
        }
        $u->save();

        return $u;
    }
}
