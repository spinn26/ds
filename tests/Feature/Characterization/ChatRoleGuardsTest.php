<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ролевые гарды инцидентов и рабочего стола техподдержки.
 *
 * Эти проверки живут в телах методов ChatController, а не в middleware, и до
 * сих пор не были покрыты ничем: мутация «гард пропускает всех» проходила
 * незамеченной. Тест закрывает именно доступ — содержимое ответов проверяют
 * другие тесты.
 */
class ChatRoleGuardsTest extends TestCase
{
    use RefreshDatabase;

    private const TICKET = 980100;

    #[Test]
    public function support_desk_is_open_to_support_roles(): void
    {
        foreach (['admin', 'support', 'head'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->getJson('/api/v1/support/desk')
                ->assertOk();
        }
    }

    #[Test]
    public function support_desk_is_closed_to_others(): void
    {
        foreach (['backoffice', 'finance', 'calculations'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->getJson('/api/v1/support/desk')
                ->assertStatus(403);
        }
    }

    #[Test]
    public function marking_an_incident_is_closed_to_others(): void
    {
        $this->actingAs($this->userWithRole('finance'), 'sanctum')
            ->postJson('/api/v1/chat/tickets/' . self::TICKET . '/incident', ['severity' => 'high'])
            ->assertStatus(403);
    }

    /**
     * Гард стоит ДО поиска тикета: привилегированная роль доходит до логики и
     * получает уже не 403 (тикета в выборке нет — потому 404/422).
     */
    #[Test]
    public function marking_an_incident_is_open_to_support_roles(): void
    {
        $status = $this->actingAs($this->userWithRole('support'), 'sanctum')
            ->postJson('/api/v1/chat/tickets/' . self::TICKET . '/incident', ['severity' => 'high'])
            ->status();

        $this->assertNotSame(403, $status, 'техподдержка не должна упираться в гард');
    }

    #[Test]
    public function resolving_an_incident_is_admin_only(): void
    {
        $this->actingAs($this->userWithRole('support'), 'sanctum')
            ->postJson('/api/v1/chat/tickets/' . self::TICKET . '/incident/resolve')
            ->assertStatus(403);

        $status = $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/chat/tickets/' . self::TICKET . '/incident/resolve')
            ->status();

        $this->assertNotSame(403, $status, 'админ проходит гард');
    }

    private function userWithRole(string $role): User
    {
        $user = new User();
        $user->email = $role . '@guards.local';
        $user->firstName = 'Гард';
        $user->lastName = ucfirst($role);
        $user->role = $role;
        $user->password = bcrypt('secret123');
        $user->save();

        return $user;
    }
}
