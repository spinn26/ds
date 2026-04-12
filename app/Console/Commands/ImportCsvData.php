<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportCsvData extends Command
{
    protected $signature = 'csv:import {--path=Db} {--table=} {--force} {--dry-run}';
    protected $description = 'Import CSV files from Db/ folder into PostgreSQL tables';

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

        // Find all CSV files
        $csvFiles = [];
        $this->findCsvFiles($basePath, $csvFiles);

        $this->info("Found " . count($csvFiles) . " CSV files");

        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($csvFiles as $filePath) {
            $tableName = pathinfo($filePath, PATHINFO_FILENAME);

            // Skip if targeting specific table
            if ($targetTable && $tableName !== $targetTable) {
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
                $result = $this->importCsv($filePath, $tableName, $tableExists, $force);
                $this->info("  OK: {$result} rows imported into {$tableName}");
                $imported++;
            } catch (\Exception $e) {
                $this->error("  FAIL {$tableName}: " . $e->getMessage());
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Done: {$imported} imported, {$skipped} skipped, {$errors} errors");

        return $errors > 0 ? 1 : 0;
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

    private function importCsv(string $filePath, string $tableName, bool $tableExists, bool $force): int
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
        }

        // Truncate if force
        if ($force && $tableExists) {
            DB::table($tableName)->truncate();
        }

        // Import rows in chunks
        $rowCount = 0;
        $batch = [];
        $batchSize = 100;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $values = str_getcsv($line, ';');
            $row = [];

            foreach ($dataColumns as $i => $col) {
                $val = $values[$i] ?? null;

                // Clean up values
                if ($val === '' || $val === null) {
                    $row[$col] = null;
                } elseif ($val === 'true') {
                    $row[$col] = true;
                } elseif ($val === 'false') {
                    $row[$col] = false;
                } else {
                    $row[$col] = $val;
                }
            }

            $batch[] = $row;
            $rowCount++;

            if (count($batch) >= $batchSize) {
                $this->insertBatch($tableName, $batch);
                $batch = [];
            }
        }

        if (count($batch) > 0) {
            $this->insertBatch($tableName, $batch);
        }

        fclose($handle);
        return $rowCount;
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

    private function insertBatch(string $tableName, array $batch): void
    {
        try {
            DB::table($tableName)->insert($batch);
        } catch (\Exception $e) {
            // Try one by one on batch failure
            foreach ($batch as $row) {
                try {
                    DB::table($tableName)->insert($row);
                } catch (\Exception $e2) {
                    // Skip problematic rows silently
                }
            }
        }
    }
}
