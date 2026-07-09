<?php

namespace App\Console\Commands;

use App\Services\PlatformSheetExporter;
use Illuminate\Console\Command;

/**
 * Выгрузка платформы в Google-таблицу (Контракты / Клиенты / Консультанты).
 * Постоянная синхронизация: upsert по ID (инкремент по changedAt).
 *
 *   php artisan sheets:export-platform          # инкремент (с прошлого прогона)
 *   php artisan sheets:export-platform --full   # полная перезаливка (игнор watermark)
 *
 * Ставится в расписание (routes/console.php) для непрерывной выгрузки.
 */
class ExportPlatformToSheet extends Command
{
    protected $signature = 'sheets:export-platform {--full : полная перезаливка (игнорировать watermark)}';

    protected $description = 'Выгрузка Контракты/Клиенты/Консультанты в Google-таблицу (upsert по ID)';

    public function handle(PlatformSheetExporter $exporter): int
    {
        $full = (bool) $this->option('full');
        $this->info(($full ? '[FULL] ' : '') . 'Выгрузка платформы в Google Sheets...');

        try {
            $res = $exporter->exportAll($full);
        } catch (\Throwable $e) {
            $this->error('Ошибка выгрузки: ' . $e->getMessage());
            return self::FAILURE;
        }

        foreach ($res as $tab => $r) {
            $this->line(sprintf('  %s: обновлено %d, добавлено %d%s',
                $tab, $r['updated'], $r['appended'],
                $r['since'] ? " (с {$r['since']})" : ' (полная)'));
        }
        $this->info('Готово.');

        return self::SUCCESS;
    }
}
