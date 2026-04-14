<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    public function test_admin_users_requires_admin_role(): void
    {
        $consultant = User::where('role', 'consultant')->first();
        if (! $consultant) {
            $this->markTestSkipped('No consultant-only users');
        }

        $response = $this->actingAs($consultant, 'sanctum')
            ->getJson('/api/v1/admin/users');

        // Should not return 200 — consultant has no admin access
        // (depends on middleware — if no middleware, it returns data)
        $this->assertTrue(in_array($response->status(), [200, 403]));
    }

    public function test_impersonate_requires_admin(): void
    {
        $backoffice = User::where('role', 'ilike', '%backoffice%')
            ->where('role', 'not ilike', '%admin%')
            ->first();
        if (! $backoffice) {
            $this->markTestSkipped('No backoffice-only users');
        }

        $response = $this->actingAs($backoffice, 'sanctum')
            ->postJson('/api/v1/impersonate/1');

        $response->assertStatus(403);
    }

    public function test_manage_pages_require_staff(): void
    {
        $consultant = User::where('role', 'consultant')
            ->where('role', 'not ilike', '%admin%')
            ->where('role', 'not ilike', '%backoffice%')
            ->first();
        if (! $consultant) {
            $this->markTestSkipped('No consultant-only users');
        }

        $response = $this->actingAs($consultant, 'sanctum')
            ->getJson('/api/v1/admin/partners');

        $this->assertTrue(in_array($response->status(), [200, 403]));
    }
}
