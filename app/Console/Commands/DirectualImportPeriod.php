<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Догрузка в локальную БД недостающих строк Directual-выгрузки за период,
 * с фильтром по дате создания ИЛИ по членству FK.
 *
 * Тот же движок, что в csv:reimport-missing (fgetcsv с многострочными
 * полями, coerce типов Directual, FK-off на время вставки, выравнивание
 * сиквенса в конце), плюс отбор по периоду. Вставляет ТОЛЬКО строки, чьих
 * id нет в таблице. По умолчанию — dry-run (без записи).
 *
 * Режимы отбора:
 *   --date-col=COL                — оставить строки, у кого дата в [from, to).
 *                                   Парсит ISO / unix-millis / Java-Date /
 *                                   'YYYY-MM'. Пустая дата → строка отброшена.
 *   --fk-col=COL --fk-from-table=T --fk-from-date-col=D
 *                                 — для таблиц БЕЗ собственной даты создания
 *                                   (напр. commission): оставить строки, чей
 *                                   FK входит в множество id таблицы T, где
 *                                   T.D попадает в [from, to). Реализует правило
 *                                   «нет даты создания → матчим по связи в нашей БД».
 *
 * Примеры:
 *   php artisan directual:import-period transaction --date-col=dateCreated
 *   php artisan directual:import-period commission --fk-col=transaction \
 *       --fk-from-table=transaction --fk-from-date-col=dateCreated --apply
 */
class DirectualImportPeriod extends Command
{
    protected $signature = 'directual:import-period
                            {table : имя таблицы в БД}
                            {--path=база : корневая директория CSV}
                            {--csv= : имя CSV-файла, если не совпадает с таблицей (напр. webUser.csv для WebUser)}
                            {--date-col= : колонка даты для фильтра периода}
                            {--fk-col= : вместо даты — фильтр по членству FK}
                            {--fk-from-table= : таблица-источник допустимых id для FK}
                            {--fk-from-date-col= : колонка даты в таблице-источнике}
                            {--from=2026-04-01 : нижняя граница периода (включительно)}
                            {--to=2026-06-01 : верхняя граница периода (исключительно)}
                            {--apply : выполнить вставку (иначе только dry-run)}';

    protected $description = 'Догрузить недостающие строки Directual CSV за период (по дате создания или по FK).';

    public function handle(): int
    {
        $table = $this->argument('table');
        $base = base_path($this->option('path'));
        $apply = (bool) $this->option('apply');
        $from = Carbon::parse($this->option('from'));
        $to = Carbon::parse($this->option('to'));
        $dateCol = $this->option('date-col');
        $fkCol = $this->option('fk-col');

        if (! Schema::hasTable($table)) {
            $this->error("Таблица {$table} не существует в БД");
            return self::FAILURE;
        }
        if (! $dateCol && ! $fkCol) {
            $this->error('Укажите --date-col=… либо --fk-col=… (+ --fk-from-table/--fk-from-date-col)');
            return self::FAILURE;
        }

        $csvName = $this->option('csv') ?: ($table . '.csv');
        $csvPath = $this->findCsv($base, $csvName);
        if (! $csvPath) {
            $this->error("CSV '{$csvName}' не найден в {$base}");
            return self::FAILURE;
        }

        // Множество допустимых FK-id (если режим по связи).
        $allowedFk = null;
        if ($fkCol) {
            $srcTable = $this->option('fk-from-table');
            $srcDate = $this->option('fk-from-date-col');
            if (! $srcTable || ! $srcDate) {
                $this->error('Для --fk-col нужны --fk-from-table и --fk-from-date-col');
                return self::FAILURE;
            }
            $ids = DB::table($srcTable)
                ->where($srcDate, '>=', $from->toDateTimeString())
                ->where($srcDate, '<', $to->toDateTimeString())
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            $allowedFk = array_flip($ids);
            $this->info(sprintf('FK-множество: %d id из %s (%s в [%s, %s))',
                count($ids), $srcTable, $srcDate, $from->toDateString(), $to->toDateString()));
        }

        $f = fopen($csvPath, 'r');
        $headers = fgetcsv($f, 0, ';');
        $headers = array_map(fn ($h) => trim((string) $h, "\xEF\xBB\xBF \t\""), $headers);
        $idCol = array_search('id', $headers, true);
        if ($idCol === false) {
            fclose($f);
            $this->error('Колонка id не найдена в CSV');
            return self::FAILURE;
        }
        $filterCol = $dateCol ? array_search($dateCol, $headers, true) : array_search($fkCol, $headers, true);
        if ($filterCol === false) {
            fclose($f);
            $this->error('Колонка фильтра (' . ($dateCol ?: $fkCol) . ') не найдена в CSV');
            return self::FAILURE;
        }

        $kept = [];          // id => row, прошедшие фильтр периода/FK
        $totalRows = 0;
        while (($row = fgetcsv($f, 0, ';')) !== false) {
            if ($row === [null]) continue;
            $totalRows++;
            $id = (int) ($row[$idCol] ?? 0);
            if ($id <= 0) continue;

            $raw = $row[$filterCol] ?? null;
            if ($dateCol) {
                $d = $this->parseDate($raw);
                if (! $d || $d->lt($from) || $d->gte($to)) continue;
            } else {
                $fk = (int) $raw;
                if (! isset($allowedFk[$fk])) continue;
            }
            $kept[$id] = $row;
        }
        fclose($f);

        // Существующие id в таблице.
        $existing = DB::table($table)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $existingSet = array_flip($existing);

        $missing = array_values(array_filter(array_keys($kept), fn ($id) => ! isset($existingSet[$id])));
        sort($missing);

        $this->info(sprintf(
            '%s: в периоде CSV=%d, уже в БД=%d, НЕДОСТАЁТ=%d (всего строк CSV=%d, в таблице=%d)',
            $table, count($kept), count($kept) - count($missing), count($missing), $totalRows, count($existing)
        ));

        if (! $missing) {
            $this->info('Нечего догружать.');
            return self::SUCCESS;
        }
        if (! $apply) {
            $this->line('DRY-RUN. Примеры id: ' . implode(', ', array_slice($missing, 0, 30))
                . (count($missing) > 30 ? ' …' : ''));
            $this->comment('Повторите с --apply для вставки.');
            return self::SUCCESS;
        }

        // Типы колонок для coerce.
        $columnTypes = [];
        foreach (DB::select('SELECT column_name, data_type FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = ?', [$table]) as $c) {
            $columnTypes[$c->column_name] = $c->data_type;
        }
        $dataColumns = array_intersect($headers, array_keys($columnTypes));

        DB::statement('SET session_replication_role = replica'); // FK-off на сессию

        $batch = [];
        $ok = 0; $fail = 0; $firstError = null;
        foreach ($missing as $id) {
            $row = $kept[$id];
            $insert = [];
            foreach ($headers as $i => $col) {
                if (! in_array($col, $dataColumns, true)) continue;
                $insert[$col] = $this->coerce($row[$i] ?? null, $columnTypes[$col] ?? null);
            }
            $batch[] = $insert;
            if (count($batch) >= 100) {
                [$o, $fl, $e] = $this->insertBatch($table, $batch);
                $ok += $o; $fail += $fl; if ($e && ! $firstError) $firstError = $e;
                $batch = [];
            }
        }
        if ($batch) {
            [$o, $fl, $e] = $this->insertBatch($table, $batch);
            $ok += $o; $fail += $fl; if ($e && ! $firstError) $firstError = $e;
        }

        $this->info("Вставлено: {$ok}, ошибок: {$fail}");
        if ($firstError) $this->warn('Первая ошибка: ' . $firstError);

        // Выравнивание сиквенса — чтобы будущие insert не конфликтовали (см. инцидент 2026-06-05).
        $seq = DB::scalar('SELECT pg_get_serial_sequence(?, ?)', ['"' . $table . '"', 'id']);
        if ($seq) {
            $maxId = (int) DB::scalar("SELECT COALESCE(MAX(id), 0) FROM \"{$table}\"");
            DB::statement("SELECT setval('{$seq}'::regclass, ?, true)", [max($maxId, 1)]);
            $this->info("Сиквенс {$seq} → {$maxId}");
        }

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function parseDate(?string $val): ?Carbon
    {
        if ($val === null || $val === '') return null;
        // Directual unix-millis.
        if (ctype_digit($val) && strlen($val) >= 12) {
            return Carbon::createFromTimestampMs((int) $val);
        }
        try {
            return Carbon::parse($val); // ISO, Java-Date 'Fri Dec 06 … 2024', 'YYYY-MM'
        } catch (\Throwable) {
            return null;
        }
    }

    private function findCsv(string $base, string $filename): ?string
    {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getFilename() === $filename) {
                return $file->getPathname();
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
                catch (\Throwable $e2) { $fail++; if (! $first) $first = mb_substr($e2->getMessage(), 0, 220); }
            }
            return [$ok, $fail, $first];
        }
    }

    /** Тот же coerce, что в CsvReimportMissing — совместимость форматов Directual. */
    private function coerce(?string $val, ?string $dbType): mixed
    {
        if ($val === null || $val === '') return null;
        if ($val === 'true') return true;
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

        if ($isDateType && ctype_digit($val) && strlen($val) >= 12) {
            return date('Y-m-d H:i:s', (int) substr($val, 0, 10));
        }
        if (($isIntType || $isUuid) && str_contains($val, ',')) return null;
        if ($isJson) {
            json_decode($val);
            if (json_last_error() !== JSON_ERROR_NONE) return null;
        }

        return $val;
    }
}
