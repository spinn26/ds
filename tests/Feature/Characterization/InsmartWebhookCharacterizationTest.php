<?php

namespace Tests\Feature\Characterization;

use App\Services\InsmartIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ХАРАКТЕРИЗУЮЩИЙ тест вебхука Insmart (Этап 0 + Этап 4).
 *
 * Вебхук создаёт person, client, contract и transaction одной транзакцией —
 * это единственный внешний источник, пишущий деньги без участия оператора.
 * Проверяем идемпотентность и то, что выдача id контракта не ломает сиквенс.
 */
class InsmartWebhookCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 970001;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('consultant')->insert([
            'id' => self::PARTNER,
            'personName' => 'Партнёр Инсмарт',
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
        ]);
    }

    #[Test]
    public function paid_webhook_creates_contract_and_transaction(): void
    {
        $result = app(InsmartIntegrationService::class)->handlePaidWebhook($this->payload('ORDER-1'));

        $this->assertSame('created', $result['status']);
        $this->assertSame(self::PARTNER, $result['consultantId']);
        $this->assertFalse($result['frozenPeriod']);

        $contract = DB::table('contract')->where('counterpartyContractId', 'ORDER-1')->first();
        $this->assertNotNull($contract, 'контракт создан');
        $this->assertSame(self::PARTNER, (int) $contract->consultant);

        $tx = DB::table('transaction')->where('contract', $contract->id)->first();
        $this->assertNotNull($tx, 'транзакция создана');
        // Сумма транзакции — страховая премия, а НЕ агентская комиссия:
        // раньше писали комиссию, и отчёт показывал КВ вместо взноса.
        $this->assertEqualsWithDelta(100_000.0, (float) $tx->amount, 0.01);
    }

    /** Повторный постбек по тому же заказу ничего не создаёт. */
    #[Test]
    public function repeated_webhook_is_idempotent(): void
    {
        $service = app(InsmartIntegrationService::class);
        $service->handlePaidWebhook($this->payload('ORDER-2'));
        $result = $service->handlePaidWebhook($this->payload('ORDER-2'));

        $this->assertSame('already_processed', $result['status']);
        $this->assertSame(1, DB::table('contract')->where('counterpartyContractId', 'ORDER-2')->count());
    }

    /** Неоплаченный постбек пропускается. */
    #[Test]
    public function unpaid_webhook_is_skipped(): void
    {
        $payload = $this->payload('ORDER-3');
        $payload['paid'] = false;
        $payload['status'] = 0;

        $result = app(InsmartIntegrationService::class)->handlePaidWebhook($payload);

        $this->assertSame('skipped_not_paid', $result['status']);
        $this->assertSame(0, DB::table('contract')->count());
    }

    /**
     * ⚠ Регресс-тест: id контракта выдаёт СИКВЕНС.
     *
     * Раньше здесь стоял LegacyId::next (MAX(id)+1), который сиквенс не
     * двигает. Каждая запись вебхука уводила его в отставание, и следующий
     * insertGetId — например, из импорта контрактов — врезался в занятый id и
     * падал «duplicate key». Ровно так в августе 2026 слёг импорт транзакций.
     */
    #[Test]
    public function contract_ids_do_not_desync_the_sequence(): void
    {
        $service = app(InsmartIntegrationService::class);
        $service->handlePaidWebhook($this->payload('ORDER-4'));
        $service->handlePaidWebhook($this->payload('ORDER-5'));

        // Обычная вставка, полагающаяся на сиквенс, не должна коллизировать.
        $id = DB::table('contract')->insertGetId([
            'number' => 'AFTER-WEBHOOK',
            'consultant' => self::PARTNER,
        ]);

        $this->assertGreaterThan(
            (int) DB::table('contract')->where('counterpartyContractId', 'ORDER-5')->value('id'),
            $id,
            'сиквенс не отстал от записей вебхука'
        );
        $this->assertSame(3, DB::table('contract')->count());
    }

    private function payload(string $orderId): array
    {
        return [
            'externalOrderId' => $orderId,
            'paid' => true,
            'status' => 2,
            'appClientId' => self::PARTNER,
            'clientFio' => 'Клиент Тестовый',
            'clientEmail' => 'client@test.local',
            'clientPhone' => '+7 900 000-00-00',
            'policyAmount' => 100_000,
            'agentCommission' => 20_000,
            'currency' => 'RUB',
            'paidAt' => '2026-07-15T10:00:00+03:00',
        ];
    }
}
