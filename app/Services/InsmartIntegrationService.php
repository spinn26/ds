<?php

namespace App\Services;

use App\Services\CommissionCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Интеграция с Insmart (per spec ✅Инсмарт.md).
 *
 * **Этот сервис — backbone для приёма вебхуков от Insmart.** Полная
 * привязка по API ключам/реальной интеграции с продакшеном требует
 * креденшелов, которых в репозитории нет — это задача деплоя.
 *
 * Сервис умеет:
 *   - принять payload с фактической оплатой страхового продукта
 *     (статус = «Оплачен»);
 *   - найти/создать Client по ФИО+phone+email;
 *   - найти/создать Product / Program «на лету» если такого ещё нет
 *     в нашей БД (per spec §3 «Авто-наполнение каталога»);
 *   - определить получателя комиссии:
 *       * прямой партнёр (по ID из payload),
 *       * если ФИО клиента совпадает с ФИО партнёра — комиссия идёт
 *         наставнику (per spec «Валидация для себя»);
 *       * если партнёр не найден — UNKNOWN_CONSULTANT_ID (0%, без каскада);
 *   - создать contract (status «Активирован», number с пометкой Insmart);
 *   - создать transaction на сумму агентской комиссии;
 *   - запустить CommissionCalculator на этой транзакции.
 */
class InsmartIntegrationService
{
    public function __construct(
        private readonly CommissionCalculator $calculator,
    ) {}

    /**
     * Обработать вебхук «Оплачено». Идемпотентен по externalOrderId.
     *
     * @param array $payload {
     *   externalOrderId, paid (bool), partnerId,
     *   partnerFio, clientFio, clientEmail, clientPhone,
     *   productCode, productName, providerName,
     *   policyAmount, agentCommission, currency
     * }
     */
    public function handlePaidWebhook(array $payload): array
    {
        // Идемпотентность: если контракт с этим внешним номером уже есть, no-op.
        $externalId = $payload['externalOrderId'] ?? null;
        if ($externalId && DB::table('contract')
            ->where('counterpartyContractId', $externalId)
            ->exists()
        ) {
            return ['status' => 'already_processed', 'externalOrderId' => $externalId];
        }
        if (empty($payload['paid'])) {
            return ['status' => 'skipped_not_paid'];
        }

        return DB::transaction(function () use ($payload) {
            // 1) Resolve consultant (per spec §3.2)
            $consultantId = $this->resolveConsultant($payload);

            // 2) Resolve client (per spec §3.3)
            $clientId = $this->resolveClient($payload, $consultantId);

            // 3) Resolve product+program (per spec §3.3 «Авто-наполнение»)
            [$productId, $programId] = $this->resolveProductAndProgram($payload);

            // 4) Контракт «Активирован»
            $contractId = DB::table('contract')->insertGetId([
                'number' => 'INSMART-' . ($payload['externalOrderId'] ?? uniqid()),
                'counterpartyContractId' => $payload['externalOrderId'] ?? null,
                'status' => $this->activatedStatusId(),
                'client' => $clientId,
                'clientName' => $payload['clientFio'] ?? null,
                'consultant' => $consultantId,
                'consultantName' => DB::table('consultant')->where('id', $consultantId)->value('personName'),
                'product' => $productId,
                'productName' => $payload['productName'] ?? null,
                'program' => $programId,
                'programName' => $payload['productName'] ?? null,
                'currency' => $this->resolveCurrencyId($payload['currency'] ?? 'RUB'),
                'ammount' => $payload['policyAmount'] ?? 0,
                'createDate' => now(),
                'openDate' => now(),
                'comment' => 'Заказ из Insmart',
                'createdAt' => now(),
                'changedAt' => now(),
            ]);

            // 5) Транзакция на сумму агентской комиссии
            $commission = (float) ($payload['agentCommission'] ?? 0);
            $txId = DB::table('transaction')->insertGetId([
                'contract' => $contractId,
                'amount' => $commission,
                'amountRUB' => $commission,    // считаем что комиссия Insmart всегда в RUB
                'currency' => $this->resolveCurrencyId($payload['currency'] ?? 'RUB'),
                'currencyRate' => 1,
                'date' => now(),
                // dateMonth хранится в формате 'YYYY-MM' (как остальные
                // транзакции и фильтры в коде); раньше тут было 'm', из-за
                // чего Insmart-транзакции не попадали в pool/finance/report.
                'dateMonth' => now()->format('Y-m'),
                'dateYear' => now()->format('Y'),
                'comment' => 'Insmart agent commission',
                'dateCreated' => now(),
                'changedAt' => now(),
            ]);

            // 6) Каскадные комиссии
            $this->calculator->calculateForTransaction($txId);

            return [
                'status' => 'created',
                'contractId' => $contractId,
                'transactionId' => $txId,
                'consultantId' => $consultantId,
            ];
        });
    }

    /** Per spec §3.2: «для себя» → комиссия идёт наставнику. */
    private function resolveConsultant(array $payload): int
    {
        $partnerId = $payload['partnerId'] ?? null;
        if (! $partnerId) return CommissionCalculator::UNKNOWN_CONSULTANT_ID;

        $partner = DB::table('consultant')->where('id', $partnerId)->first();
        if (! $partner) return CommissionCalculator::UNKNOWN_CONSULTANT_ID;

        $clientFio = mb_strtolower(trim((string) ($payload['clientFio'] ?? '')));
        $partnerFio = mb_strtolower(trim((string) $partner->personName));
        if ($clientFio && $clientFio === $partnerFio && $partner->inviter) {
            Log::info('InsmartIntegrationService: self-purchase, redirect to inviter', [
                'partnerId' => $partner->id, 'inviterId' => $partner->inviter,
            ]);
            return (int) $partner->inviter;
        }

        return (int) $partner->id;
    }

    private function resolveClient(array $payload, int $consultantId): int
    {
        $email = $payload['clientEmail'] ?? null;
        $phone = $payload['clientPhone'] ?? null;
        if ($email) {
            $existing = DB::table('client')->where('email', $email)->value('id');
            if ($existing) return (int) $existing;
        }
        if ($phone) {
            $existing = DB::table('client')->where('phone', $phone)->value('id');
            if ($existing) return (int) $existing;
        }

        return DB::table('client')->insertGetId([
            'personName' => $payload['clientFio'] ?? 'Insmart Client',
            'email' => $email,
            'phone' => $phone,
            'consultant' => $consultantId,
            'active' => true,
            'createDate' => now(),
        ]);
    }

    private function resolveProductAndProgram(array $payload): array
    {
        $code = $payload['productCode'] ?? null;
        $name = $payload['productName'] ?? 'Insmart Product';
        $providerName = $payload['providerName'] ?? null;

        $productId = null;
        if ($code) {
            $productId = DB::table('product')->where('formLink', 'ilike', '%' . $code . '%')->value('id');
        }
        if (! $productId) {
            $productId = DB::table('product')->insertGetId([
                'name' => $name,
                'active' => true,
                'visibleToCalculator' => false,
                'visibleToResident' => false,
                'noComission' => false,
                'publish_status' => 'draft',
            ]);
        }

        // Программа: одна на провайдер+продукт
        $programId = DB::table('program')
            ->where('product', $productId)
            ->where('providerName', $providerName)
            ->value('id');
        if (! $programId) {
            $programId = DB::table('program')->insertGetId([
                'product' => $productId,
                'name' => $name . ($providerName ? " ({$providerName})" : ''),
                'productName' => $name,
                'providerName' => $providerName,
                'active' => true,
            ]);
        }

        return [$productId, $programId];
    }

    private function activatedStatusId(): ?int
    {
        return DB::table('contractStatus')->where('name', 'Активирован')->value('id');
    }

    private function resolveCurrencyId(string $code): int
    {
        $map = ['RUB' => 67, 'USD' => 5, 'EUR' => 17, 'GBP' => 10];
        return $map[strtoupper($code)] ?? 67;
    }
}
