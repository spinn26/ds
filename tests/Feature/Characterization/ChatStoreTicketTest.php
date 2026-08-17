<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Создание тикета (POST /chat/tickets).
 *
 * Сетка ПОД вынос. Здесь решается, куда обращение попадёт и кто его увидит,
 * поэтому закреплено:
 *   - категория нормализуется (legacy-ключи сворачиваются в текущие), а
 *     неизвестная не принимается вовсе;
 *   - адресный тикет партнёру и тикет в общую категорию — разные вещи:
 *     у первого есть получатель, у второго его нет;
 *   - повторное обращение к тому же получателю не плодит тикеты: возвращается
 *     существующий с признаком дедупликации;
 *   - разметка и теги из темы и сообщения вырезаются — бэкенд хранит текст.
 */
class ChatStoreTicketTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 2900001;
    private const SUPPORT = 2900002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedUsers();
    }

    // ---------------- Категория ----------------

    /** Обычное обращение в отдел: получателя нет, автор — партнёр. */
    #[Test]
    public function a_department_ticket_has_no_recipient(): void
    {
        $id = $this->create([
            'department' => 'support', 'subject' => 'Не работает вход',
            'message' => 'Не пускает по паролю',
        ]);

        $row = DB::table('chat_tickets')->where('id', $id)->first();

        $this->assertSame('support', $row->department);
        $this->assertNull($row->recipient_id);
        $this->assertSame(self::PARTNER, (int) $row->created_by);
    }

    /** Legacy-ключ категории сворачивается в текущий. */
    #[Test]
    public function a_legacy_department_key_is_normalised(): void
    {
        $id = $this->create([
            'department' => 'billing', 'subject' => 'Вопрос по выплате',
            'message' => 'Когда придут деньги?',
        ]);

        $this->assertSame('accruals',
            DB::table('chat_tickets')->where('id', $id)->value('department'),
            'billing — старое имя категории «Начисления и выплаты»');
    }

    /** Неизвестная категория не принимается. */
    #[Test]
    public function an_unknown_department_is_rejected(): void
    {
        $this->send(['department' => 'нет-такого', 'subject' => 'Тема'])
            ->assertStatus(422);
    }

    /** Тема обязательна. */
    #[Test]
    public function the_subject_is_required(): void
    {
        $this->send(['department' => 'support', 'message' => 'Текст'])->assertStatus(422);
    }

    /**
     * ⚠ Сообщение тоже обязательно — кроме «тихих» тикетов, которые заводит
     * кнопка «Написать» из списка партнёров: там переписка начинается без
     * авто-приветствия.
     */
    #[Test]
    public function the_message_is_required_unless_the_ticket_is_silent(): void
    {
        $this->send(['department' => 'support', 'subject' => 'Тема'])->assertStatus(422);

        $this->send(['department' => 'support', 'subject' => 'Тема', 'silent' => true])
            ->assertCreated();
    }

    // ---------------- Адресный тикет ----------------

    /** У тикета с получателем проставлен адресат. */
    #[Test]
    public function a_direct_ticket_records_its_recipient(): void
    {
        $id = $this->create([
            'department' => 'support',
            'subject' => 'Личный вопрос',
            'message' => 'Здравствуйте',
            'recipient_id' => self::SUPPORT,
            'context_type' => 'Партнёр',
            'context_id' => (string) self::PARTNER,
        ]);

        $this->assertSame(self::SUPPORT,
            (int) DB::table('chat_tickets')->where('id', $id)->value('recipient_id'));
    }

    /**
     * ⚠ Повторное обращение к тому же получателю БЕЗ контекста не создаёт
     * второй тикет: возвращается существующий с признаком дедупликации. Это
     * защита от двойного клика по кнопке «Написать».
     */
    #[Test]
    public function a_repeated_direct_ticket_without_context_is_deduplicated(): void
    {
        $payload = [
            'department' => 'support',
            'subject' => 'Личный вопрос',
            'message' => 'Здравствуйте',
            'recipient_id' => self::SUPPORT,
        ];

        $first = $this->create($payload);
        $again = $this->send($payload)->assertOk();

        $this->assertTrue($again->json('deduplicated'));
        $this->assertSame($first, (int) $again->json('ticket.id'));
        $this->assertSame(1, DB::table('chat_tickets')->count(), 'второй тикет не завёлся');
    }

    /**
     * ⚠ А вот с контекстом дедупликация НАМЕРЕННО не работает: по одному
     * контракту или клиенту может быть открыто несколько параллельных
     * обращений, и схлопывать их в одно нельзя.
     */
    #[Test]
    public function tickets_with_a_context_are_not_deduplicated(): void
    {
        $payload = [
            'department' => 'support',
            'subject' => 'Вопрос по контракту',
            'message' => 'Здравствуйте',
            'recipient_id' => self::SUPPORT,
            'context_type' => 'Контракт',
            'context_id' => '12345',
        ];

        $this->create($payload);
        $this->create($payload);

        $this->assertSame(2, DB::table('chat_tickets')->count(),
            'два обращения по одному контракту — это два тикета');
    }

    // ---------------- Содержимое ----------------

    /** Разметка из темы и сообщения вырезается — бэкенд хранит текст. */
    #[Test]
    public function markup_is_stripped_from_the_text(): void
    {
        $id = $this->create([
            'department' => 'support',
            'subject' => '<b>Жирная тема</b>',
            'message' => '<script>alert(1)</script>Текст',
        ]);

        $row = DB::table('chat_tickets')->where('id', $id)->first();

        $this->assertSame('Жирная тема', $row->subject);
        $this->assertStringNotContainsString('<script>',
            (string) DB::table('chat_messages')->where('ticket_id', $id)->value('content'));
    }

    /** Первое сообщение сохраняется вместе с тикетом. */
    #[Test]
    public function the_first_message_is_stored_with_the_ticket(): void
    {
        $id = $this->create([
            'department' => 'support',
            'subject' => 'Тема',
            'message' => 'Первое сообщение',
        ]);

        $this->assertStringContainsString('Первое сообщение',
            (string) DB::table('chat_messages')->where('ticket_id', $id)->value('content'));
    }

    // ================================================================

    /** @param array<string, mixed> $payload */
    private function send(array $payload)
    {
        return $this->actingAs(User::find(self::PARTNER), 'sanctum')
            ->postJson('/api/v1/chat/tickets', $payload);
    }

    /** @param array<string, mixed> $payload */
    private function create(array $payload): int
    {
        return (int) $this->send($payload)->assertCreated()->json('ticket.id');
    }

    private function seedUsers(): void
    {
        $this->user(self::PARTNER, 'consultant', 'Партнёров');
        $this->user(self::SUPPORT, 'support', 'Саппортов');
    }

    private function user(int $id, string $role, string $last): void
    {
        $u = new User();
        $u->id = $id;
        $u->email = 'store' . $id . '@test.local';
        $u->role = $role;
        $u->lastName = $last;
        $u->firstName = 'Тест';
        $u->password = bcrypt('secret123');
        $u->save();
    }
}
