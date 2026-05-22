<?php

namespace App\Jobs;

use App\Services\CommissionCalculator;
use App\Services\GoogleSheetsReader;
use App\Services\SheetProfiles;
use App\Services\ApiSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Async-импорт транзакций (Google Sheets / CSV).
 *
 * Раньше POST /admin/transaction-import* делал всё синхронно: парсинг,
 * валидацию ~1000+ строк (× 2 SQL-запроса на матчинг контракта),
 * INSERT в DB::transaction(), а потом ещё каскад комиссий через
 * CommissionCalculator::calculateForImport. На 1267 строк это легко
 * пробивает 30s axios timeout — клиент видит «Нет связи с сервером»,
 * хотя сервер всё-таки доделывает работу и создаёт impor-лог. В итоге
 * пользователь жмёт повторно → задвоенные импорты в истории.
 *
 * Здесь работа уезжает в очередь: контроллер сразу отдаёт 202 +
 * `importId` + `tracker`, фронт поллит /admin/import-progress, а Job
 * пишет tracker по ходу выполнения и финализирует transaction_import_log.
 */
class ImportTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;   // 30 минут: 1500 строк × calc цепочки = ~5-10 минут на dev
    public int $tries = 1;        // импорт не идемпотентен — ретраи создают дубликаты
    public int $maxExceptions = 1;

    /**
     * @param string $source 'sheets' | 'csv'
     * @param string $sourceRef имя листа (sheets) или путь к файлу (csv)
     * @param ?int $counterpartyId явный counterparty (для generic-листов и CSV)
     * @param ?int $currencyId явная валюта; для sheets с профилем перебивается профилем
     * @param int $importLogId id заранее созданной записи в transaction_import_log
     * @param int $userId автор импорта (для лога и комментариев)
     * @param string $tracker uuid для polling-прогресса (cache: "import:tracker:{tracker}")
     */
    public function __construct(
        public readonly string $source,
        public readonly string $sourceRef,
        public readonly ?int $counterpartyId,
        public readonly ?int $currencyId,
        public readonly int $importLogId,
        public readonly int $userId,
        public readonly string $tracker,
    ) {}

    public function handle(CommissionCalculator $calculator): void
    {
        $this->putTracker(['status' => 'running', 'total' => 0, 'processed' => 0, 'success' => 0, 'errors' => 0]);

        try {
            // === STEP 1: get rows (Sheets API call / CSV read) ===
            [$rows, $resolvedCounterparty, $resolvedCurrency, $profile] = $this->loadRows();
            $total = count($rows);

            if ($total === 0) {
                $this->finalizeError('Источник пустой или нет строк данных');
                return;
            }

            $this->putTracker([
                'status' => 'running', 'total' => $total, 'processed' => 0,
                'success' => 0, 'errors' => 0,
            ]);

            // === STEP 2: validation (parse + match contract) ===
            $currencyRate = 1.0;
            if ($resolvedCurrency && $resolvedCurrency !== 67) {
                $rate = DB::table('currencyRate')
                    ->where('currency', $resolvedCurrency)
                    ->orderByDesc('date')
                    ->first();
                $currencyRate = (float) ($rate->rate ?? 1);
            }
            $usdRate = 1.0;
            $rateUsd = DB::table('currencyRate')->where('currency', 5)->orderByDesc('date')->first();
            if ($rateUsd) $usdRate = (float) $rateUsd->rate;

            $errors = [];
            $warnings = [];
            $prepared = [];
            $periodFreeze = app(\App\Services\PeriodFreezeService::class);
            foreach ($rows as $i => $row) {
                $contractNumber = trim((string) ($row['contract_number'] ?? $row['number'] ?? $row['номер_контракта'] ?? $row['contract'] ?? ''));
                $amount = (float) str_replace([' ', ','], ['', '.'], (string) ($row['amount'] ?? $row['сумма'] ?? $row['sum'] ?? '0'));
                $date = $row['date'] ?? $row['дата'] ?? $row['payment_date'] ?? null;

                if ($contractNumber === '') {
                    $errors[] = 'Строка ' . ($i + 2) . ': пустой номер контракта';
                } else {
                    // 1) точное совпадение
                    $contract = DB::table('contract')
                        ->where('number', $contractNumber)
                        ->whereNull('deletedAt')
                        ->first();
                    $matchedByIlike = false;
                    if (! $contract) {
                        // 2) fallback ilike — оставляем для удобства, но
                        // обязательно даём warning: молчаливое совпадение
                        // «1234» с контрактом «1234567» бывает катастрофой.
                        $contract = DB::table('contract')
                            ->where('number', 'ilike', '%' . $contractNumber . '%')
                            ->whereNull('deletedAt')
                            ->first();
                        $matchedByIlike = (bool) $contract;
                    }
                    if (! $contract) {
                        $errors[] = 'Строка ' . ($i + 2) . ': контракт «' . $contractNumber . '» не найден';
                    } else {
                        // Period-freeze: дата транзакции не должна попадать
                        // в закрытый месяц. Иначе commission по ней не
                        // пересчитается, и оператор зальёт «потерянные»
                        // транзакции, которые ничего не дадут партнёру.
                        if ($date) {
                            $year = (int) date('Y', strtotime($date));
                            $month = (int) date('m', strtotime($date));
                            if ($year && $month && $periodFreeze->isFrozen($year, $month)) {
                                $errors[] = sprintf(
                                    'Строка %d: дата %s в закрытом периоде %02d.%d — импорт запрещён',
                                    $i + 2, date('Y-m-d', strtotime($date)), $month, $year,
                                );
                                continue;
                            }
                        }

                        if ($matchedByIlike && $contract->number !== $contractNumber) {
                            $warnings[] = sprintf(
                                'Строка %d: точного совпадения нет, найден по частичному → контракт «%s» (id %d, клиент %s). Проверьте.',
                                $i + 2,
                                $contract->number ?? '?',
                                $contract->id,
                                $contract->clientName ?? '—',
                            );
                        }

                        $amountRub = $amount * $currencyRate;
                        $amountUsd = $usdRate > 0 ? $amountRub / $usdRate : 0;
                        $prepared[] = [
                            'contract_id' => $contract->id,
                            'amount' => $amount,
                            'amountRub' => $amountRub,
                            'amountUsd' => $amountUsd,
                            'date' => $date,
                            'ds_percent' => $row['ds_percent'] ?? $row['процент_дс'] ?? null,
                            'property' => $row['property'] ?? $row['свойство'] ?? null,
                        ];
                    }
                }

                // Обновляем tracker каждые 50 строк или на последней — даёт живой прогресс
                // в ImportProgressDialog без перегруза кэша (1500 строк / 50 = 30 апдейтов).
                if (($i + 1) % 50 === 0 || $i === $total - 1) {
                    $this->putTracker([
                        'status' => 'running', 'total' => $total, 'processed' => $i + 1,
                        'success' => 0, 'errors' => count($errors),
                    ]);
                }
            }

            // Атомарность сохраняется: если хоть одна строка не валидна — ничего не вставляем.
            if ($errors) {
                $this->finalizeError(
                    'Импорт отменён: найдено ' . count($errors) . ' ошибок. Ничего не загружено.',
                    $errors,
                );
                return;
            }

            // === STEP 3: atomic insert ===
            $createdIds = [];
            try {
                $createdIds = DB::transaction(function () use ($prepared, $resolvedCurrency, $currencyRate) {
                    $ids = [];
                    foreach ($prepared as $p) {
                        $txId = DB::table('transaction')->insertGetId([
                            'contract' => $p['contract_id'],
                            'amount' => $p['amount'],
                            'amountRUB' => round($p['amountRub'], 2),
                            'amountUSD' => round($p['amountUsd'], 2),
                            'currency' => $resolvedCurrency,
                            'currencyRate' => $currencyRate,
                            'date' => $p['date'] ? date('Y-m-d\TH:i:s', strtotime($p['date'])) : now()->toIso8601String(),
                            'dateMonth' => $p['date'] ? date('Y-m', strtotime($p['date'])) : now()->format('Y-m'),
                            'dateYear' => $p['date'] ? date('Y', strtotime($p['date'])) : now()->format('Y'),
                            'comment' => 'Импорт #' . $this->importLogId,
                            'dsCommissionPercentage' => $p['ds_percent'],
                            'commissionCalcProperty' => $p['property'],
                        ]);
                        $ids[] = (int) $txId;
                    }
                    return $ids;
                });
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (preg_match('/([^:\n]+(?:violation|violates|exists|not-null|duplicate)[^\n]*)/i', $msg, $m)) {
                    $msg = trim($m[1]);
                }
                Log::error('Import atomic insert failed', ['importId' => $this->importLogId, 'error' => $msg]);
                $this->finalizeError('Импорт отменён из-за ошибки БД. Ничего не загружено.', [mb_substr($msg, 0, 300)]);
                return;
            }

            $successCount = count($createdIds);

            // === STEP 4: auto-calc commissions ===
            // Сначала обновляем лог success_count и created_ids, чтобы ошибка
            // расчёта не оставила транзакции без записи в логе.
            DB::table('transaction_import_log')->where('id', $this->importLogId)->update([
                'success_count' => $successCount,
                'error_count' => 0,
                'created_ids' => json_encode($createdIds),
                'updated_at' => now(),
            ]);

            $this->putTracker([
                'status' => 'running', 'total' => $total, 'processed' => $total,
                'success' => $successCount, 'errors' => 0,
                'phase' => 'calc',
            ]);

            $calcStats = null;
            try {
                $calcStats = $calculator->calculateForImport($this->importLogId);
            } catch (\Throwable $e) {
                Log::warning("Auto-calc commissions failed for import #{$this->importLogId}: " . $e->getMessage());
            }

            $infoMessages = [];
            if ($calcStats) {
                $infoMessages[] = "Расчёт комиссий: {$calcStats['success']} из {$calcStats['total']}";
                if (! empty($calcStats['errors'])) {
                    foreach (array_slice($calcStats['errors'], 0, 20) as $err) {
                        $infoMessages[] = is_array($err) ? json_encode($err, JSON_UNESCAPED_UNICODE) : (string) $err;
                    }
                }
            }

            $update = [
                'status' => 'success',
                'errors' => $infoMessages ? json_encode($infoMessages, JSON_UNESCAPED_UNICODE) : null,
                'updated_at' => now(),
            ];
            if ($warnings && \Illuminate\Support\Facades\Schema::hasColumn('transaction_import_log', 'warnings')) {
                $update['warnings'] = json_encode($warnings, JSON_UNESCAPED_UNICODE);
            }
            DB::table('transaction_import_log')->where('id', $this->importLogId)->update($update);

            $msg = "Импорт завершён: {$successCount} транзакций загружено";
            if ($warnings) $msg .= ", предупреждений: " . count($warnings);
            if ($calcStats) {
                $msg .= ", комиссии рассчитаны: {$calcStats['success']} из {$calcStats['total']}";
            }

            $this->putTracker([
                'status' => 'done', 'total' => $total, 'processed' => $total,
                'success' => $successCount, 'errors' => 0,
                'warnings' => count($warnings),
                'importId' => $this->importLogId, 'message' => $msg,
                'calc' => $calcStats,
            ]);

            // Cleanup: если CSV — удалить временный файл
            if ($this->source === 'csv' && file_exists($this->sourceRef)) {
                @unlink($this->sourceRef);
            }
        } catch (\Throwable $e) {
            Log::error('ImportTransactionsJob failed', [
                'importId' => $this->importLogId,
                'tracker' => $this->tracker,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->finalizeError('Импорт прерван: ' . $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->finalizeError('Импорт не выполнен (job failed): ' . $e->getMessage());
    }

    /**
     * Прочитать строки из Google Sheets или CSV и привести к общему виду:
     *   [rows[], counterpartyId, currencyId, profile|null]
     */
    private function loadRows(): array
    {
        if ($this->source === 'sheets') {
            $settings = app(ApiSettingsService::class);
            $spreadsheetId = $settings->get('google.sheets.transactions_id',
                config('services.google_sheets.spreadsheet_id', env('GOOGLE_SHEETS_SPREADSHEET_ID')));
            $apiKey = $settings->get('google.sheets.api_key',
                config('services.google_sheets.api_key', env('GOOGLE_SHEETS_API_KEY')));

            if (! $spreadsheetId || ! $apiKey) {
                throw new \RuntimeException('Google Sheets не настроен в /admin/api-keys');
            }

            $range = urlencode($this->sourceRef);
            $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?key={$apiKey}&majorDimension=ROWS";
            $response = Http::timeout(60)->get($url);
            if (! $response->ok()) {
                throw new \RuntimeException("Ошибка чтения листа: HTTP {$response->status()}. Проверьте ID и название листа.");
            }

            $values = $response->json('values') ?? [];
            if (count($values) < 2) {
                return [[], $this->counterpartyId ?? 0, $this->currencyId ?? 67, null];
            }

            $headers = $values[0];
            $rawRows = array_slice($values, 1);
            $profile = SheetProfiles::profile($this->sourceRef);

            if ($profile) {
                $counterpartyId = SheetProfiles::resolveCounterpartyId($profile['counterpartyName'] ?? '')
                    ?? $this->counterpartyId
                    ?? 0;
                if (! $counterpartyId) {
                    throw new \RuntimeException(
                        'Counterparty «' . ($profile['counterpartyName'] ?? '—') . '» не найден в БД. '
                        . 'Создайте его или выберите вручную.'
                    );
                }
                $currencyId = isset($profile['currency'])
                    ? SheetProfiles::resolveCurrencyId($profile['currency'], 67)
                    : ($this->currencyId ?? 67);

                $rows = [];
                foreach ($rawRows as $row) {
                    $a = SheetProfiles::alignRow($row, $headers, $profile);
                    $rows[] = [
                        'contract_number' => (string) ($a['contract_number'] ?? ''),
                        'amount' => $a['amount'] ?? ($a['commission'] ?? 0),
                        'date' => $a['date'] ?? null,
                        'ds_percent' => $a['commission_pct'] ?? null,
                    ];
                }
                return [$rows, (int) $counterpartyId, (int) $currencyId, $profile];
            }

            // Generic: counterparty обязателен (проверен в контроллере перед dispatch).
            $rows = [];
            foreach ($rawRows as $row) {
                $assoc = [];
                foreach ($headers as $i => $h) $assoc[mb_strtolower(trim((string) $h))] = $row[$i] ?? null;
                $rows[] = $assoc;
            }
            return [$rows, $this->counterpartyId ?? 0, $this->currencyId ?? 67, null];
        }

        // source === 'csv'
        $rows = $this->parseCsv($this->sourceRef);
        return [$rows, $this->counterpartyId ?? 0, $this->currencyId ?? 67, null];
    }

    private function parseCsv(string $path): array
    {
        $rows = [];
        $handle = @fopen($path, 'r');
        if (! $handle) return [];

        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = str_contains((string) $firstLine, ';') ? ';' : ',';

        $headers = fgetcsv($handle, 0, $delimiter);
        if (! $headers) { fclose($handle); return []; }

        $headers = array_map(fn ($h) => strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', (string) $h))), $headers);

        $headerMap = [];
        foreach ($headers as $i => $h) {
            if (str_contains($h, 'контракт') || str_contains($h, 'contract') || str_contains($h, 'номер')) {
                $headerMap[$i] = 'contract_number';
            } elseif (str_contains($h, 'сумма') || str_contains($h, 'amount') || str_contains($h, 'sum')) {
                $headerMap[$i] = 'amount';
            } elseif (str_contains($h, 'дата') || str_contains($h, 'date')) {
                $headerMap[$i] = 'date';
            } elseif (str_contains($h, 'свойство') || str_contains($h, 'property')) {
                $headerMap[$i] = 'property';
            } elseif (str_contains($h, 'процент') || str_contains($h, 'ds_percent') || str_contains($h, 'комисс')) {
                $headerMap[$i] = 'ds_percent';
            } else {
                $headerMap[$i] = $h;
            }
        }

        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row = [];
            foreach ($headerMap as $i => $key) {
                $row[$key] = $values[$i] ?? null;
            }
            if (! empty(array_filter($row))) {
                $rows[] = $row;
            }
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Зафиксировать ошибку в логе + в tracker'е, чтобы фронт корректно
     * отобразил финальный «status=done, errors>0» state.
     */
    private function finalizeError(string $message, array $details = []): void
    {
        DB::table('transaction_import_log')->where('id', $this->importLogId)->update([
            'status' => 'error',
            'success_count' => 0,
            'error_count' => max(1, count($details)),
            'errors' => json_encode(array_slice($details, 0, 100) ?: [$message], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        $current = Cache::get("import:tracker:{$this->tracker}") ?? [];
        $this->putTracker([
            'status' => 'done',
            'total' => $current['total'] ?? 0,
            'processed' => $current['total'] ?? 0,
            'success' => 0,
            'errors' => max(1, count($details)),
            'importId' => $this->importLogId,
            'message' => $message,
            'errorDetails' => array_slice($details, 0, 100),
        ]);

        if ($this->source === 'csv' && file_exists($this->sourceRef)) {
            @unlink($this->sourceRef);
        }
    }

    private function putTracker(array $state): void
    {
        Cache::put("import:tracker:{$this->tracker}", $state, 1800);
    }
}
