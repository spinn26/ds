<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Видимость тикетов чата: список, счётчик непрочитанных и прямое открытие.
 *
 * Сетка ПОД вынос правил в один сервис. Сейчас правила живут ТРЕМЯ копиями —
 * ChatController::index(), ChatController::unreadCount() и ChatTicketPolicy —
 * и обязаны совпадать: расхождение уже дважды давало баг «бейдж есть, а
 * тикета в списке нет» и «тикет в списке есть, а по ссылке 403».
 *
 * Поэтому почти каждый сценарий проверяется сразу по всем трём каналам —
 * см. assertVisibility(). Единственное УМЫШЛЕННОЕ расхождение: у партнёра
 * тикеты «Написать собственнику» видны в списке, но в счётчик не идут.
 */
class ChatVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 1900001;
    private const OTHER_PARTNER = 1900002;
    private const SUPPORT = 1900003;
    private const OTHER_SUPPORT = 1900004;
    private const LEAD = 1900005;
    private const ADMIN = 1900006;
    private const FINANCE = 1900007;

    private int $ticketSeq = 1900100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedUsers();
    }

    // ---------------- Партнёр ----------------

    /** Партнёр видит свои тикеты — как автор и как получатель. */
    #[Test]
    public function a_partner_sees_their_own_tickets(): void
    {
        $own = $this->ticket(['created_by' => self::PARTNER, 'department' => 'support']);
        $addressed = $this->ticket(['created_by' => self::SUPPORT, 'recipient_id' => self::PARTNER]);

        $this->assertVisibility(self::PARTNER, $own, true);
        $this->assertVisibility(self::PARTNER, $addressed, true);
    }

    /** Чужой тикет партнёру недоступен ни одним из трёх каналов. */
    #[Test]
    public function a_partner_does_not_see_someone_elses_ticket(): void
    {
        $foreign = $this->ticket(['created_by' => self::OTHER_PARTNER, 'department' => 'support']);

        $this->assertVisibility(self::PARTNER, $foreign, false);
    }

    /** Приглашённый в переписку видит тикет, хотя и не автор. */
    #[Test]
    public function an_invited_participant_sees_the_ticket(): void
    {
        $t = $this->ticket(['created_by' => self::OTHER_PARTNER, 'department' => 'support']);
        DB::table('chat_ticket_participants')->insert([
            'ticket_id' => $t, 'user_id' => self::PARTNER,
            'user_name' => 'Партнёров Тест', 'added_by' => self::SUPPORT,
            'added_at' => now(),
        ]);

        $this->assertVisibility(self::PARTNER, $t, true);
    }

    /**
     * ⚠ Единственное умышленное расхождение списка и счётчика: «Написать
     * собственнику» партнёр видит в списке, но в бейдж непрочитанных этот
     * канал не идёт — он исходящий.
     */
    #[Test]
    public function owner_channel_shows_in_the_list_but_not_in_the_badge(): void
    {
        $t = $this->ticket(['created_by' => self::PARTNER, 'department' => 'owner']);
        $this->unread($t, self::PARTNER);

        $this->assertTrue($this->inList(self::PARTNER, $t), 'в списке тикет есть');
        $this->assertSame(0, $this->badge(self::PARTNER), 'а в счётчик не идёт');
    }

    // ---------------- Staff и claim & hide ----------------

    /** Неразобранный тикет своей категории виден всему отделу. */
    #[Test]
    public function an_unclaimed_department_ticket_is_visible_to_the_desk(): void
    {
        $t = $this->ticket(['created_by' => self::PARTNER, 'department' => 'support']);

        $this->assertVisibility(self::SUPPORT, $t, true);
        $this->assertVisibility(self::OTHER_SUPPORT, $t, true);
    }

    /**
     * Взятый в работу тикет пропадает у остальных сотрудников отдела —
     * и из списка, и из счётчика, и по прямой ссылке.
     */
    #[Test]
    public function a_claimed_ticket_hides_from_the_rest_of_the_desk(): void
    {
        $t = $this->ticket([
            'created_by' => self::PARTNER, 'department' => 'support',
            'assigned_to' => self::SUPPORT,
        ]);

        $this->assertVisibility(self::SUPPORT, $t, true, 'у взявшего тикет остаётся');
        $this->assertVisibility(self::OTHER_SUPPORT, $t, false, 'у коллеги пропадает');
    }

    /**
     * Тикет чужой категории сотруднику не виден. ⚠ Категория «Начисления и
     * выплаты» называется accruals — finance это РОЛЬ, а не отдел тикета.
     */
    #[Test]
    public function a_foreign_category_is_not_visible_to_staff(): void
    {
        $t = $this->ticket(['created_by' => self::PARTNER, 'department' => 'accruals']);

        $this->assertVisibility(self::SUPPORT, $t, false);
        $this->assertVisibility(self::FINANCE, $t, true);
    }

    /** Руководитель отдела видит и взятые подчинёнными тикеты. */
    #[Test]
    public function the_department_lead_sees_claimed_tickets_too(): void
    {
        $t = $this->ticket([
            'created_by' => self::PARTNER, 'department' => 'support',
            'assigned_to' => self::SUPPORT,
        ]);

        $this->assertVisibility(self::LEAD, $t, true);
    }

    /** Админ видит все тикеты техподдержки, claim & hide на него не влияет. */
    #[Test]
    public function the_admin_sees_every_support_ticket(): void
    {
        $t = $this->ticket([
            'created_by' => self::PARTNER, 'department' => 'support',
            'assigned_to' => self::SUPPORT,
        ]);

        $this->assertVisibility(self::ADMIN, $t, true);
    }

    /** Legacy-категория technical приравнена к support для админа. */
    #[Test]
    public function the_legacy_technical_category_counts_as_support(): void
    {
        $t = $this->ticket([
            'created_by' => self::PARTNER, 'department' => 'technical',
            'assigned_to' => self::SUPPORT,
        ]);

        $this->assertTrue($this->inList(self::ADMIN, $t));
    }

    /**
     * 🐞 Роль с заглавной буквы разводит список и политику: список приводит
     * роли к нижнему регистру (User::getRolesArray), а ChatTicketPolicy
     * разбирает `role` вручную и регистр не трогает. Итог — тикет виден в
     * списке, но по прямой ссылке отдаёт 403.
     *
     * Тест фиксирует поведение как есть, чтобы вынос правил в сервис был
     * проверяемо равносильным; починка идёт отдельным коммитом, который эту
     * проверку и перевернёт.
     */
    #[Test]
    public function a_capitalised_role_splits_the_list_from_the_policy(): void
    {
        $this->user(1900008, 'Support', 'Заглавнов');
        $t = $this->ticket(['created_by' => self::PARTNER, 'department' => 'support']);

        $this->assertTrue($this->inList(1900008, $t), 'в списке тикет есть');
        $this->assertFalse($this->canOpen(1900008, $t), 'а по ссылке — отказ');
    }

    // ================================================================

    /**
     * Проверяет ВСЕ ТРИ канала сразу: список, счётчик и прямое открытие.
     * Именно расхождение между ними и было источником багов.
     */
    private function assertVisibility(int $userId, int $ticketId, bool $visible, string $why = ''): void
    {
        $suffix = $why !== '' ? " ({$why})" : '';
        $this->unread($ticketId, $userId);

        $this->assertSame($visible, $this->inList($userId, $ticketId), 'список' . $suffix);
        $this->assertSame($visible, $this->badge($userId) > 0, 'счётчик непрочитанных' . $suffix);
        $this->assertSame($visible, $this->canOpen($userId, $ticketId), 'прямое открытие' . $suffix);
    }

    private function inList(int $userId, int $ticketId): bool
    {
        $rows = $this->actingAs(User::find($userId), 'sanctum')
            ->getJson('/api/v1/chat/tickets')->assertOk()->json('data');

        return in_array($ticketId, array_map('intval', array_column($rows, 'id')), true);
    }

    private function badge(int $userId): int
    {
        return (int) $this->actingAs(User::find($userId), 'sanctum')
            ->getJson('/api/v1/chat/unread-count')->assertOk()->json('count');
    }

    private function canOpen(int $userId, int $ticketId): bool
    {
        return $this->actingAs(User::find($userId), 'sanctum')
            ->getJson('/api/v1/chat/tickets/' . $ticketId)->status() === 200;
    }

    /** Кладёт непрочитанное сообщение от постороннего — чтобы бейдж мог сработать. */
    private function unread(int $ticketId, int $forUserId): void
    {
        DB::table('chat_messages')->where('ticket_id', $ticketId)->delete();
        DB::table('chat_messages')->insert([
            'ticket_id' => $ticketId,
            'sender_id' => $forUserId === self::OTHER_PARTNER ? self::PARTNER : self::OTHER_PARTNER,
            'sender_name' => 'Отправитель',
            'content' => 'Непрочитанное',
            'is_agent' => false,
            'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function ticket(array $attrs): int
    {
        $id = $this->ticketSeq++;
        DB::table('chat_tickets')->insert(array_merge([
            'id' => $id,
            'subject' => 'Тикет ' . $id,
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'created_by' => self::PARTNER,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return $id;
    }

    private function seedUsers(): void
    {
        $this->user(self::PARTNER, 'consultant', 'Партнёров');
        $this->user(self::OTHER_PARTNER, 'consultant', 'Чужов');
        $this->user(self::SUPPORT, 'support', 'Саппортов');
        $this->user(self::OTHER_SUPPORT, 'support', 'Коллегин');
        $this->user(self::LEAD, 'support', 'Руководителев', ['chat_department_lead' => true]);
        $this->user(self::ADMIN, 'admin', 'Админов');
        $this->user(self::FINANCE, 'finance', 'Финансов');
    }

    /** @param array<string, mixed> $extra */
    private function user(int $id, string $role, string $last, array $extra = []): void
    {
        $u = new User();
        $u->id = $id;
        $u->email = 'chat' . $id . '@test.local';
        $u->role = $role;
        $u->lastName = $last;
        $u->firstName = 'Тест';
        $u->password = bcrypt('secret123');
        foreach ($extra as $k => $v) {
            $u->{$k} = $v;
        }
        $u->save();
    }
}
