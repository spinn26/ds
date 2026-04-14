<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_with_valid_credentials(): void
    {
        $user = User::where('email', '!=', null)->first();
        if (! $user) {
            $this->markTestSkipped('No users in database');
        }

        // Set known password
        $user->password = Hash::make('test123');
        $user->saveQuietly();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'test123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'firstName', 'role']]);
    }

    public function test_login_with_wrong_password(): void
    {
        $user = User::where('email', '!=', null)->first();
        if (! $user) {
            $this->markTestSkipped('No users in database');
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong_password_definitely',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_rate_limiting(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'nonexistent@test.com',
                'password' => 'wrong',
            ]);
        }

        $response->assertStatus(429); // Too Many Requests
    }

    public function test_me_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/auth/me');
        $response->assertStatus(401);
    }

    public function test_me_returns_user_data(): void
    {
        $user = User::where('email', '!=', null)->first();
        if (! $user) {
            $this->markTestSkipped('No users in database');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure(['id', 'email', 'firstName', 'role', 'activityStatus']);
    }
}
