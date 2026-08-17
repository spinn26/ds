<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Отправка сообщения в тикет (POST /chat/tickets/{id}/messages).
 *
 * Сетка ПОД вынос. Здесь живёт механизм «взял в работу»: первое сообщение
 * сотрудника назначает тикет на него, и ровно этим тикет исчезает из списков
 * остальных сотрудников отдела (claim & hide). Всё, что этот механизм
 * ослабляет, ломает разбор очереди: либо тикет разбирают двое, либо он
 * пропадает у всех.
 *
 * Сообщение партнёра назначение НЕ трогает — иначе тикет «самоназначался» бы
 * на автора.
 */
class ChatSendMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2700001;
    private const SUPPORT = 2700002;
    private const OTHER_SUPPORT = 2700003;

    private int $ticketSeq = 2700100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedUsers();
    }

    // ---------------- Взятие в работу ----------------

    /** Первое сообщение сотрудника назначает тикет на него. */
    #[Test]
    public function the_first_staff_message_claims_the_ticket(): void
    {
        $t = $this->ticket(['status' => 'new', 'assigned_to' => null]);

        $this->send(self::SUPPORT, $t, 'Беру в работу')->assertOk();

        $row = DB::table('chat_tickets')->where('id', $t)->first();

        $this->assertSame(self::SUPPORT, (int) $row->assigned_to);
        $this->assertSame('Саппортов Тест', $row->assigned_name);
        $this->assertSame('open', $row->status, 'новый тикет переходит в работу');
    }

    /**
     * ⚠ Взятый тикет коллеге не просто «не перехватывается» — он ему вообще
     * недоступен: claim & hide убирает тикет из его списка, и попытка
     * ответить отдаётся отказом. Это и есть защита от разбора вдвоём.
     */
    #[Test]
    public function a_claimed_ticket_is_closed_to_the_rest_of_the_desk(): void
    {
        $t = $this->ticket(['status' => 'open', 'assigned_to' => self::SUPPORT]);

        $this->send(self::OTHER_SUPPORT, $t, 'Тоже отвечу')->assertForbidden();

        $this->assertSame(self::SUPPORT,
            (int) DB::table('chat_tickets')->where('id', $t)->value('assigned_to'),
            'назначение остаётся за тем, кто взял первым');
    }

    /**
     * ⚠ Сообщение ПАРТНЁРА не назначает тикет: иначе он «самоназначался» бы
     * на автора и пропадал из очереди отдела.
     */
    #[Test]
    public function a_partner_message_never_claims_the_ticket(): void
    {
        $t = $this->ticket(['status' => 'new', 'assigned_to' => null]);

        $this->send(self::PARTNER, $t, 'Здравствуйте')->assertOk();

        $row = DB::table('chat_tickets')->where('id', $t)->first();

        $this->assertNull($row->assigned_to);
        $this->assertSame('new', $row->status, 'статус тоже не меняется');
    }

    // ---------------- Сообщение и счётчики ----------------

    /** Сообщение сохраняется с автором и признаком «от сотрудника». */
    #[Test]
    public function the_message_records_its_author_and_side(): void
    {
        $t = $this->ticket();

        $this->send(self::SUPPORT, $t, 'Ответ сотрудника')->assertOk();
        $this->send(self::PARTNER, $t, 'Ответ партнёра')->assertOk();

        $rows = DB::table('chat_messages')->where('ticket_id', $t)->orderBy('id')->get();

        $this->assertSame('Ответ сотрудника', $rows[0]->content);
        $this->assertTrue((bool) $rows[0]->is_agent, 'сообщение сотрудника помечено');
        $this->assertFalse((bool) $rows[1]->is_agent, 'сообщение партнёра — нет');
    }

    /** Счётчик сообщений и время последнего обновляются на тикете. */
    #[Test]
    public function the_ticket_counters_move_with_each_message(): void
    {
        $t = $this->ticket(['messages_count' => 0]);

        $this->send(self::PARTNER, $t, 'Первое')->assertOk();
        $this->send(self::PARTNER, $t, 'Второе')->assertOk();

        $row = DB::table('chat_tickets')->where('id', $t)->first();

        $this->assertSame(2, (int) $row->messages_count);
        $this->assertNotNull($row->last_message_at);
    }

    /** Пустое сообщение без вложения не принимается. */
    #[Test]
    public function an_empty_message_is_rejected(): void
    {
        $t = $this->ticket();

        $this->send(self::PARTNER, $t, '')->assertStatus(422);
    }

    /** В чужой тикет писать нельзя. */
    #[Test]
    public function writing_into_a_foreign_ticket_is_forbidden(): void
    {
        $t = $this->ticket([
            'created_by' => self::OTHER_SUPPORT, 'department' => 'accruals',
            'assigned_to' => self::OTHER_SUPPORT,
        ]);

        $this->send(self::PARTNER, $t, 'Чужой тикет')->assertForbidden();
    }

    // ================================================================

    private function send(int $userId, int $ticketId, string $text)
    {
        return $this->actingAs(User::find($userId), 'sanctum')
            ->postJson('/api/v1/chat/tickets/' . $ticketId . '/messages', [
                'message' => $text,
            ]);
    }

    /** @param array<string, mixed> $attrs */
    private function ticket(array $attrs = []): int
    {
        $id = $this->ticketSeq++;
        DB::table('chat_tickets')->insert(array_merge([
            'id' => $id,
            'subject' => 'Тикет ' . $id,
            'status' => 'new',
            'priority' => 'normal',
            'department' => 'support',
            'created_by' => self::PARTNER,
            'messages_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return $id;
    }

    private function seedUsers(): void
    {
        $this->user(self::PARTNER, 'consultant', 'Партнёров');
        $this->user(self::SUPPORT, 'support', 'Саппортов');
        $this->user(self::OTHER_SUPPORT, 'support', 'Коллегин');
    }

    private function user(int $id, string $role, string $last): void
    {
        $u = new User();
        $u->id = $id;
        $u->email = 'send' . $id . '@test.local';
        $u->role = $role;
        $u->lastName = $last;
        $u->firstName = 'Тест';
        $u->password = bcrypt('secret123');
        $u->save();
    }
}
