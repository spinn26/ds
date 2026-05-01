<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Пересинхронизация агрегатов consultantBalance.
 *
 * После backfill миграций (000020 + 000030) commission.amountRUB заполнен
 * для 144 638 строк, которые раньше были NULL. Но consultantBalance —
 * legacy-агрегат на (consultant, dateMonth) — был посчитан Directual'ом
 * до этого backfill'а, поэтому accruedTransactional / accruedTotal /
 * totalPayable хранят старые (заниженные) значения.
 *
 * Эта миграция пересчитывает три агрегата по реальным данным:
 *   accruedTransactional ← SUM(commission.amountRUB WHERE type='transaction')
 *   accruedNonTransactional ← SUM(commission.amountRUB WHERE type='nonTransactional')
 *   accruedTotal ← accruedTransactional + accruedNonTransactional + accruedPool
 *   totalPayable ← balance + accruedTotal
 *   remaining ← totalPayable - payed
 *
 * Идемпотентна — можно перезапускать.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Транзакционные комиссии по (consultant, dateMonth).
        DB::statement('
            UPDATE "consultantBalance" cb
            SET "accruedTransactional" = COALESCE(sub.s, 0)
            FROM (
                SELECT consultant, "dateMonth", SUM(COALESCE("amountRUB", 0)) AS s
                FROM commission
                WHERE type = \'transaction\' AND "deletedAt" IS NULL
                GROUP BY consultant, "dateMonth"
            ) sub
            WHERE cb.consultant = sub.consultant AND cb."dateMonth" = sub."dateMonth"
        ');

        // Прочие начисления (включая legacy nonTransactional).
        DB::statement('
            UPDATE "consultantBalance" cb
            SET "accruedNonTransactional" = COALESCE(sub.s, 0)
            FROM (
                SELECT consultant, "dateMonth", SUM(COALESCE("amountRUB", 0)) AS s
                FROM commission
                WHERE type = \'nonTransactional\' AND "deletedAt" IS NULL
                GROUP BY consultant, "dateMonth"
            ) sub
            WHERE cb.consultant = sub.consultant AND cb."dateMonth" = sub."dateMonth"
        ');

        // accruedTotal ← сумма трёх компонентов.
        DB::statement('
            UPDATE "consultantBalance"
            SET "accruedTotal" = COALESCE("accruedTransactional", 0)
                                + COALESCE("accruedNonTransactional", 0)
                                + COALESCE("accruedPool", 0)
        ');

        // totalPayable ← перенесённое сальдо + начислено в этом месяце.
        DB::statement('
            UPDATE "consultantBalance"
            SET "totalPayable" = COALESCE(balance, 0) + COALESCE("accruedTotal", 0)
        ');

        // remaining ← totalPayable - payed.
        $updated = DB::statement('
            UPDATE "consultantBalance"
            SET remaining = COALESCE("totalPayable", 0) - COALESCE(payed, 0)
        ');

        echo "  Resynced consultantBalance aggregates" . PHP_EOL;
    }

    public function down(): void
    {
        // Откат не делаем — иначе вернёмся к рассинхронизированным данным.
    }
};
