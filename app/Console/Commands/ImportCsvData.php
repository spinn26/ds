<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportCsvData extends Command
{
    protected $signature = 'csv:import {--path=Db} {--table=} {--force} {--dry-run} {--skip-heavy}';
    protected $description = 'Import CSV files from Db/ folder into PostgreSQL tables';

    /**
     * Тяжёлые исторические таблицы (N8N логи, отчёты Directual'а),
     * которые не нужны локально для разработки. Включается флагом
     * --skip-heavy. Без него — импортируется всё.
     */
    private const HEAVY_TABLES = [
        'reportGenerator',          // 446 MB, 9.2M rows — XLSX-выгрузки
        'exportLogTransactions',    // 107 MB, 2.7M rows — N8N лог
        'exportLogQualificationLog',// 32 MB, 749k rows
        'exportLogContract',        // 30 MB, 718k rows
        'exportLogConsultant',      // 45 MB, 664k rows
        'exportLogClients',         // 6 MB, 111k rows
        'SystemMessage',            // 16 MB, 81k rows — application log
    ];

    public function handle(): int
    {
        $basePath = base_path($this->option('path'));
        $targetTable = $this->option('table');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        if (! is_dir($basePath)) {
            $this->error("Directory not found: {$basePath}");
            return 1;
        }

        // Отключаем FK constraints на эту сессию — иначе строки commission/
        // contract/transaction проваливаются с FK violation, потому что их
        // CSV-партнёры (consultant, person и т.д.) ещё не загружены к этому
        // моменту.
        if ($force && ! $dryRun) {
            DB::statement('SET session_replication_role = replica');
            $this->line('FK constraints disabled for this session (replica role)');
        }

        // Find all CSV files
        $csvFiles = [];
        $this->findCsvFiles($basePath, $csvFiles);

        $this->info("Found " . count($csvFiles) . " CSV files");

        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $rowsInserted = 0;
        $rowsFailed = 0;

        $skipHeavy = $this->option('skip-heavy');

        foreach ($csvFiles as $filePath) {
            $tableName = pathinfo($filePath, PATHINFO_FILENAME);

            // Skip if targeting specific table
            if ($targetTable && $tableName !== $targetTable) {
                continue;
            }

            // --skip-heavy: пропускаем большие исторические логи (446 MB
            // reportGenerator, миллионы строк exportLog*, SystemMessage),
            // которые не нужны для разработки локально.
            if ($skipHeavy && in_array($tableName, self::HEAVY_TABLES, true)) {
                $this->line("  SKIP {$tableName} (heavy historical log, --skip-heavy)");
                $skipped++;
                continue;
            }

            $tableExists = Schema::hasTable($tableName);

            if ($tableExists && ! $force) {
                $count = DB::table($tableName)->count();
                if ($count > 0) {
                    $this->line("  SKIP {$tableName} (exists, {$count} rows)");
                    $skipped++;
                    continue;
                }
            }

            $this->info("Importing: {$tableName} from {$filePath}");

            if ($dryRun) {
                $this->line("  DRY-RUN: would import {$tableName}");
                continue;
            }

            try {
                [$ok, $failed] = $this->importCsv($filePath, $tableName, $tableExists, $force);
                $rowsInserted += $ok;
                $rowsFailed += $failed;
                $msg = "  OK: {$ok} rows imported into {$tableName}";
                if ($failed > 0) {
                    $msg .= " (⚠ {$failed} failed — see above)";
                }
                $this->info($msg);
                $imported++;
            } catch (\Exception $e) {
                $this->error("  FAIL {$tableName}: " . $e->getMessage());
                $errors++;
            }
        }

        if ($force && ! $dryRun) {
            DB::statement('SET session_replication_role = origin');
        }

        $this->newLine();
        $this->info("Done: {$imported} imported, {$skipped} skipped, {$errors} errors");
        $this->info("Total rows: {$rowsInserted} inserted, {$rowsFailed} failed");

        return ($errors > 0 || $rowsFailed > 0) ? 1 : 0;
    }

    private function findCsvFiles(string $dir, array &$files): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->findCsvFiles($path, $files);
            } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'csv') {
                $files[] = $path;
            }
        }
    }

    /**
     * @return array{0:int,1:int}  [ok, failed]
     */
    private function importCsv(string $filePath, string $tableName, bool $tableExists, bool $force): array
    {
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        // Read header line
        $headerLine = fgets($handle);
        if (! $headerLine) {
            fclose($handle);
            throw new \RuntimeException("Empty file");
        }

        // strip BOM
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
        $headers = str_getcsv(trim($headerLine), ';');

        // Filter out Directual system columns (@dateCreated, @dateChanged)
        $systemColumns = [];
        $dataColumns = [];
        foreach ($headers as $i => $h) {
            $h = trim($h);
            if (str_starts_with($h, '@')) {
                $systemColumns[$i] = $h;
            } else {
                $dataColumns[$i] = $h;
            }
        }

        // Create table if not exists
        if (! $tableExists) {
            $this->createTable($tableName, $dataColumns);
            $tableExists = true;
        }

        // Если таблица уже существует — оставляем только те колонки CSV,
        // которые реально присутствуют в DB. Иначе INSERT упадёт на любом
        // старом столбце (типа "user", "deletedAt" и т.п., которых давно нет).
        $existingColumns = Schema::getColumnListing($tableName);
        $existingSet = array_flip($existingColumns);
        $skippedColumns = [];
        foreach ($dataColumns as $i => $col) {
            if (! isset($existingSet[$col])) {
                $skippedColumns[] = $col;
                unset($dataColumns[$i]);
            }
        }
        if ($skippedColumns) {
            $this->line('  ' . count($skippedColumns) . ' колонок CSV отсутствуют в DB, пропускаем: '
                . implode(', ', array_slice($skippedColumns, 0, 8))
                . (count($skippedColumns) > 8 ? '…' : ''));
        }

        // Get column types for value coercion (Directual экспортирует
        // даты как unix-millis, integer-поля иногда содержат CSV-массивы
        // типа "232,233,240,...").
        $columnTypes = [];
        foreach (DB::select('
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = ?
        ', [$tableName]) as $c) {
            $columnTypes[$c->column_name] = $c->data_type;
        }

        // НЕ используем DB::table->truncate() — Laravel делает TRUNCATE CASCADE,
        // что каскадно вычищает все FK-зависимые таблицы (commission TRUNCATE
        // CASCADE снесёт уже импортированные consultant/contract/...).
        // session_replication_role=replica не блокирует CASCADE — это часть
        // DDL синтаксиса, не триггер. DELETE FROM работает в режиме replica
        // без каскада.
        if ($force && $tableExists) {
            DB::statement('DELETE FROM "' . $tableName . '"');
        }

        // Import rows in chunks
        $rowsOk = 0;
        $rowsFailed = 0;
        $firstError = null;
        $batch = [];
        $batchSize = 100;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $values = str_getcsv($line, ';');
            $row = [];

            foreach ($dataColumns as $i => $col) {
                $val = $values[$i] ?? null;
                $row[$col] = $this->coerce($val, $columnTypes[$col] ?? null);
            }

            $batch[] = $row;

            if (count($batch) >= $batchSize) {
                [$ok, $fail, $err] = $this->insertBatch($tableName, $batch);
                $rowsOk += $ok;
                $rowsFailed += $fail;
                if ($err && $firstError === null) $firstError = $err;
                $batch = [];
            }
        }

        if (count($batch) > 0) {
            [$ok, $fail, $err] = $this->insertBatch($tableName, $batch);
            $rowsOk += $ok;
            $rowsFailed += $fail;
            if ($err && $firstError === null) $firstError = $err;
        }

        if ($rowsFailed > 0 && $firstError) {
            $this->warn('  ⚠ ' . $rowsFailed . ' строк не вставились. Первая ошибка: ' . $firstError);
        }

        fclose($handle);
        return [$rowsOk, $rowsFailed];
    }

    private function createTable(string $tableName, array $columns): void
    {
        $colDefs = [];
        foreach ($columns as $col) {
            if ($col === 'id') {
                $colDefs[] = '"id" BIGINT PRIMARY KEY';
            } else {
                $colDefs[] = '"' . $col . '" TEXT';
            }
        }

        $sql = 'CREATE TABLE IF NOT EXISTS "' . $tableName . '" (' . implode(', ', $colDefs) . ')';
        DB::statement($sql);

        $this->line("  Created table: {$tableName} (" . count($columns) . " columns)");
    }

    /**
     * Преобразует CSV-значение в формат, который примет Postgres.
     *
     * Directual экспортирует:
     *   - даты как unix-millis (`1721840400000`) — превращаем в timestamp
     *   - multi-value поля как CSV-массив `"232,233,240,..."` —
     *     если DB-колонка integer/bigint/uuid → null
     */
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
            'uuid',
        ], true);

        // unix-millis → ISO timestamp (только для date-типов).
        if ($isDateType && ctype_digit($val) && strlen($val) >= 10) {
            $secs = (int) (strlen($val) >= 13 ? (int) ((int) $val / 1000) : (int) $val);
            // допустимый диапазон: 1980-01-01 .. 2100-01-01
            if ($secs >= 315532800 && $secs <= 4102444800) {
                return date('Y-m-d H:i:s', $secs);
            }
        }

        // CSV-массив в integer-колонку → null (legacy multi-value поле).
        if ($isIntType && str_contains($val, ',')) {
            return null;
        }

        return $val;
    }

    /**
     * @return array{0:int,1:int,2:?string}  [ok, failed, firstError]
     */
    private function insertBatch(string $tableName, array $batch): array
    {
        try {
            DB::table($tableName)->insert($batch);
            return [count($batch), 0, null];
        } catch (\Exception $e) {
            // Fallback: вставляем по одной, чтобы плохие строки не топили
            // весь батч. Считаем ok/fail и первый текст ошибки — раньше
            // их silently глотали, что вызывало «лог говорит OK 543k,
            // а в БД 0 строк».
            $ok = 0;
            $failed = 0;
            $firstError = null;
            foreach ($batch as $row) {
                try {
                    DB::table($tableName)->insert($row);
                    $ok++;
                } catch (\Exception $e2) {
                    $failed++;
                    if ($firstError === null) {
                        $firstError = mb_substr($e2->getMessage(), 0, 220);
                    }
                }
            }
            return [$ok, $failed, $firstError];
        }
    }
}
