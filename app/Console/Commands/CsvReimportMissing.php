<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Точечный re-import: читает CSV и вставляет только те строки, ID
 * которых отсутствуют в БД. Используется чтобы дочитать «потерянные»
 * при предыдущем fgets-импорте строки (см. коммит 817b2e64a).
 *
 *   php artisan csv:reimport-missing transaction
 *   php artisan csv:reimport-missing person
 *   php artisan csv:reimport-missing transaction --dry-run
 */
class CsvReimportMissing extends Command
{
    protected $signature = 'csv:reimport-missing
                            {table : Имя таблицы (совпадает с CSV-файлом)}
                            {--path=Db : Корневая директория CSV}
                            {--dry-run : Только вывести список потерянных id}';

    protected $description = 'Догрузить в БД только те строки CSV, чьи id отсутствуют в таблице.';

    public function handle(): int
    {
        $tableName = $this->argument('table');
        $basePath = base_path($this->option('path'));
        $dryRun = (bool) $this->option('dry-run');

        // Найти CSV-файл (рекурсивно).
        $csvPath = $this->findCsv($basePath, $tableName . '.csv');
        if (! $csvPath) {
            $this->error("CSV not found for table '{$tableName}' under {$basePath}");
            return self::FAILURE;
        }

        if (! Schema::hasTable($tableName)) {
            $this->error("Таблица {$tableName} не существует в БД");
            return self::FAILURE;
        }

        // Парсим CSV корректно (fgetcsv с многострочными полями).
        $f = fopen($csvPath, 'r');
        $headers = fgetcsv($f, 0, ';');
        $headers = array_map(fn ($h) => trim($h, "\xEF\xBB\xBF \t\""), $headers);
        $idCol = array_search('id', $headers, true);
        if ($idCol === false) {
            fclose($f);
            $this->error('Колонка id не найдена в CSV');
            return self::FAILURE;
        }

        $byId = [];
        while (($row = fgetcsv($f, 0, ';')) !== false) {
            if ($row === [null]) continue;
            $id = (int) ($row[$idCol] ?? 0);
            if ($id <= 0) continue;
            $byId[$id] = $row;
        }
        fclose($f);

        // Существующие id в БД.
        $existing = DB::table($tableName)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $existingSet = array_flip($existing);

        // Найти missing.
        $missing = [];
        foreach ($byId as $id => $_) {
            if (! isset($existingSet[$id])) $missing[] = $id;
        }
        sort($missing);

        $this->info(sprintf(
            'CSV: %d строк, БД: %d строк, missing: %d',
            count($byId), count($existing), count($missing)
        ));

        if (! $missing) {
            $this->info('Нечего догружать — расхождений нет.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('Missing IDs: ' . implode(', ', array_slice($missing, 0, 100))
                . (count($missing) > 100 ? ' ...' : ''));
            return self::SUCCESS;
        }

        // Подготовим типы колонок для coerce.
        $columnTypes = [];
        foreach (DB::select('
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = ?
        ', [$tableName]) as $c) {
            $columnTypes[$c->column_name] = $c->data_type;
        }

        // Какие колонки CSV есть в DB.
        $dataColumns = array_intersect($headers, array_keys($columnTypes));

        // Отключаем FK на сессию — чтобы вставка прошла, даже если ссылочные
        // строки тоже потеряны.
        DB::statement('SET session_replication_role = replica');

        $batch = [];
        $ok = 0;
        $fail = 0;
        $firstError = null;
        foreach ($missing as $id) {
            $row = $byId[$id];
            $insert = [];
            foreach ($headers as $i => $col) {
                if (! in_array($col, $dataColumns, true)) continue;
                $val = $row[$i] ?? null;
                $insert[$col] = $this->coerce($val, $columnTypes[$col] ?? null);
            }
            $batch[] = $insert;
            if (count($batch) >= 100) {
                [$o, $f, $e] = $this->insertBatch($tableName, $batch);
                $ok += $o; $fail += $f;
                if ($e && ! $firstError) $firstError = $e;
                $batch = [];
            }
        }
        if ($batch) {
            [$o, $f, $e] = $this->insertBatch($tableName, $batch);
            $ok += $o; $fail += $f;
            if ($e && ! $firstError) $firstError = $e;
        }

        $this->info("Inserted: {$ok}, failed: {$fail}");
        if ($firstError) $this->warn('First error: ' . $firstError);

        // Sync sequence чтобы будущие insert не конфликтовали.
        $seq = DB::scalar('SELECT pg_get_serial_sequence(?, ?)', ['"' . $tableName . '"', 'id']);
        if ($seq) {
            $maxId = (int) DB::scalar("SELECT COALESCE(MAX(id), 0) FROM \"{$tableName}\"");
            DB::statement("SELECT setval('{$seq}'::regclass, ?, true)", [max($maxId, 1)]);
        }

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function findCsv(string $base, string $filename): ?string
    {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($rii as $f) {
            if ($f->isFile() && $f->getFilename() === $filename) {
                return $f->getPathname();
            }
        }
        return null;
    }

    private function insertBatch(string $tbl, array $rows): array
    {
        try {
            DB::table($tbl)->insert($rows);
            return [count($rows), 0, null];
        } catch (\Throwable $e) {
            $ok = 0; $fail = 0; $first = null;
            foreach ($rows as $r) {
                try { DB::table($tbl)->insert($r); $ok++; }
                catch (\Throwable $e2) {
                    $fail++;
                    if (! $first) $first = mb_substr($e2->getMessage(), 0, 220);
                }
            }
            return [$ok, $fail, $first];
        }
    }

    /** Тот же coerce что в ImportCsvData — для совместимости форматов Directual. */
    private function coerce(?string $val, ?string $dbType): mixed
    {
        if ($val === null || $val === '') return null;
        if ($val === 'true')  return true;
        if ($val === 'false') return false;

        $isDateType = in_array($dbType, [
            'timestamp without time zone', 'timestamp with time zone',
            'date', 'time', 'time without time zone',
        ], true);
        $isIntType = in_array($dbType, [
            'integer', 'bigint', 'smallint', 'numeric', 'real', 'double precision',
        ], true);
        $isUuid = $dbType === 'uuid';
        $isJson = in_array($dbType, ['json', 'jsonb'], true);

        // Directual unix-millis → timestamp
        if ($isDateType && ctype_digit($val) && strlen($val) >= 12) {
            return date('Y-m-d H:i:s', (int) substr($val, 0, 10));
        }
        // CSV-array "232,233,240" в integer колонку → null
        if (($isIntType || $isUuid) && str_contains($val, ',')) return null;

        // Невалидный JSON (например, заэкранированные строки от внешних
        // webhook-логов с битыми кавычками) — лучше null чем сломанный insert.
        if ($isJson) {
            json_decode($val);
            if (json_last_error() !== JSON_ERROR_NONE) return null;
        }

        return $val;
    }
}
