<?php

namespace Tests\Feature\Characterization;

use App\Enums\PartnerActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ручные баллы из «Прочих начислений» активируют «Зарегистрированного».
 *
 * Спека ✅Прочие начисления §3: баллы идут в ЛП и учитываются для статуса.
 * Порог активации проверялся только после расчёта комиссий, поэтому партнёр
 * без сделок, которому начислили 500 баллов, оставался «Зарегистрирован» и
 * ждал терминации по окну (Русакова, 14.09.2026).
 */
class ManualPointsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 990201;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User();
        $this->admin->id = 990200;
        $this->admin->email = 'points@test.local';
        $this->admin->firstName = 'Баллы';
        $this->admin->lastName = 'Тестовый';
        $this->admin->role = 'admin';
        $this->admin->password = bcrypt('secret123');
        $this->admin->save();

        DB::table('consultant')->insert([
            'id' => self::PARTNER,
            'personName' => 'Партнёр Новичок',
            'activity' => PartnerActivity::Registered->value,
            'active' => false,
            'personalVolume' => 0,
            'terminationCount' => 0,
            'activationDeadline' => now()->addDays(30),
            'dateCreated' => now()->subDays(90),
        ]);
    }

    #[Test]
    public function points_reaching_the_threshold_activate_a_registered_partner(): void
    {
        $this->charge('points', PartnerActivity::activationPoints())
            ->assertCreated()
            ->assertJsonPath('activated', true);

        $c = DB::table('consultant')->where('id', self::PARTNER)->first();
        $this->assertSame(PartnerActivity::Active->value, (int) $c->activity);
        $this->assertNotNull($c->dateActivity);
        $this->assertNotNull($c->yearPeriodEnd, 'срок годового периода выставлен — «Будет терминирован» сдвигается');
    }

    #[Test]
    public function points_below_the_threshold_keep_the_partner_registered(): void
    {
        $this->charge('points', PartnerActivity::activationPoints() - 1)
            ->assertCreated()
            ->assertJsonPath('activated', false);

        $this->assertSame(PartnerActivity::Registered->value, $this->activity());
    }

    /** Рубли на ЛП не влияют — и статус не трогают. */
    #[Test]
    public function rubles_do_not_activate(): void
    {
        $this->charge('rub', 100_000)
            ->assertCreated()
            ->assertJsonPath('activated', false);

        $this->assertSame(PartnerActivity::Registered->value, $this->activity());
    }

    /** Добор до порога правкой существующего начисления тоже активирует. */
    #[Test]
    public function raising_points_by_edit_activates(): void
    {
        $id = $this->charge('points', 100)->assertCreated()->json('id');
        $this->assertSame(PartnerActivity::Registered->value, $this->activity());

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/charges/' . $id, [
                'consultant' => self::PARTNER,
                'type' => 'points',
                'amount' => PartnerActivity::activationPoints(),
                'comment' => 'добор',
            ])
            ->assertOk()
            ->assertJsonPath('activated', true);

        $this->assertSame(PartnerActivity::Active->value, $this->activity());
    }

    private function charge(string $type, float $amount)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/charges', [
                'consultant' => self::PARTNER,
                'type' => $type,
                'amount' => $amount,
                'comment' => 'тест',
            ]);
    }

    private function activity(): int
    {
        return (int) DB::table('consultant')->where('id', self::PARTNER)->value('activity');
    }
}
