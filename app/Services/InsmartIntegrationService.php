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

            // 2) Если клиент сам является ФК — контракт идёт его инвайтеру.
            //    ФК не может продавать самому себе; client-запись при этом
            //    создаётся на имя инвайтера (реального куратора сделки).
            $consultantId = $this->redirectIfClientIsPartner($payload, $consultantId);

            // 3) Resolve client (per spec §3.3) — пишем в person, потом client.
            $clientId = $this->resolveClient($payload, $consultantId);

            // 3) Resolve product+program (per spec §3.3 «Авто-наполнение»)
            [$productId, $programId] = $this->resolveProductAndProgram($payload);

            $productName = $productId
                ? DB::table('product')->where('id', $productId)->value('name')
                : null;
            $programName = $programId
                ? DB::table('program')->where('id', $programId)->value('name')
                : null;

            // Дата оплаты из payload (paidAt → createdAt → now). Контракт и
            // транзакцию датируем ЕЮ, а не временем приёма постбека — иначе всё
            // падает в дату вебхука и не видно в отчётах за период оплаты
            // (фидбек InSmart 23.06.2026). Приводим к таймзоне приложения.
            $paidRaw = $payload['paidAt'] ?? $payload['createdAt'] ?? null;
            $paidAt = $paidRaw
                ? \Illuminate\Support\Carbon::parse($paidRaw)->setTimezone(config('app.timezone', 'Europe/Moscow'))
                : now();

            // 4) Контракт «Активирован»
            $contractNumber = strtoupper(substr($externalId ?? uniqid('', true), 0, 8));
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
                'productName' => $productName,
                'program' => $programId,
                'programName' => $programName,
                'currency' => $this->resolveCurrencyId($payload['currency'] ?? 'RUB'),
                'ammount' => $payload['policyAmount'] ?? $payload['price'] ?? 0,
                'createDate' => $paidAt,
                'openDate' => $paidAt,
                'activated_at' => $paidAt->toDateString(),
                'comment' => 'Заказ из Insmart',
                'createdAt' => now(),
                'changedAt' => now(),
            ]);

            // 5) Транзакция на сумму СТРАХОВОЙ ПРЕМИИ (взноса), а не комиссии.
            // Раньше amount = agentCommission (комиссия), из-за чего в отчёте
            // «сумма транзакции» показывала нашу КВ, а не взнос (правка по
            // фидбеку Лены 22.06.2026). Комиссию ДС сохраняем через %ДС:
            // dsCommissionPercentage = agentCommission / premium × 100, тогда
            // калькулятор посчитает доход ДС = premium × %ДС/100 = agentCommission.
            $premium = (float) ($payload['policyAmount'] ?? $payload['price'] ?? 0);
            $commission = (float) ($payload['agentCommission'] ?? 0);
            // КВ=0 (или нет премии) → транзакция без суммы/комиссии. ВАЖНО: нельзя
            // ставить amount=премия с %ДС=0 — калькулятор трактует %ДС<=0 как
            // «не задано» и берёт тариф/дефолт (даст ненулевую комиссию). Поэтому
            // для нулевой КВ оставляем amount=0.
            if ($commission > 0 && $premium > 0) {
                $txAmount = $premium;
                $dsPercent = round($commission / $premium * 100, 4);
            } else {
                $txAmount = 0.0;
                $dsPercent = 0;
            }
            $txId = LegacyId::next('transaction');
            DB::table('transaction')->insert([
                'id' => $txId,
                'contract' => $contractId,
                'amount' => $txAmount,
                'amountRUB' => $txAmount,       // страховая премия Insmart всегда в RUB
                'dsCommissionPercentage' => $dsPercent,
                'currency' => $this->resolveCurrencyId($payload['currency'] ?? 'RUB'),
                'currencyRate' => 1,
                'date' => $paidAt,
                // dateMonth: 'YYYY-MM' (как остальные транзакции и фильтры).
                'dateMonth' => $paidAt->format('Y-m'),
                'dateYear' => $paidAt->format('Y'),
                'comment' => 'Insmart: страховая премия (КВ '.number_format($commission, 2, ',', ' ').' ₽)',
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

    /** Per spec §3.2: находим consultant по appClientId из payload. */
    private function resolveConsultant(array $payload): int
    {
        $partnerId = $payload['partnerId'] ?? $payload['appClientId'] ?? null;
        if (! $partnerId) return CommissionCalculator::UNKNOWN_CONSULTANT_ID;

        $partner = DB::table('consultant')->where('id', $partnerId)->first();
        if (! $partner) return CommissionCalculator::UNKNOWN_CONSULTANT_ID;

        return (int) $partner->id;
    }

    /**
     * САМОПОКУПКА: партнёр купил полис сам себе — контракт не может висеть на нём
     * же, он уходит его наставнику (спека ✅Инсмарт §2: «Если Партнер купил
     * страховку сам себе … система назначает автором сделки его наставника»).
     *
     * Ключевое условие — клиент и есть ПРОДАВЕЦ. Раньше проверки на это не было:
     * искали любого партнёра по контактам клиента и отдавали контракт ЕГО
     * инвайтеру, игнорируя продавца из payload. В MLM ситуация «ФК A продал
     * полис человеку, который сам ФК B» — обычная, и сделка вместе с клиентом и
     * всей цепочкой комиссий уезжала в чужую ветку.
     *
     * Проверяем по email и phone (надёжнее, чем ФИО).
     */
    private function redirectIfClientIsPartner(array $payload, int $consultantId): int
    {
        $email = $payload['clientEmail'] ?? $payload['email'] ?? null;
        $phone = $payload['clientPhone'] ?? $payload['phone'] ?? null;

        $webUser = null;
        if ($email) {
            $webUser = DB::table('WebUser')->where('email', $email)->whereNull('dateDeleted')->first();
        }
        if (! $webUser && $phone) {
            $webUser = DB::table('WebUser')->where('phone', $phone)->whereNull('dateDeleted')->first();
        }
        if (! $webUser) return $consultantId;

        $clientConsultant = DB::table('consultant')->where('webUser', $webUser->id)->first();
        if (! $clientConsultant) return $consultantId;

        // Клиент — партнёр, но НЕ продавец: обычная продажа другому ФК.
        // Сделка остаётся у продавца.
        if ((int) $clientConsultant->id !== $consultantId) {
            return $consultantId;
        }

        // Самопокупка: контракт идёт наставнику продавца.
        $inviterId = $clientConsultant->inviter
            ? (int) $clientConsultant->inviter
            : CommissionCalculator::UNKNOWN_CONSULTANT_ID;

        Log::info('InsmartIntegrationService: self-purchase, redirect contract to inviter', [
            'clientWebUserId'   => $webUser->id,
            'clientConsultantId' => $clientConsultant->id,
            'originalConsultantId' => $consultantId,
            'inviterId'         => $inviterId,
        ]);

        return $inviterId;
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

            LegacyId::syncSequence('person'); // защита от duplicate person_pkey
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

        LegacyId::syncSequence('client');
        return (int) DB::table('client')->insertGetId([
            'person' => $personId,
            'personName' => $fio,
            'consultant' => $consultantId,
            'dateCreated' => now(),
        ]);
    }

    private function resolveProductAndProgram(array $payload): array
    {
        $typeCode     = isset($payload['type']) ? (int) $payload['type'] : null;
        $companyAlias = $payload['company'] ?? null;
        $name         = $payload['productName'] ?? 'Insmart Product';
        $providerName = $payload['providerName'] ?? $payload['company'] ?? null;

        // Fast path: exact hit in mapping table.
        if ($typeCode !== null && $companyAlias) {
            $map = DB::table('insmart_type_map')
                ->where('insmart_type', $typeCode)
                ->where('insmart_company', $companyAlias)
                ->first();
            if ($map) {
                return [(int) $map->product_id, (int) $map->program_id];
            }
        }

        // Slow path: find or create product.
        // If we know the type but not this company, reuse the product for that type.
        $productId = null;
        if ($typeCode !== null) {
            $productId = (int) (DB::table('insmart_type_map')
                ->where('insmart_type', $typeCode)
                ->value('product_id') ?? 0) ?: null;
        }
        if (! $productId) {
            $productId = LegacyId::next('product');
            DB::table('product')->insert([
                'id'                 => $productId,
                'name'               => $name,
                'active'             => true,
                'visibleToCalculator' => false,
                'visibleToResident'  => false,
                'noComission'        => false,
                'publish_status'     => 'draft',
            ]);
        }

        // Find or create program: match by product + company alias (providerName).
        $programId = DB::table('program')
            ->where('product', $productId)
            ->where('providerName', $providerName)
            ->value('id');
        if (! $programId) {
            $programId = LegacyId::next('program');
            DB::table('program')->insert([
                'id'           => $programId,
                'product'      => $productId,
                'name'         => $name . ($providerName ? " ({$providerName})" : ''),
                'productName'  => $name,
                'providerName' => $providerName,
                'active'       => true,
            ]);
        }

        // Auto-register so the next identical webhook hits the fast path.
        if ($typeCode !== null && $companyAlias) {
            DB::table('insmart_type_map')->insertOrIgnore([
                'insmart_type'    => $typeCode,
                'insmart_company' => $companyAlias,
                'product_id'      => $productId,
                'program_id'      => $programId,
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
