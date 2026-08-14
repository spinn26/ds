<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Автогигиена БД: не даёт технадолгу копиться заново после разовой
 * оптимизации 2026-08. Убирает мусор, который порождают отчёты/фиксы/мониторинг:
 *   - report_*_for_struct_* — генерируемые снимки отчётов (пересобираются);
 *   - *_backup_YYYYMMDD / _bak_* / june_purge_* — разовые бэкапы прошлых фиксов;
 *   - health_check_result_history_items — мониторинг старше N дней (ретеншн).
 *
 * БЕЗОПАСНО: dry-run по умолчанию (только показывает). Дропает ТОЛЬКО таблицы
 * по строгим junk-паттернам, ТОЛЬКО без FK-зависимостей и ТОЛЬКО те, которых
 * нет в PROTECTED. Доменные/логи не трогает.
 *
 * ⚠ История: паттерн `^report_` матчил боевую `report_archive` (входящих FK у
 * неё нет, проверка пропускала) — и `--apply` сносил её вместе с архивом
 * отчётов. Отсюда явный список PROTECTED и требование суффикса-снимка.
 */
class DbHousekeep extends Command
{
    protected $signature = 'db:housekeep
        {--apply : выполнить (иначе dry-run)}
        {--health-days=14 : хранить дней истории health_check}
        {--backup-days=30 : дропать backup-таблицы старше N дней (по дате в имени)}';

    protected $description = 'Ретеншн/чистка мусора БД (снимки отчётов, старые бэкапы, история мониторинга)';

    /**
     * Живые таблицы, чьи имена попадают под junk-паттерны. Дропать нельзя ни
     * при каких условиях — отсутствие входящих FK тут ничего не доказывает.
     */
    private const PROTECTED_TABLES = [
        'report_archive',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $healthDays = max(1, (int) $this->option('health-days'));
        $backupDays = max(1, (int) $this->option('backup-days'));
        $mode = $apply ? 'ПРИМЕНЕНО' : 'DRY-RUN';

        // 1) report_* СНИМКИ — только генерируемые «..._for_struct_...» /
        // «report_<что-то>_YYYYMMDD». Голый `^report_` матчил живую
        // report_archive, поэтому одного префикса недостаточно.
        $reports = $this->junkTables(
            "s.relname ~ '^report_' AND (s.relname ~ '_for_struct' OR s.relname ~ '_20\\d{6}$' OR s.relname ~ '_tmp$')"
        );
        $this->dropTables($reports, 'report_* снимки', $apply);

        // 2) Оставшиеся backup-таблицы старше N дней (дата YYYYMMDD/YYYY_MM_DD в имени).
        $backups = $this->junkTables(
            "(s.relname ~ 'backup' OR s.relname ~ '_bak' OR s.relname ~ '^june_purge_' OR s.relname ~ '^_(reorg|dnorm|inviter|terminated|perkulimov|clientdenorm|boundary|shem|term_compress|c1898)')"
        );
        $oldBackups = [];
        $undated = [];
        foreach ($backups as $t) {
            if (preg_match('/(20\d{2})[_-]?(\d{2})[_-]?(\d{2})/', $t, $m)) {
                $tableDate = "{$m[1]}-{$m[2]}-{$m[3]}";
                if ($tableDate < now()->subDays($backupDays)->toDateString()) {
                    $oldBackups[] = $t;
                }
            } else {
                // ⚠ Раньше такие дропались безусловно, игнорируя --backup-days:
                // свежая копия вида `contract_backup`, снятая перед правкой
                // сегодня, умирала на первом же --apply, а в выводе при этом
                // значилось «старше N дн». Возраст без даты в имени берём из
                // системного каталога, а не «считаем мусором по умолчанию».
                $undated[] = $t;
            }
        }

        foreach ($undated as $t) {
            $age = $this->tableAgeInDays($t);
            if ($age === null) {
                $this->warn("    · {$t}: возраст не определён — пропущено (удалите вручную)");
                continue;
            }
            if ($age > $backupDays) {
                $oldBackups[] = $t;
            } else {
                $this->line("    · {$t}: {$age} дн — моложе {$backupDays}, оставлено");
            }
        }

        $this->dropTables($oldBackups, "backup-таблицы старше {$backupDays} дн", $apply);

        // 3) Ретеншн health_check (прун + возврат места).
        if (DB::getSchemaBuilder()->hasTable('health_check_result_history_items')) {
            $cut = now()->subDays($healthDays)->toDateTimeString();
            $old = (int) DB::table('health_check_result_history_items')
                ->where('created_at', '<', $cut)->count();
            if ($old > 0) {
                $this->line("  health_check: удалить {$old} строк старше {$healthDays} дн");
                if ($apply) {
                    DB::table('health_check_result_history_items')->where('created_at', '<', $cut)->delete();
                    DB::statement('VACUUM (ANALYZE) health_check_result_history_items');
                }
            } else {
                $this->line('  health_check: нечего чистить');
            }
        }

        $this->info("{$mode} — гигиена БД завершена" . ($apply ? '' : ' (--apply чтобы выполнить)'));

        return self::SUCCESS;
    }

    /**
     * Возраст таблицы в днях по времени создания её файла в каталоге PG.
     * null — если определить не удалось (тогда таблицу не трогаем).
     */
    private function tableAgeInDays(string $table): ?int
    {
        try {
            $row = DB::selectOne(
                "SELECT EXTRACT(DAY FROM now() - (pg_stat_file(
                     pg_relation_filepath(?::regclass)
                 )).modification)::int AS days",
                ['public."' . str_replace('"', '', $table) . '"']
            );

            return $row && $row->days !== null ? (int) $row->days : null;
        } catch (\Throwable $e) {
            // pg_stat_file требует прав суперпользователя/pg_read_server_files.
            return null;
        }
    }

    /** Junk-таблицы public по условию, у которых НЕТ FK-зависимостей (безопасно дропать). */
    private function junkTables(string $whereRaw): array
    {
        $tables = DB::table('pg_stat_user_tables as s')
            ->join('pg_class as c', 'c.oid', '=', 's.relid')
            ->where('s.schemaname', 'public')
            ->whereRaw($whereRaw)
            ->whereRaw("NOT EXISTS (SELECT 1 FROM pg_constraint k WHERE k.confrelid=c.oid AND k.contype='f')")
            ->pluck('s.relname')
            ->all();

        return array_values(array_filter(
            $tables,
            fn ($t) => ! in_array($t, self::PROTECTED_TABLES, true)
        ));
    }

    /**
     * Зависимые объекты (вью/матвью), которые унесёт CASCADE. Показываем их до
     * дропа: раньше они молча исчезали и в dry-run не фигурировали вовсе.
     *
     * @return array<int,string>
     */
    private function cascadeDependents(string $table): array
    {
        try {
            $rows = DB::select(
                "SELECT DISTINCT dependent.relname AS name
                   FROM pg_depend d
                   JOIN pg_rewrite r ON r.oid = d.objid
                   JOIN pg_class dependent ON dependent.oid = r.ev_class
                   JOIN pg_class source ON source.oid = d.refobjid
                  WHERE source.relname = ?
                    AND dependent.relname <> source.relname",
                [$table]
            );

            return array_map(fn ($r) => $r->name, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @param array<int,string> $tables */
    private function dropTables(array $tables, string $label, bool $apply): void
    {
        if (empty($tables)) {
            $this->line("  {$label}: нет");
            return;
        }
        $this->line("  {$label}: " . count($tables) . ' табл');
        foreach ($tables as $t) {
            $this->line("    · {$t}");
            foreach ($this->cascadeDependents($t) as $dep) {
                $this->warn("        ⚠ CASCADE унесёт зависимый объект: {$dep}");
            }
            if ($apply) {
                DB::statement('DROP TABLE IF EXISTS public.' . '"' . str_replace('"', '', $t) . '" CASCADE');
            }
        }
    }
}
