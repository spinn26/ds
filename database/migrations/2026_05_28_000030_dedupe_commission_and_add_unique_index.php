<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Чистим ранее накопленные дубли в `commission` по тройке
 * (transaction, consultant, chainOrder) и навешиваем partial unique index,
 * чтобы новые дубли невозможно было создать на уровне БД.
 *
 * Корневая причина дублей (фикс в коде — 2026-05-28): два параллельных
 * вызова CommissionCalculator::calculateForTransaction для одной и той же
 * транзакции (напр. два «Рассчитать» в истории импортов) под Read
 * Committed не видели свежие INSERT'ы друг друга в шаге soft-delete и
 * оба вставляли полную цепочку. После добавления `lockForUpdate()` на
 * parent-строку транзакции это уже невозможно — индекс защищает от
 * регрессии и от любых других каналов записи в `commission`.
 *
 * Strategy:
 *   1. Софт-удаляем все, кроме строки с max(id) в каждой группе.
 *   2. Создаём UNIQUE INDEX … WHERE "deletedAt" IS NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 5-минутный лимит на сессию — на проде ~776 строк к апдейту,
        // дефолтные 30s могут зацепить.
        DB::statement("SET LOCAL statement_timeout = '300s'");

        // Софт-удаляем все «не-максимальные» дубли. Группируем по
        // (transaction, consultant, chainOrder) среди active-строк
        // и оставляем строку с наибольшим id.
        DB::statement(<<<'SQL'
            WITH dupes AS (
                SELECT id,
                       ROW_NUMBER() OVER (
                           PARTITION BY transaction, consultant, "chainOrder"
                           ORDER BY id DESC
                       ) AS rn
                FROM commission
                WHERE "deletedAt" IS NULL
                  AND transaction IS NOT NULL
            )
            UPDATE commission c
            SET "deletedAt" = NOW()
            FROM dupes d
            WHERE c.id = d.id AND d.rn > 1
        SQL);

        // Partial unique index. Имя короткое и однозначное — других
        // unique-ограничений по этим колонкам на commission нет.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS commission_tx_consultant_chain_active_uniq
            ON commission (transaction, consultant, "chainOrder")
            WHERE "deletedAt" IS NULL AND transaction IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Откатываем только индекс — soft-delete'ы НЕ восстанавливаем
        // (они корректны: лишние дубли всё равно мешали отчётам).
        DB::statement('DROP INDEX IF EXISTS commission_tx_consultant_chain_active_uniq');
    }
};
