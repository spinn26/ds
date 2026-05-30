<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Полный реимпорт исторических данных (transaction, commission, dsCommission)
 * из Directual CSV-выгрузки 29 мая 2026.
 *
 * Контекст: с 1 июня 2026 платформа считает commission по новой формуле.
 * Все исторические данные ДО этой даты должны точно соответствовать
 * выгрузке Directual (там старая формула, она же source of truth для
 * исторических периодов).
 *
 * Стратегия:
 *  1) Загружаем translation map: CSV_id → prod_id для client/consultant/
 *     contract/product/program через natural keys (idDs/participantCode/
 *     number/name). Эти таблицы уже синхронизированы UPSERT-ом.
 *  2) TRUNCATE transaction CASCADE → снесёт ~22 зависимые таблицы (snapshots,
 *     consultantBalance, calculations, logs, webhooks). Все эти данные
 *     относились к старой формуле и должны быть пересчитаны заново после
 *     первого июня. Бэкап есть в /var/backups/newds/.
 *  3) COPY transaction с резолвом FK на client/consultant/contract.
 *     Сохраняем CSV id как prod id (после TRUNCATE id-namespace пустой).
 *  4) COPY dsCommission с резолвом FK на product/program.
 *  5) COPY commission с резолвом FK на consultant. transaction FK
 *     автоматически указывает на новый prod transaction.id (= CSV id).
 *  6) SETVAL для sequence-ей.
 *
 * Запуск:
 *   php artisan db:reimport-historical          # dry-run + статистика
 *   php artisan db:reimport-historical --apply  # реально TRUNCATE+COPY
 */
class ReimportDirectualHistorical extends Command
{
    protected $signature = 'db:reimport-historical
        {--csv-dir=storage/app/directual_2026-05-29 : Папка с CSV-файлами}
        {--apply : Реально TRUNCATE+COPY (без флага — только dry-run отчёт)}';

    protected $description = 'Полный реимпорт transaction/commission/dsCommission из Directual CSV';

    public function handle(): int
    {
        $dir = (string) $this->option('csv-dir');
        if (! str_starts_with($dir, '/')) {
            $dir = base_path($dir);
        }

        $files = [
            'transaction'  => $dir . '/transaction.csv',
            'commission'   => $dir . '/commission.csv',
            'dsCommission' => $dir . '/dsCommission.csv',
        ];
        foreach ($files as $name => $path) {
            if (! is_file($path)) {
                $this->error("Файл не найден: {$path}");
                return self::FAILURE;
            }
        }

        $this->info('=== Загрузка translation maps ===');
        $maps = $this->buildTranslationMaps();
        foreach ($maps as $entity => $map) {
            $this->line("  {$entity}: " . count($map) . ' пар CSV_id → prod_id');
        }

        $this->info('');
        $this->info('=== Анализ CSV (только подсчёт строк) ===');
        $stats = [];
        foreach ($files as $name => $path) {
            $stats[$name] = $this->countCsvRows($path);
            $this->line("  {$name}: {$stats[$name]} строк, " . round(filesize($path) / 1024 / 1024, 1) . ' MB');
        }

        $this->info('');
        $this->info('=== Анализ FK-резолва (sample 1000 строк transaction) ===');
        $resolveSample = $this->analyzeResolution($files['transaction'], $maps);
        foreach ($resolveSample as $col => $info) {
            $this->line("  transaction.{$col}: resolved {$info['resolved']}/{$info['total']} ({$info['percent']}%)");
        }

        if (! $this->option('apply')) {
            $this->info('');
            $this->warn('Dry-run. Используйте --apply для реального TRUNCATE+COPY.');
            $this->warn('После --apply будут вычищены ~22 таблицы CASCADE-ом!');
            return self::SUCCESS;
        }

        $this->info('');
        $this->warn('=== APPLY: TRUNCATE CASCADE + COPY ===');
        $this->warn('Снос consultantBalance, calculationConsultantPoints, calculationContestTrigger,');
        $this->warn('documentlogs, partnerMonthlyPaymentsReportTrigger, qualificationLog, nocomission,');
        $this->warn('massTransactionRecalculationTrigger, importTransactionLog, transactionRecalculation,');
        $this->warn('exportLogTransactions, reportGenerator, getInsmartOrderWebHookData,');
        $this->warn('getCourseOrderWebHookData, getcourseExportTransactionsData,');
        $this->warn('getcourseTransactionExportDataFromGoogleSpread, changeContractDsCommisionTrigger,');
        $this->warn('investorsTrust, volumeCalculator + обнуление contract.dsCommission/program.dsCommission/');
        $this->warn('consultant.commissionLast/transactionProrostId/transactionSpfkId');

        DB::transaction(function () use ($files, $maps) {
            $this->info('TRUNCATE...');
            // Отключаем statement_timeout — TRUNCATE 22 таблиц + COPY 552k commission
            // может занять >30 секунд.
            DB::statement("SET LOCAL statement_timeout = '600s'");
            DB::statement('TRUNCATE TABLE transaction, commission, "dsCommission" CASCADE');

            $this->info('Reimport dsCommission...');
            $n = $this->importDsCommission($files['dsCommission'], $maps);
            $this->line("  dsCommission: {$n}");

            $this->info('Reimport transaction...');
            $n = $this->importTransaction($files['transaction'], $maps);
            $this->line("  transaction: {$n}");

            $this->info('Reimport commission...');
            $n = $this->importCommission($files['commission'], $maps);
            $this->line("  commission: {$n}");

            $this->info('Обновляем sequences...');
            $this->fixSequence('transaction', 'transaction_id_seq');
            $this->fixSequence('commission', 'commission_id_seq');
            $this->fixSequenceDsCommission();
        });

        $this->info('');
        $this->info('=== DONE ===');
        $this->line('Проверка: SELECT COUNT(*) FROM transaction; SELECT COUNT(*) FROM commission;');
        return self::SUCCESS;
    }

    /**
     * Построить translation maps:
     *   client[csv_id] = prod_id (через idDs)
     *   consultant[csv_id] = prod_id (через participantCode)
     *   contract[csv_id] = prod_id (через number)
     *   product[csv_id] = prod_id (через name)
     *   program[csv_id] = prod_id (через name внутри product)
     */
    private function buildTranslationMaps(): array
    {
        $dir = base_path((string) $this->option('csv-dir'));

        // CSV: id → natural key
        $csvClient = [];   // csv_id → idDs
        foreach ($this->csvIter("{$dir}/client.csv") as $r) {
            $csvClient[(int) $r['id']] = trim((string) ($r['idDs'] ?? ''));
        }
        $csvConsultant = [];
        foreach ($this->csvIter("{$dir}/consultant.csv") as $r) {
            $csvConsultant[(int) $r['id']] = trim((string) ($r['participantCode'] ?? ''));
        }
        $csvContract = [];
        foreach ($this->csvIter("{$dir}/contract.csv") as $r) {
            $csvContract[(int) $r['id']] = trim((string) ($r['number'] ?? ''));
        }
        $csvProduct = [];
        foreach ($this->csvIter("{$dir}/product.csv") as $r) {
            $csvProduct[(int) $r['id']] = mb_strtolower(trim((string) ($r['name'] ?? '')));
        }
        $csvProgram = [];
        foreach ($this->csvIter("{$dir}/program.csv") as $r) {
            $csvProgram[(int) $r['id']] = mb_strtolower(trim((string) ($r['name'] ?? '')));
        }

        // PROD: natural key → prod_id
        $prodClient = [];
        DB::table('client')->whereNotNull('idDs')->orderBy('id')
            ->each(function ($row) use (&$prodClient) {
                $k = trim((string) $row->idDs);
                if ($k !== '' && ! isset($prodClient[$k])) $prodClient[$k] = (int) $row->id;
            });
        $prodConsultant = [];
        DB::table('consultant')->whereNotNull('participantCode')->orderBy('id')
            ->each(function ($row) use (&$prodConsultant) {
                $k = trim((string) $row->participantCode);
                if ($k !== '' && ! isset($prodConsultant[$k])) $prodConsultant[$k] = (int) $row->id;
            });
        $prodContract = [];
        DB::table('contract')->whereNotNull('number')->orderBy('id')
            ->each(function ($row) use (&$prodContract) {
                $k = trim((string) $row->number);
                if ($k !== '' && ! isset($prodContract[$k])) $prodContract[$k] = (int) $row->id;
            });
        $prodProduct = [];
        DB::table('product')->whereNotNull('name')->orderBy('id')
            ->each(function ($row) use (&$prodProduct) {
                $k = mb_strtolower(trim((string) $row->name));
                if ($k !== '' && ! isset($prodProduct[$k])) $prodProduct[$k] = (int) $row->id;
            });
        $prodProgram = [];
        DB::table('program')->whereNotNull('name')->orderBy('id')
            ->each(function ($row) use (&$prodProgram) {
                $k = mb_strtolower(trim((string) $row->name));
                if ($k !== '' && ! isset($prodProgram[$k])) $prodProgram[$k] = (int) $row->id;
            });

        // Финальные maps: csv_id → prod_id
        return [
            'client'     => $this->joinMaps($csvClient, $prodClient),
            'consultant' => $this->joinMaps($csvConsultant, $prodConsultant),
            'contract'   => $this->joinMaps($csvContract, $prodContract),
            'product'    => $this->joinMaps($csvProduct, $prodProduct),
            'program'    => $this->joinMaps($csvProgram, $prodProgram),
        ];
    }

    private function joinMaps(array $csv, array $prod): array
    {
        $out = [];
        foreach ($csv as $csvId => $nk) {
            if ($nk !== '' && isset($prod[$nk])) {
                $out[$csvId] = $prod[$nk];
            }
        }
        return $out;
    }

    /**
     * Итератор по CSV — генератор assoc-row.
     */
    private function csvIter(string $path): \Generator
    {
        $fh = fopen($path, 'r');
        if (! $fh) return;
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);
        $headers = fgetcsv($fh, 0, ';', '"', '\\');
        if (! $headers) { fclose($fh); return; }
        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        while (($row = fgetcsv($fh, 0, ';', '"', '\\')) !== false) {
            if ($row === [null]) continue;
            $assoc = [];
            foreach ($headers as $i => $h) $assoc[$h] = $row[$i] ?? null;
            yield $assoc;
        }
        fclose($fh);
    }

    private function countCsvRows(string $path): int
    {
        $n = 0;
        foreach ($this->csvIter($path) as $_) $n++;
        return $n;
    }

    private function analyzeResolution(string $path, array $maps): array
    {
        $stats = [
            'client'     => ['resolved' => 0, 'total' => 0],
            'consultant' => ['resolved' => 0, 'total' => 0],
            'contract'   => ['resolved' => 0, 'total' => 0],
            'product'    => ['resolved' => 0, 'total' => 0],
        ];
        $i = 0;
        foreach ($this->csvIter($path) as $r) {
            if ($i++ >= 1000) break;
            foreach ($stats as $col => $_) {
                $id = (int) ($r[$col] ?? 0);
                if ($id > 0) {
                    $stats[$col]['total']++;
                    if (isset($maps[$col][$id])) $stats[$col]['resolved']++;
                }
            }
        }
        foreach ($stats as $col => &$s) {
            $s['percent'] = $s['total'] ? round($s['resolved'] / $s['total'] * 100, 1) : 0;
        }
        return $stats;
    }

    /**
     * Bulk INSERT через chunked DB::table()->insert. Реальная INSERT-команда
     * имеет лимит ~65k параметров; chunk-size 500 строк × 30 колонок = 15k
     * параметров — безопасно.
     */
    private function importTransaction(string $path, array $maps): int
    {
        // prod-схема transaction содержит ТОЛЬКО основные поля; client/
        // consultant/product денормализуются через contract на этапе
        // расчёта commission. В CSV из этого набора только: id, contract,
        // comment, amount, dsCommissionPercentage, vat, date, dateMonth,
        // dateYear, currency, commissionCalcProperty.
        $cols = Schema::getColumnListing('transaction');
        $writable = ['id', 'contract', 'comment', 'amount', 'dsCommissionPercentage',
                     'vat', 'date', 'dateMonth', 'dateYear', 'currency',
                     'commissionCalcProperty'];
        $cols = array_values(array_intersect($writable, $cols));

        return $this->copyChunked($path, 'transaction', $cols, function ($r) use ($maps) {
            $contract = (int) ($r['contract'] ?? 0);
            $out = [
                'id'                       => (int) $r['id'],
                'contract'                 => $maps['contract'][$contract] ?? null,
                'currency'                 => $this->toIntOrNull($r['currency'] ?? null),
                'date'                     => $r['date'] ?: null,
                'dateYear'                 => $r['dateYear'] ?: null,
                'dateMonth'                => $r['dateMonth'] ?: null,
                'amount'                   => $this->toFloat($r['amount'] ?? null),
                'comment'                  => $r['comment'] ?: null,
                'dsCommissionPercentage'   => $this->toFloat($r['dsCommissionPercentage'] ?? null),
                'commissionCalcProperty'   => $this->toIntOrNull($r['commissionCalcProperty'] ?? null),
                'vat'                      => $this->toIntOrNull($r['vat'] ?? null),
            ];
            // Пропускаем строки с unresolved contract (FK violation).
            if (! $out['contract']) return null;
            return $out;
        });
    }

    private function importCommission(string $path, array $maps): int
    {
        // prod commission columns + типы (см. \d commission):
        //   reduction boolean, qualificationLog integer (FK), createdAt timestamp
        //   (НЕ dateCreated). user_field integer (CSV: user — но это reserved word)
        $cols = ['id', 'transaction', 'consultant', 'consultantsChain', 'chainOrder',
                 'amount', 'amountRUB', 'amountUSD', 'currency', 'dateMonth', 'dateYear',
                 'date', 'type', 'calculationLevel',
                 'commissionFromOtherConsultant', 'qualificationLog', 'reduction',
                 'comment', 'percent', 'absolute', 'personalVolume', 'groupVolume',
                 'createdAt'];
        return $this->copyChunked($path, 'commission', $cols, function ($r) use ($maps) {
            $cons = (int) ($r['consultant'] ?? 0);
            $consChain = (int) ($r['consultantsChain'] ?? 0);
            $fromOther = (int) ($r['commissionFromOtherConsultant'] ?? 0);
            $out = [
                'id'                                 => (int) $r['id'],
                'transaction'                        => (int) ($r['transaction'] ?? 0) ?: null,
                'consultant'                         => $maps['consultant'][$cons] ?? null,
                'consultantsChain'                   => $maps['consultant'][$consChain] ?? null,
                'chainOrder'                         => $this->toIntOrNull($r['chainOrder'] ?? null),
                'amount'                             => $this->toFloat($r['amount'] ?? null),
                'amountRUB'                          => $this->toFloat($r['amountRUB'] ?? null),
                'amountUSD'                          => $this->toFloat($r['amountUSD'] ?? null),
                'currency'                           => $this->toIntOrNull($r['currency'] ?? null),
                'dateMonth'                          => $r['dateMonth'] ?: null,
                'dateYear'                           => $r['dateYear'] ?: null,
                'date'                               => $r['date'] ?: null,
                'type'                               => $r['type'] ?: null,
                'calculationLevel'                   => $this->toIntOrNull($r['calculationLevel'] ?? null),
                'commissionFromOtherConsultant'     => $maps['consultant'][$fromOther] ?? null,
                'qualificationLog'                   => null,  // FK на qualificationLog; пропустим (пересчёт после)
                'reduction'                          => $this->toBool($r['reduction'] ?? null),
                'comment'                            => $r['comment'] ?: null,
                'percent'                            => $this->toFloat($r['percent'] ?? null),
                'absolute'                           => $this->toFloat($r['absolute'] ?? null),
                'personalVolume'                     => $this->toFloat($r['personalVolume'] ?? null),
                'groupVolume'                        => $this->toFloat($r['groupVolume'] ?? null),
                'createdAt'                          => $r['@dateCreated'] ?? ($r['createdAt'] ?? null) ?: null,
            ];
            if (! $out['transaction'] || ! $out['consultant']) return null;
            return $out;
        });
    }

    private function importDsCommission(string $path, array $maps): int
    {
        // Реальные колонки prod-таблицы dsCommission (см. \d "dsCommission"):
        // id, product, program, productName, programName, comission (sic!
        // опечатка в Directual), commissionAbsolute, commissionCalcProperty,
        // date, dateFinish, active, termContract, dateDeleted.
        $cols = ['id', 'product', 'program', 'productName', 'programName',
                 'comission', 'commissionAbsolute', 'commissionCalcProperty',
                 'date', 'dateFinish', 'active', 'termContract', 'dateDeleted'];

        return $this->copyChunked($path, '"dsCommission"', $cols, function ($r) use ($maps) {
            $prod = (int) ($r['product'] ?? 0);
            $prog = (int) ($r['program'] ?? 0);
            $out = [
                'id'                     => (int) $r['id'],
                'product'                => $maps['product'][$prod] ?? null,
                'program'                => $maps['program'][$prog] ?? null,
                'productName'            => $r['productName'] ?: null,
                'programName'            => $r['programName'] ?: null,
                'comission'              => $this->toFloat($r['comission'] ?? null),
                'commissionAbsolute'     => $this->toFloat($r['commissionAbsolute'] ?? null),
                'commissionCalcProperty' => $this->toIntOrNull($r['commissionCalcProperty'] ?? null),
                'date'                   => $r['date'] ?: null,
                'dateFinish'             => $r['dateFinish'] ?: null,
                'active'                 => $this->toBool($r['active'] ?? null),
                'termContract'           => $this->toIntOrNull($r['termContract'] ?? null),
                'dateDeleted'            => $r['dateDeleted'] ?: null,
            ];
            if (! $out['product']) return null;
            return $out;
        });
    }

    private function toBool(mixed $v): ?bool
    {
        if ($v === null || $v === '') return null;
        $s = strtolower((string) $v);
        return in_array($s, ['true', 't', '1', 'yes'], true);
    }

    /**
     * Универсальный chunk-COPY с резолвером.
     *
     * @param  callable(array): ?array  $resolver  превращает CSV-row в DB-row или null чтобы пропустить
     */
    private function copyChunked(string $path, string $table, array $cols, callable $resolver): int
    {
        $chunk = [];
        $size = 500;
        $total = 0;
        $skipped = 0;

        foreach ($this->csvIter($path) as $r) {
            $row = $resolver($r);
            if ($row === null) { $skipped++; continue; }
            // Берём только нужные колонки
            $clean = [];
            foreach ($cols as $c) $clean[$c] = $row[$c] ?? null;
            $chunk[] = $clean;

            if (count($chunk) >= $size) {
                DB::table(trim($table, '"'))->insert($chunk);
                $total += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            DB::table(trim($table, '"'))->insert($chunk);
            $total += count($chunk);
        }
        if ($skipped) {
            $this->warn("  {$table}: skipped {$skipped} (unresolved FK)");
        }
        return $total;
    }

    private function fixSequence(string $table, string $seq): void
    {
        DB::statement("SELECT setval('{$seq}', COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1))");
    }

    private function fixSequenceDsCommission(): void
    {
        DB::statement("SELECT setval('\"dsCommission_id_seq\"', COALESCE((SELECT MAX(id) FROM \"dsCommission\"), 1))");
    }

    private function toFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        return (float) str_replace(',', '.', (string) $v);
    }

    private function toIntOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        $i = (int) $v;
        return $i > 0 ? $i : null;
    }
}
