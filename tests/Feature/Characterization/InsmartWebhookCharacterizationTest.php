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

    /** Удалённый партнёр: вебхук его находит, а калькулятор удалённых не принимает. */
    private const DELETED_PARTNER = 970002;

    protected function setUp(): void
    {
        parent::setUp();
        // Кэш ставок НДС живёт весь процесс: без сброса «ставки нет» из одного
        // теста перетекает в другой, где ставка заведена.
        \App\Support\VatRate::flush();
        \App\Support\CurrencyRates::flush();

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

    /**
     * ⚠ Регресс-тест: ошибку расчёта комиссий нельзя проглатывать.
     *
     * calculateForTransaction не бросает, а возвращает ['error' => …], и вебхук
     * результат не смотрел: сделка создавалась, комиссии — нет, а наружу уходил
     * обычный «created». Цепочка ничего не получала, отчёт «Комиссии» давал по
     * сделке 0, а пул — оценку дохода: так август 2026 разошёлся на 740 ₽.
     */
    #[Test]
    public function commission_error_is_reported_not_swallowed(): void
    {
        DB::table('consultant')->insert([
            'id' => self::DELETED_PARTNER,
            'personName' => 'Удалённый партнёр',
            'activity' => 1,
            'dateCreated' => '2026-01-01 00:00:00',
            'dateDeleted' => '2026-07-01 00:00:00',
        ]);
        $payload = $this->payload('ORDER-6');
        $payload['appClientId'] = self::DELETED_PARTNER;

        $result = app(InsmartIntegrationService::class)->handlePaidWebhook($payload);

        // Сделку не теряем: платёж был, контракт и транзакция нужны.
        $this->assertSame('created', $result['status']);
        $this->assertFalse($result['commissionsCalculated']);
        $this->assertStringContainsString('удалён', (string) $result['commissionError']);

        $tx = DB::table('transaction')->where('id', $result['transactionId'])->first();
        $this->assertNotNull($tx, 'транзакция создана');
        $this->assertNull($tx->commissionsAmountRUB, 'доход ДС не записан — расчёт не прошёл');
    }

    /** Прошедший расчёт так и отмечается, а доход ДС записан. */
    #[Test]
    public function successful_calculation_is_reported(): void
    {
        DB::table('vat')->delete();
        DB::table('vat')->insert([
            'id' => 970100,
            'value' => 5,
            'dateFrom' => '2020-01-01',
            'dateTo' => '2050-01-01',
        ]);

        $result = app(InsmartIntegrationService::class)->handlePaidWebhook($this->payload('ORDER-7'));

        $this->assertTrue($result['commissionsCalculated'], (string) ($result['commissionError'] ?? ''));
        $this->assertNull($result['commissionError']);

        // Премия 100 000 с НДС 5% → база 95 238,10; КВ 20 000 = 20% премии →
        // доход ДС без НДС = 95 238,10 × 20% = 19 047,62.
        $income = DB::table('transaction')->where('id', $result['transactionId'])->value('commissionsAmountRUB');
        $this->assertEqualsWithDelta(19_047.62, (float) $income, 0.01);
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
