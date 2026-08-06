<?php

namespace Tests\Feature;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use App\Services\PartnerStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Самовосстановление партнёра после терминации (2026-08-06).
 *
 * ⚠ DB-тесты локально не идут (нет DB_PASSWORD_TEST и базы newds_test), а CI
 * гейтит только PHPStan — см. .github/workflows/deploy.yml. Тест фиксирует
 * контракт для окружения, где база поднята; локальная проверка сценария
 * делалась скриптом на dev-базе.
 */
class PartnerSelfReinstateTest extends TestCase
{
    use DatabaseTransactions;

    private function makeTerminated(array $attrs = []): Consultant
    {
        return Consultant::create(array_merge([
            'personName' => 'Тест Восстановление',
            'activity' => PartnerActivity::Terminated,
            'active' => false,
            'terminationCount' => 1,
            'reinstatement_count' => 0,
            'reinstate_blocked' => false,
            'personalVolume' => 0,
        ], $attrs));
    }

    public function test_terminated_partner_returns_to_registered_with_fresh_window(): void
    {
        $c = $this->makeTerminated(['personalVolume' => 320]);

        $res = app(PartnerStatusService::class)->selfReinstate($c);

        $this->assertTrue($res['ok']);
        $c->refresh();
        $this->assertSame(PartnerActivity::Registered, $c->activity);
        $this->assertSame(0.0, (float) $c->personalVolume);
        $this->assertSame(1, (int) $c->reinstatement_count);
        $this->assertNotNull($c->activationDeadline);
        // Счётчик терминаций самовосстановлением не трогается.
        $this->assertSame(1, (int) $c->terminationCount);
    }

    public function test_limit_is_enforced(): void
    {
        $c = $this->makeTerminated(['reinstatement_count' => PartnerActivity::selfReinstateLimit()]);

        $res = app(PartnerStatusService::class)->selfReinstate($c);

        $this->assertFalse($res['ok']);
        $this->assertSame(PartnerActivity::Terminated, $c->refresh()->activity);
    }

    public function test_excluded_partner_cannot_self_reinstate(): void
    {
        $c = $this->makeTerminated(['activity' => PartnerActivity::Excluded]);

        $this->assertFalse(app(PartnerStatusService::class)->selfReinstate($c)['ok']);
    }

    public function test_admin_block_closes_the_door(): void
    {
        $c = $this->makeTerminated(['reinstate_blocked' => true]);

        $this->assertFalse(app(PartnerStatusService::class)->selfReinstate($c)['ok']);
    }

    /**
     * Ключевое следствие смены триггера: партнёр с оставшимися попытками после
     * терминации остаётся «Терминирован» (иначе не увидит окно возврата), а
     * исчерпавший — уходит в «Исключён».
     */
    public function test_termination_excludes_only_when_no_attempts_left(): void
    {
        $service = app(PartnerStatusService::class);

        $withAttempts = Consultant::create([
            'personName' => 'Тест Есть попытки',
            'activity' => PartnerActivity::Active,
            'active' => true,
            'terminationCount' => 0,
            'reinstatement_count' => 0,
        ]);
        $this->assertSame(PartnerActivity::Terminated, $service->terminate($withAttempts, 'тест'));

        $exhausted = Consultant::create([
            'personName' => 'Тест Попыток нет',
            'activity' => PartnerActivity::Active,
            'active' => true,
            'terminationCount' => 0,
            'reinstatement_count' => PartnerActivity::selfReinstateLimit(),
        ]);
        $this->assertSame(PartnerActivity::Excluded, $service->terminate($exhausted, 'тест'));
    }
}
