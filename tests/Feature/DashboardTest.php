<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\User;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_dashboard_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/dashboard');
        $response->assertStatus(401);
    }

    public function test_dashboard_returns_data(): void
    {
        $user = User::where('role', 'ilike', '%consultant%')->first();
        if (! $user) {
            $this->markTestSkipped('No consultant users');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'consultant' => ['id', 'personName', 'statusName'],
                'qualification',
                'volumes' => ['personalVolume', 'groupVolume', 'groupVolumeCumulative'],
                'team',
                'partners',
                'period',
            ]);
    }

    public function test_dashboard_with_period(): void
    {
        $user = User::where('role', 'ilike', '%consultant%')->first();
        if (! $user) {
            $this->markTestSkipped('No consultant users');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard?month=2025-12');

        $response->assertStatus(200)
            ->assertJsonPath('period', '2025-12');
    }
}
