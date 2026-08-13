<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Удаление артефактов конструктора отчётов Directual — таблиц вида
 * report_<номер>_for_struct_<сущность>.
 *
 * Прежняя платформа сохраняла результат каждого построенного отчёта отдельной
 * таблицей. Наша их не читает: отчёты считаются заново по живым данным, а
 * первоисточник (transaction, commission, contract, qualificationLog) на месте.
 * На проде это 89 таблиц, 110 860 строк, 23 МБ.
 *
 * ⚠ Шаблон СТРОГИЙ: только report_<цифры>_for_struct_<буквы>. Движок отчётов
 * Directual — reportGenerator, reportLogs, report_archive — намеренно НЕ
 * трогаем: в report_archive лежит 75 строк, и это не слепок, а его данные.
 * ⚠ Отдельный дамп именно этих таблиц снят до удаления:
 *   /root/backups/newds_report_artifacts_20260813.dump (2,7 МБ, 89 таблиц)
 *
 * Внешних ключей на эти таблицы нет — проверено перед удалением.
 */
return new class extends Migration
{
    private const PATTERN = '^report_[0-9]+_for_struct_[A-Za-z]+$';

    public function up(): void
    {
        $tables = DB::select(<<<SQL
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind = 'r' AND n.nspname = 'public' AND c.relname ~ ?
            SQL, [self::PATTERN]);

        foreach ($tables as $t) {
            DB::statement('DROP TABLE IF EXISTS "'.$t->relname.'"');
        }
    }

    public function down(): void
    {
        // Восстанавливаются только из дампа: это данные прежней платформы,
        // миграциями они никогда не создавались.
    }
};
