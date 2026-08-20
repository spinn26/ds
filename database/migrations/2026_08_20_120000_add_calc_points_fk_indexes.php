<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы под ВСЕ внешние ключи calculationConsultantPoints.
 *
 * На таблице стоял только PK, а FK-колонок шесть — и каждая проверка ссылки
 * шла seq scan'ом. Больнее всего это било по удалению квалификаций:
 *
 *   delete from "qualificationLog" where "consultant" in (…сотни id…)
 *   → на КАЖДУЮ удаляемую строку Postgres проверяет
 *     calculationConsultantPoints.qualification и ."qualificationLog"
 *   → seq scan × число строк → statement timeout (лог прода: 17.08 ×2, 19.08 ×2).
 *
 * ⚠ Миграция 2026_08_19 (индексы на самоссылки qualificationLog) закрыла
 * только ПРЯМЫЕ ссылки внутри самого журнала; обратные — из этой таблицы —
 * остались голыми, и таймаут воспроизводился.
 *
 * Индексируем все шесть, а не две «виноватые»: таблица маленькая (9,8 тыс.
 * строк, 1,7 МБ), цена лишних индексов на вставке пренебрежима, а каскадные
 * проверки при удалении партнёра/контракта/транзакции ходят теми же путями.
 *
 * CONCURRENTLY — вне транзакции, как в 2026_08_14_001000_add_missing_fk_indexes.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLE = 'calculationConsultantPoints';

    /** @var list<array{0:string,1:string}> [индекс, колонка] */
    private array $indexes = [
        ['calcpoints_qualification_idx', 'qualification'],
        ['calcpoints_quallog_idx', 'qualificationLog'],
        ['calcpoints_consultant_idx', 'consultant'],
        ['calcpoints_contracts_idx', 'contracts'],
        ['calcpoints_transaction_idx', 'transaction'],
        ['calcpoints_contest_idx', 'contest'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ($this->indexes as [$index, $column]) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }
            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON public."%s" ("%s")',
                $index,
                self::TABLE,
                $column
            ));
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$index]) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS public.{$index}");
        }
    }
};
