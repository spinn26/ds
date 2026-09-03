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
    /** Soft-deleted WebUser — его почта занятой НЕ считается. */
    private const DELETED_TWIN = 1800102;
    /** Живой клиентский логин с почтой партнёра, отличающейся регистром. */
    private const LIVE_TWIN = 1800103;

    private const CONTRACT = 1800200;
    private const CLIENT = 1800300;
    /** Подтверждённые реквизиты партнёра — их снимает смена ФИО. */
    private const REQUISITE = 1800400;
    private const BANK_REQUISITE = 1800500;

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

    // ---------------- Сброс верификации реквизитов ----------------

    /**
     * Per spec ✅Верификация реквизитов Партнёра.md, Контур 3: партнёром ДС
     * может быть только ИП, оформленное на то же имя, что в профиле. Значит
     * смена ФИО снимает «Верифицировано» — и с ИП, и с банковской строки, —
     * закрывает платёжный гейт и открывает партнёру повторный ввод.
     */
    #[Test]
    public function renaming_a_partner_drops_the_requisites_verification(): void
    {
        $this->save($this->admin, self::PARTNER, ['lastName' => 'Переименов'])->assertOk();

        $req = DB::table('requisites')->where('id', self::REQUISITE)->first();
        $bank = DB::table('bankrequisites')->where('id', self::BANK_REQUISITE)->first();

        $this->assertFalse((bool) $req->verified, 'верификация ИП обязана слететь');
        $this->assertSame(2, (int) $req->status, 'реквизиты возвращены партнёру');
        $this->assertNotEmpty($req->rejection_reason, 'партнёр должен видеть причину');
        $this->assertFalse((bool) $bank->verified, 'банковская строка тоже на перепроверку');
        // Гейт продуктов/выплат закрыт: 3 = подтверждено, всё меньшее — нет.
        $this->assertSame(2, (int) DB::table('consultant')
            ->where('id', self::PARTNER)->value('statusRequisites'));
    }

    /** Партнёру уходит уведомление — иначе он не узнает, что надо отправить заново. */
    #[Test]
    public function the_partner_is_notified_about_the_reverification(): void
    {
        $this->save($this->admin, self::PARTNER, ['firstName' => 'Переимён'])->assertOk();

        $this->assertSame(1, DB::table('notifications')
            ->where('user_id', self::PARTNER_WEBUSER)
            ->where('type', 'requisites')->count());
    }

    /** Сброс попадает и в историю карточки (diff), и в аудит отдельной записью. */
    #[Test]
    public function the_reset_is_written_to_the_history(): void
    {
        $this->save($this->admin, self::PARTNER, ['lastName' => 'Переименов'])->assertOk();

        $update = DB::table('audit_log')->where('action', 'partner_update')
            ->where('entity_id', (string) self::PARTNER)->first();
        $diff = json_decode($update->payload, true)['diff'];
        $this->assertFalse($diff['requisitesVerified']['to']);

        $reset = DB::table('audit_log')->where('action', 'requisites_reverification')
            ->where('entity', 'requisites')->first();
        $this->assertNotNull($reset, 'сброс обязан попасть в аудит');
        $this->assertSame((string) self::REQUISITE, $reset->entity_id);
        $this->assertSame(self::PARTNER, json_decode($reset->payload, true)['consultant']);
    }

    /** Правка без смены ФИО верификацию не трогает. */
    #[Test]
    public function a_save_without_a_name_change_keeps_the_verification(): void
    {
        $this->save($this->admin, self::PARTNER, ['phone' => '+79991112233'])->assertOk();

        $this->assertTrue((bool) DB::table('requisites')
            ->where('id', self::REQUISITE)->value('verified'));
        $this->assertSame(3, (int) DB::table('consultant')
            ->where('id', self::PARTNER)->value('statusRequisites'));
        $this->assertSame(0, DB::table('audit_log')
            ->where('action', 'requisites_reverification')->count());
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

    /**
     * Основание правки обязательно — через полгода на вопрос «почему у
     * партнёра другое ФИО» отвечает только этот комментарий. Гард дублирует
     * UI: прямой PUT без основания тоже не проходит.
     */
    #[Test]
    public function a_real_change_without_a_reason_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/partners/' . self::PARTNER, ['phone' => '+79991112233'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('comment');

        // Отказ откатывает всё: телефон остался прежним, журнал пуст.
        $this->assertSame('+79990000000', DB::table('WebUser')
            ->where('id', self::PARTNER_WEBUSER)->value('phone'));
        $this->assertSame(0, DB::table('audit_log')->where('entity', 'consultant')
            ->where('entity_id', (string) self::PARTNER)->count());
    }

    /** Правка «ничего не поменялось» проходит и без основания. */
    #[Test]
    public function a_no_op_save_needs_no_reason(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/partners/' . self::PARTNER, ['phone' => '+79990000000'])
            ->assertOk();
    }

    /** Основание ложится в ту же запись журнала, что и diff полей. */
    #[Test]
    public function the_reason_is_stored_with_the_diff(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson(
            '/api/v1/admin/partners/' . self::PARTNER,
            ['phone' => '+79991112233', 'comment' => 'Заявление партнёра от 03.09.2026'],
        )->assertOk();

        $row = DB::table('audit_log')->where('action', 'partner_update')
            ->where('entity_id', (string) self::PARTNER)->first();
        $payload = json_decode($row->payload, true);

        $this->assertSame('Заявление партнёра от 03.09.2026', $payload['comment']);
        $this->assertSame('+79991112233', $payload['diff']['phone']['to']);
    }

    /** Отписка в один символ не проходит — основание должно быть по существу. */
    #[Test]
    public function a_too_short_reason_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson(
            '/api/v1/admin/partners/' . self::PARTNER,
            ['phone' => '+79991112233', 'comment' => 'x'],
        )->assertStatus(422)->assertJsonValidationErrors('comment');
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

    // ---------------- Уникальность почты ----------------

    /**
     * Почту удалённой записи можно занять: soft-deleted строки в проверке не
     * участвуют. Прежнее `unique:WebUser,email,{id},id` о них спотыкалось.
     */
    #[Test]
    public function email_of_a_soft_deleted_login_is_free_to_take(): void
    {
        $this->save($this->admin, self::PARTNER, ['email' => 'freed@test.local'])->assertOk();

        $this->assertSame('freed@test.local',
            DB::table('WebUser')->where('id', self::PARTNER_WEBUSER)->value('email'));
    }

    /**
     * Карточка сохраняется, даже если почта партнёра конфликтует с чужим ЖИВЫМ
     * логином: форма шлёт email при любой правке, и проверка на неизменённом
     * значении не запускается вовсе. Иначе правка отчества или наставника была
     * бы навсегда заперта («Этот email уже зарегистрирован» — ФК 588 и ещё 10).
     */
    #[Test]
    public function unchanged_email_saves_despite_a_conflicting_live_twin(): void
    {
        $this->save($this->admin, self::PARTNER, [
            'email' => 'partner@test.local', 'patronymic' => 'Обновлёнович',
        ])->assertOk();

        $this->assertSame('Обновлёнович',
            DB::table('WebUser')->where('id', self::PARTNER_WEBUSER)->value('patronymic'));
    }

    /**
     * Почта ЖИВОГО чужого логина занята, причём независимо от регистра: вход по
     * почте регистрозависим, и «Staff@Test.Local» рядом с «staff@test.local» —
     * это логин, которым не войти.
     */
    #[Test]
    public function email_of_another_live_login_is_rejected_case_insensitively(): void
    {
        $this->save($this->admin, self::PARTNER, ['email' => 'Staff@Test.Local'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame('partner@test.local',
            DB::table('WebUser')->where('id', self::PARTNER_WEBUSER)->value('email'));
    }

    /**
     * Правка карточки требует основания — оно уходит в Историю изменений.
     * Здесь подставляем дежурное, чтобы тесты про остальную механику не
     * повторяли его в каждом вызове; проверки самого правила — отдельно.
     *
     * @param array<string, mixed> $payload
     */
    private function save(User $as, int $id, array $payload)
    {
        return $this->actingAs($as, 'sanctum')->putJson(
            '/api/v1/admin/partners/' . $id,
            $payload + ['comment' => 'Тестовое основание правки'],
        );
    }

    private function seedFixture(): void
    {
        $this->admin = $this->webUser(1800900, 'admin', 'admin@test.local', 'Админов', 'Админ');
        $this->staff = $this->webUser(1800901, 'backoffice', 'staff@test.local', 'Стаффов', 'Стафф');

        $this->webUser(self::PARTNER_WEBUSER, 'consultant', 'partner@test.local',
            'Партнёров', 'Партнёр', 'Партнёрович', [
                'phone' => '+79990000000', 'nicTG' => '@partner', 'birthDate' => '1990-01-01',
            ]);

        // Удалённая строка — наследство Directual-экспорта. Её почта занятой
        // не считается (ФК 588 / WebUser 319-388).
        $this->webUser(self::DELETED_TWIN, 'consultant', 'freed@test.local',
            'Удалённый', 'Дубль', '', ['dateDeleted' => '2025-01-29 19:00:00']);

        // Живой клиентский логин с почтой партнёра в другом регистре
        // (реальные пары WebUser 511/687, 668/726).
        $this->webUser(self::LIVE_TWIN, 'client', 'PARTNER@TEST.LOCAL',
            'Клиентов', 'Клиент');

        // Справочник статусов реквизитов: на него смотрят внешние ключи
        // consultant.statusRequisites и requisites.status, а в схему-фикстуру
        // он не попал (как в SetupRequisitesTest).
        DB::table('status_requisites')->insert([
            ['id' => 1, 'level' => 1, 'name' => 'backoffice'],
            ['id' => 2, 'level' => 2, 'name' => 'consultant'],
            ['id' => 3, 'level' => 3, 'name' => 'verified'],
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
                // 3 = реквизиты подтверждены, гейт продуктов/выплат открыт.
                'statusRequisites' => 3,
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
        // Подтверждённая пара «ИП + банк»: ровно то состояние, которое обязана
        // снять смена ФИО (Контур 3 спеки по верификации реквизитов).
        DB::table('requisites')->insert([
            'id' => self::REQUISITE, 'consultant' => self::PARTNER,
            'webUser' => self::PARTNER_WEBUSER,
            'individualEntrepreneur' => 'ИП Партнёров Партнёр Партнёрович',
            'inn' => '770000000012', 'ogrn' => '312770000000123',
            'address' => 'г. Москва, ул. Тестовая, 1',
            'email' => 'partner@test.local', 'phone' => '+79990000000',
            'verified' => true, 'status' => 3, 'dateChange' => '2026-02-01 00:00:00',
        ]);
        DB::table('bankrequisites')->insert([
            'id' => self::BANK_REQUISITE, 'requisites' => self::REQUISITE,
            'bankName' => 'Тестбанк', 'bankBik' => '044525000',
            'accountNumber' => '40802810000000000001',
            'correspondentAccount' => '30101810000000000001',
            'beneficiaryName' => 'ИП Партнёров Партнёр Партнёрович',
            'verified' => true, 'dateChange' => '2026-02-01 00:00:00',
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
