<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Пересчёт contract.accrual_forecast — «прогноз начисления».
 *
 * Правила (Lena, 16.06.2026):
 *  - только для статуса «Активирован» (id=1); в остальных статусах — NULL;
 *  - если по контракту НЕТ транзакции → прогноз = месяц активации
 *    (contract.activated_at) + products_catalog.accrual_forecast_months;
 *  - если транзакция ЕСТЬ → подменяем фактической датой начисления
 *    (минимальная transaction.date по контракту);
 *  - у старых активированных без activated_at → NULL (по требованию).
 *
 * Поле системное: руками не редактируется, обслуживается этим сервисом
 * (инлайн при сохранении контракта + ночной командой contracts:recompute-
 * accrual-forecast, которая подхватывает появившиеся транзакции).
 */
class AccrualForecastService
{
    /** Пересчитать для одного контракта (после смены статуса/сохранения). */
    public function recomputeForContract(int $contractId): void
    {
        $this->run($contractId);
    }

    /** Массовый пересчёт (ночная команда). */
    public function recomputeAll(): int
    {
        return $this->run(null);
    }

    private function run(?int $contractId): int
    {
        // Месяцы прогноза по продукту контракта: сначала по legacy_product_id
        // (contract.product → products_catalog.legacy_product_id), иначе по
        // имени (contract.productName → products_catalog.name). Нет матча → 0.
        $sql = <<<'SQL'
            UPDATE contract AS c
            SET accrual_forecast = CASE
                WHEN c.status <> 1 THEN NULL
                WHEN EXISTS (
                    SELECT 1 FROM "transaction" t
                    WHERE t.contract = c.id AND t."deletedAt" IS NULL
                ) THEN (
                    SELECT MIN(t.date)::date FROM "transaction" t
                    WHERE t.contract = c.id AND t."deletedAt" IS NULL
                )
                WHEN COALESCE(c."openDate", c.activated_at) IS NOT NULL THEN (
                    -- Якорь — openDate (реальная дата активации контракта, по ней же
                    -- строится слой «Активировано» в матрице). activated_at брать
                    -- НЕЛЬЗЯ: он ставится в now() в момент переключения статуса в
                    -- системе, поэтому у июньских контрактов, отмеченных активными
                    -- лишь в июле, activated_at=июль → прогноз ошибочно уезжал на
                    -- +1 месяц (июнь-активация N=1 давала август вместо июля).
                    -- Фолбэк на activated_at — только если openDate пуст.
                    date_trunc('month', COALESCE(c."openDate", c.activated_at)::timestamp)
                    + (COALESCE(
                        (SELECT pc.accrual_forecast_months FROM products_catalog pc
                          WHERE pc.legacy_product_id = c.product LIMIT 1),
                        (SELECT pc2.accrual_forecast_months FROM products_catalog pc2
                          WHERE pc2.name = c."productName" LIMIT 1),
                        0
                    ) || ' months')::interval
                  )::date
                ELSE NULL
            END
            WHERE c."deletedAt" IS NULL
        SQL;

        $bindings = [];
        if ($contractId !== null) {
            $sql .= ' AND c.id = ?';
            $bindings[] = $contractId;
        }

        return DB::affectingStatement($sql, $bindings);
    }
}
