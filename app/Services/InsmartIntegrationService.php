<?php

namespace App\Services;

use App\Services\CommissionCalculator;
use App\Support\LegacyId;
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
 *   - найти/создать Client по ФИО+phone+email (через person);
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
 *
 * Legacy-схема: person и client — две разные таблицы (person.id — реальный
 * контакт, client.id — карточка-связка с консультантом). client колонок
 * email/phone/createDate не имеет — почта/телефон хранятся в person.
 * product и program не имеют identity sequence — id генерится через
 * LegacyId::next под advisory_xact_lock.
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
     *   externalOrderId (orderId), paid (bool|status), partnerId (appClientId),
     *   partnerFio, clientFio (insurant), clientEmail (email), clientPhone (phone),
     *   productCode (type), productName, providerName (company),
     *   policyAmount (price), agentCommission, currency
     * }
     */
    public function handlePaidWebhook(array $payload): array
    {
        // Идемпотентность: если контракт с этим внешним номером уже есть, no-op.
        $externalId = $payload['externalOrderId'] ?? $payload['orderId'] ?? null;
        if ($externalId && DB::table('contract')
            ->where('counterpartyContractId', $externalId)
            ->exists()
        ) {
            return ['status' => 'already_processed', 'externalOrderId' => $externalId];
        }
        if (empty($payload['paid']) && (int) ($payload['status'] ?? 0) !== 2) {
            return ['status' => 'skipped_not_paid'];
        }

        return DB::transaction(function () use ($payload, $externalId) {
            // 1) Resolve consultant (per spec §3.2)
            $consultantId = $this->resolveConsultant($payload);

            // 2) Resolve client (per spec §3.3) — пишем в person, потом client.
            $clientId = $this->resolveClient($payload, $consultantId);

            // 3) Resolve product+program (per spec §3.3 «Авто-наполнение»)
            [$productId, $programId] = $this->resolveProductAndProgram($payload);

            // 4) Контракт «Активирован»
            $contractNumber = 'INSMART-' . ($externalId ?? uniqid());
            $contractId = LegacyId::next('contract');
            DB::table('contract')->insert([
                'id' => $contractId,
                'number' => $contractNumber,
                'counterpartyContractId' => $externalId,
                'status' => $this->activatedStatusId(),
                'client' => $clientId,
                'clientName' => $payload['clientFio'] ?? $payload['insurant'] ?? null,
                'consultant' => $consultantId,
                'consultantName' => DB::table('consultant')->where('id', $consultantId)->value('personName'),
                'product' => $productId,
                'productName' => $payload['productName'] ?? null,
                'program' => $programId,
                'programName' => $payload['productName'] ?? null,
                'currency' => $this->resolveCurrencyId($payload['currency'] ?? 'RUB'),
                'ammount' => $payload['policyAmount'] ?? $payload['price'] ?? 0,
                'createDate' => now(),
                'openDate' => now(),
                'comment' => 'Заказ из Insmart',
                'createdAt' => now(),
                'changedAt' => now(),
            ]);

            // 5) Транзакция на сумму агентской комиссии
            $commission = (float) ($payload['agentCommission'] ?? 0);
            $txId = LegacyId::next('transaction');
            DB::table('transaction')->insert([
                'id' => $txId,
                'contract' => $contractId,
                'amount' => $commission,
                'amountRUB' => $commission,    // комиссия Insmart всегда в RUB
                'currency' => $this->resolveCurrencyId($payload['currency'] ?? 'RUB'),
                'currencyRate' => 1,
                'date' => now(),
                // dateMonth: 'YYYY-MM' (как остальные транзакции и фильтры).
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
        $partnerId = $payload['partnerId'] ?? $payload['appClientId'] ?? null;
        if (! $partnerId) return CommissionCalculator::UNKNOWN_CONSULTANT_ID;

        $partner = DB::table('consultant')->where('id', $partnerId)->first();
        if (! $partner) return CommissionCalculator::UNKNOWN_CONSULTANT_ID;

        $clientFio = mb_strtolower(trim((string) ($payload['clientFio'] ?? $payload['insurant'] ?? '')));
        $partnerFio = mb_strtolower(trim((string) $partner->personName));
        if ($clientFio && $clientFio === $partnerFio && $partner->inviter) {
            Log::info('InsmartIntegrationService: self-purchase, redirect to inviter', [
                'partnerId' => $partner->id, 'inviterId' => $partner->inviter,
            ]);
            return (int) $partner->inviter;
        }

        return (int) $partner->id;
    }

    /**
     * client.person → person.id, поиск по email/phone — в person.
     * Если не нашли — создаём person (личные данные) + client (карточка).
     */
    private function resolveClient(array $payload, int $consultantId): int
    {
        $email = $payload['clientEmail'] ?? $payload['email'] ?? null;
        $phone = $payload['clientPhone'] ?? $payload['phone'] ?? null;
        $fio = (string) ($payload['clientFio'] ?? $payload['insurant'] ?? 'Insmart Client');

        // Поиск в person → найти client по этому person.
        $personId = null;
        if ($email) {
            $personId = DB::table('person')->where('email', $email)->value('id');
        }
        if (! $personId && $phone) {
            $personId = DB::table('person')->where('phone', $phone)->value('id');
        }
        if ($personId) {
            $existingClient = DB::table('client')->where('person', $personId)->value('id');
            if ($existingClient) return (int) $existingClient;
        }

        // Создаём новые person + client.
        if (! $personId) {
            $parts = preg_split('/\s+/u', trim($fio));
            $lastName = $parts[0] ?? $fio;
            $firstName = $parts[1] ?? '';
            $patronymic = $parts[2] ?? null;

            $personId = DB::table('person')->insertGetId([
                'firstName' => $firstName,
                'lastName' => $lastName,
                'patronymic' => $patronymic,
                'email' => $email,
                'phone' => $phone,
                'role' => 'client',
                'dateCreated' => now()->toIso8601String(),
            ]);
        }

        return (int) DB::table('client')->insertGetId([
            'person' => $personId,
            'personName' => $fio,
            'consultant' => $consultantId,
            'dateCreated' => now(),
        ]);
    }

    private function resolveProductAndProgram(array $payload): array
    {
        $code = $payload['productCode'] ?? $payload['type'] ?? null;
        $name = $payload['productName'] ?? 'Insmart Product';
        $providerName = $payload['providerName'] ?? $payload['company'] ?? null;

        $productId = null;
        if ($code) {
            $productId = DB::table('product')->where('formLink', 'ilike', '%' . $code . '%')->value('id');
        }
        if (! $productId) {
            $productId = LegacyId::next('product');
            DB::table('product')->insert([
                'id' => $productId,
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
            $programId = LegacyId::next('program');
            DB::table('program')->insert([
                'id' => $programId,
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
