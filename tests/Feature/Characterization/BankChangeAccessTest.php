<?php

namespace Tests\Feature\Characterization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Кто может подтверждать смену банковских реквизитов
 * (POST /admin/bank-change-requests/{id}/accept и /reject).
 *
 * Подтверждение смены счёта — вектор мошенничества с выплатами, поэтому круг
 * допущенных должен быть виден и управляем.
 *
 * ⚠ Раньше эти кнопки были закрыты ЖЁСТКИМ списком ролей (admin, finance) и
 * сетку «Группы и права» не читали вовсе: что бы ни стояло в колонке «Смена
 * реквизитов», доступ не менялся. Расхождение шло в обе стороны — finance мог
 * подтверждать, имея по сетке только просмотр, а руководитель по расчётам не
 * мог, имея полный доступ.
 *
 * Теперь гейт идёт через сетку (`permission:bank-changes,full`), и этот тест
 * держит именно это: доступ определяется уровнем в группе, а не именем роли.
 */
class BankChangeAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 3400001;

    private int $seq = 3400100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFixture();
    }

    /** Полный доступ к разделу — подтверждать можно. */
    #[Test]
    public function a_group_with_full_access_may_accept(): void
    {
        $this->setLevel('calculations', 'full');

        $this->accept($this->user('calculations'))->assertOk();
    }

    /** Админ проходит всегда: у него полный доступ ко всем разделам. */
    #[Test]
    public function an_admin_may_accept(): void
    {
        $this->accept($this->user('admin'))->assertOk();
    }

    /**
     * ⚠ Только просмотр — подтверждать НЕЛЬЗЯ, даже если раньше роль это
     * умела. Уровень в сетке теперь единственный источник правды.
     */
    #[Test]
    public function a_view_only_group_may_not_accept(): void
    {
        $this->setLevel('support', 'view');

        $this->accept($this->user('support'))->assertForbidden();
    }

    /** Правка — тоже недостаточно: подтверждение требует полного доступа. */
    #[Test]
    public function an_edit_level_is_not_enough(): void
    {
        $this->setLevel('backoffice', 'edit');

        $this->accept($this->user('backoffice'))->assertForbidden();
    }

    /** Отклонение закрыто тем же уровнем, что и подтверждение. */
    #[Test]
    public function rejecting_needs_the_same_level(): void
    {
        $this->setLevel('support', 'view');

        $id = $this->request();
        $this->actingAs($this->user('support'), 'sanctum')
            ->postJson('/api/v1/admin/bank-change-requests/' . $id . '/reject', [
                'reason' => 'нет',
            ])->assertForbidden();
    }

    /** Партнёру раздел недоступен вовсе. */
    #[Test]
    public function a_partner_may_not_accept(): void
    {
        $this->accept($this->user('consultant'))->assertForbidden();
    }

    // ================================================================

    private function accept(User $as)
    {
        return $this->actingAs($as, 'sanctum')
            ->postJson('/api/v1/admin/bank-change-requests/' . $this->request() . '/accept');
    }

    /** Заявка на смену реквизитов в статусе «ожидает». */
    private function request(): int
    {
        $id = $this->seq++;
        DB::table('bank_requisite_change_requests')->insert([
            'id' => $id,
            'consultant' => self::PARTNER,
            'requisite_id' => null,
            'new_bank_name' => 'Новый банк',
            'new_bank_bik' => '044525225',
            'new_account_number' => '40802810000000000002',
            'new_correspondent_account' => '30101810400000000225',
            'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function setLevel(string $group, string $level): void
    {
        $row = DB::table('permission_groups')->where('key', $group)->first();
        $perms = $row ? (json_decode((string) $row->permissions, true) ?: []) : [];
        $perms['bank-changes'] = $level;

        DB::table('permission_groups')->updateOrInsert(
            ['key' => $group],
            [
                'name' => $group,
                'permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]
        );
    }

    private function user(string $role): User
    {
        $u = new User();
        $u->id = $this->seq++;
        $u->email = 'bank' . $u->id . '@test.local';
        $u->role = $role;
        $u->lastName = 'Тестов';
        $u->firstName = 'Тест';
        $u->password = bcrypt('secret123');
        $u->save();

        return $u;
    }

    private function seedFixture(): void
    {
        DB::table('consultant')->insert([
            'id' => self::PARTNER,
            'personName' => 'Банковский Партнёр',
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
    }
}
