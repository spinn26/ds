<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketTest extends TestCase
{
    public function test_create_ticket(): void
    {
        $user = User::where('role', 'ilike', '%consultant%')->first();
        if (! $user) {
            $this->markTestSkipped('No consultant users');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'subject' => 'Тестовый тикет',
                'category' => 'support',
                'message' => 'Тестовое сообщение',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'id']);
    }

    public function test_ticket_list(): void
    {
        $user = User::where('role', 'ilike', '%consultant%')->first();
        if (! $user) {
            $this->markTestSkipped('No consultant users');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tickets');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_ticket_categories(): void
    {
        $user = User::first();
        if (! $user) {
            $this->markTestSkipped('No users');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tickets/categories');

        $response->assertStatus(200)
            ->assertJsonStructure(['support', 'backoffice', 'legal', 'accounting', 'accruals']);
    }

    public function test_ticket_stats(): void
    {
        $user = User::where('role', 'ilike', '%admin%')->first();
        if (! $user) {
            $this->markTestSkipped('No admin users');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tickets/stats');

        $response->assertStatus(200)
            ->assertJsonStructure(['openToday', 'totalOpen', 'inProgress', 'closedToday']);
    }
}
