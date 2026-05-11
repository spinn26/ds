<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeleteChatTicketTest extends TestCase
{
    // Каждый тест выполняется в транзакции и откатывается. Это позволяет
    // создавать "тестовые" тикеты прямо в newds_test без полей-маркеров и
    // не зависеть от RefreshDatabase (который выпилил бы сидированные роли).
    use DatabaseTransactions;

    private function createTicket(int $createdBy, string $department = 'general'): int
    {
        $id = DB::table('chat_tickets')->insertGetId([
            'subject' => 'TEST-DELETE-' . uniqid(),
            'description' => 'autotest',
            'status' => 'open',
            'priority' => 'medium',
            'department' => $department,
            'created_by' => $createdBy,
            'customer_name' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('chat_messages')->insert([
            'ticket_id' => $id,
            'sender_id' => $createdBy,
            'sender_name' => 'Test',
            'content' => 'autotest message',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function findUserByRole(string $role): ?User
    {
        return User::where('role', 'ilike', '%' . $role . '%')->first();
    }

    public function test_admin_deletes_ticket_and_writes_audit_record(): void
    {
        $admin = $this->findUserByRole('admin');
        if (! $admin) $this->markTestSkipped('No admin user in test DB');

        $ticketId = $this->createTicket($admin->id);

        $resp = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/chat/tickets/{$ticketId}");

        $resp->assertStatus(200)
            ->assertJson(['id' => $ticketId, 'deleted' => true]);

        // Каскады
        $this->assertDatabaseMissing('chat_tickets', ['id' => $ticketId]);
        $this->assertDatabaseMissing('chat_messages', ['ticket_id' => $ticketId]);

        // Аудит-запись осталась после commit
        $this->assertDatabaseHas('chat_ticket_changes', [
            'ticket_id' => $ticketId,
            'field' => 'deleted',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_non_admin_staff_gets_403(): void
    {
        // Сотрудник без admin: support / finance / etc.
        $support = User::whereRaw("role ilike '%support%'")
            ->whereRaw("role not ilike '%admin%'")
            ->first();
        if (! $support) $this->markTestSkipped('No non-admin staff user');

        $admin = $this->findUserByRole('admin');
        if (! $admin) $this->markTestSkipped('No admin to seed ticket');

        $ticketId = $this->createTicket($admin->id, 'support');

        $resp = $this->actingAs($support, 'sanctum')
            ->deleteJson("/api/v1/chat/tickets/{$ticketId}");

        $resp->assertStatus(403);
        $this->assertDatabaseHas('chat_tickets', ['id' => $ticketId]);
    }

    public function test_partner_creator_gets_403(): void
    {
        $partner = $this->findUserByRole('consultant');
        if (! $partner) $this->markTestSkipped('No partner user');

        // Сам же создатель — но удалять всё равно не имеет права.
        $ticketId = $this->createTicket($partner->id);

        $resp = $this->actingAs($partner, 'sanctum')
            ->deleteJson("/api/v1/chat/tickets/{$ticketId}");

        $resp->assertStatus(403);
        $this->assertDatabaseHas('chat_tickets', ['id' => $ticketId]);
    }

    public function test_returns_404_for_missing_ticket(): void
    {
        $admin = $this->findUserByRole('admin');
        if (! $admin) $this->markTestSkipped('No admin user');

        $resp = $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/chat/tickets/2147483600');

        $resp->assertStatus(404);
    }
}
