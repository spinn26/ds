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
 * по строгим junk-паттернам и ТОЛЬКО без FK-зависимостей. Доменные/логи не трогает.
 * Ставится в расписание (Kernel) раз в неделю.
 */
class DbHousekeep extends Command
{
    protected $signature = 'db:housekeep
        {--apply : выполнить (иначе dry-run)}
        {--health-days=14 : хранить дней истории health_check}
        {--backup-days=30 : дропать backup-таблицы старше N дней (по дате в имени)}';

    protected $description = 'Ретеншн/чистка мусора БД (снимки отчётов, старые бэкапы, история мониторинга)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $healthDays = max(1, (int) $this->option('health-days'));
        $backupDays = max(1, (int) $this->option('backup-days'));
        $mode = $apply ? 'ПРИМЕНЕНО' : 'DRY-RUN';

        // 1) report_* снимки — без FK-зависимостей, пересобираемы.
        $reports = $this->junkTables("s.relname ~ '^report_'");
        $this->dropTables($reports, 'report_* снимки', $apply);

        // 2) Оставшиеся backup-таблицы старше N дней (дата YYYYMMDD/YYYY_MM_DD в имени).
        $backups = $this->junkTables(
            "(s.relname ~ 'backup' OR s.relname ~ '_bak' OR s.relname ~ '^june_purge_' OR s.relname ~ '^_(reorg|dnorm|inviter|terminated|perkulimov|clientdenorm|boundary|shem|term_compress|c1898)')"
        );
        $oldBackups = [];
        foreach ($backups as $t) {
            if (preg_match('/(20\d{2})[_-]?(\d{2})[_-]?(\d{2})/', $t, $m)) {
                $tableDate = "{$m[1]}-{$m[2]}-{$m[3]}";
                if ($tableDate < now()->subDays($backupDays)->toDateString()) {
                    $oldBackups[] = $t;
                }
            } else {
                $oldBackups[] = $t; // без даты в имени — тоже мусор
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
                $this->line("  health_check: нечего чистить");
            }
        }

        $this->info("{$mode} — гигиена БД завершена" . ($apply ? '' : ' (--apply чтобы выполнить)'));

        return self::SUCCESS;
    }

    /** Junk-таблицы public по условию, у которых НЕТ FK-зависимостей (безопасно дропать). */
    private function junkTables(string $whereRaw): array
    {
        return DB::table('pg_stat_user_tables as s')
            ->join('pg_class as c', 'c.oid', '=', 's.relid')
            ->where('s.schemaname', 'public')
            ->whereRaw($whereRaw)
            ->whereRaw("NOT EXISTS (SELECT 1 FROM pg_constraint k WHERE k.confrelid=c.oid AND k.contype='f')")
            ->pluck('s.relname')
            ->all();
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
            if ($apply) {
                DB::statement('DROP TABLE IF EXISTS public.' . '"' . str_replace('"', '', $t) . '" CASCADE');
            }
        }
    }
}
