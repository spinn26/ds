<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Индексы на самоссылки qualificationLog — иначе финализация месяца падает.
 *
 * ⚠ Инцидент 19.08.2026. Кнопка «Пересчитать штрафы» за июль отвечала «Не
 * удалось применить штрафы», в логе — statement timeout на запросе:
 *
 *   delete from "qualificationLog" where consultant in (~1600 id) and date = ...
 *   CONTEXT: SELECT 1 FROM ONLY "qualificationLog" x
 *            WHERE $1 = "qualificationLogPrevious" FOR KEY SHARE OF x
 *
 * У таблицы два внешних ключа НА СЕБЯ — qualificationLogPrevious и
 * firstLineBranches, — и ни одного индекса по ним. Postgres при каждом
 * удалении проверяет, не ссылается ли кто-то на удаляемую строку, и без
 * индекса это полный проход по таблице: 46 тысяч строк × 1600 удалений.
 *
 * Тот же таймаут объясняет и половинчатый прогон 17.08: снимки кандидатов
 * записались (они идут построчно), а массовое обновление остальных упало —
 * из-за чего НГП у 147 партнёров остался июньским.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS qualificationlog_prev_idx
            ON "qualificationLog" ("qualificationLogPrevious")
            WHERE "qualificationLogPrevious" IS NOT NULL');

        DB::statement('CREATE INDEX IF NOT EXISTS qualificationlog_firstline_idx
            ON "qualificationLog" ("firstLineBranches")
            WHERE "firstLineBranches" IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS qualificationlog_prev_idx');
        DB::statement('DROP INDEX IF EXISTS qualificationlog_firstline_idx');
    }
};
