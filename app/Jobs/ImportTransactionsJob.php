<?php

namespace App\Jobs;

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
 * Архитектура (правки 2026-05-22):
 *   STEP 1: чтение источника (Sheets API / CSV).
 *   STEP 2: валидация. ОДИН batch-SELECT по contract.number вместо
 *           1267 row-by-row. Закрытые периоды → warnings, пропуск.
 *           Любая другая ошибка → атомарный abort, ничего не вставляем.
 *   STEP 3: bulk INSERT чанками по 500 через `INSERT ... RETURNING id`
 *           (Postgres). Раньше — 1267 раздельных insertGetId.
 *
 * Расчёт комиссий БОЛЬШЕ НЕ запускается автоматически — оператор жмёт
 * «Рассчитать» в истории, когда удобно. Каскад наставников = ~5-10 мин
 * на 1267 транзакций, и держать поллинг прогресса всё это время — плохой
 * UX. Импорт теперь = только загрузка строк, ~5-10 секунд.
 */
class ImportTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;    // 10 минут — без авто-calc хватает с запасом на 5000+ строк
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

    public function handle(): void
    {
        $this->putTracker(['status' => 'running', 'total' => 0, 'processed' => 0, 'success' => 0, 'errors' => 0]);

        try {
            // === STEP 1: get rows (Sheets API call / CSV read) ===
            [$rows, , $resolvedCurrency, ] = $this->loadRows();
            $total = count($rows);

            if ($total === 0) {
                $this->finalizeError('Источник пустой или нет строк данных');
                return;
            }

            $this->putTracker([
                'status' => 'running', 'total' => $total, 'processed' => 0,
                'success' => 0, 'errors' => 0,
            ]);

            // Сразу пишем total_rows в лог — чтобы оператор в истории видел
            // сколько строк в источнике, ещё ДО завершения import'а (на
            // случай если упадём на валидации).
            DB::table('transaction_import_log')->where('id', $this->importLogId)->update([
                'total_rows' => $total,
                'updated_at' => now(),
            ]);

            // === STEP 2: validation (parse + match contract) ===
            // Курсы валют — раз, не на каждой строке.
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

            // Batch-загрузка контрактов: 1 SELECT вместо 1267 (раньше каждая
            // строка делала отдельный exact SELECT — горлышко валидации).
            $allNumbers = [];
            foreach ($rows as $row) {
                $n = trim((string) ($row['contract_number'] ?? $row['number'] ?? $row['номер_контракта'] ?? $row['contract'] ?? ''));
                if ($n !== '') $allNumbers[$n] = true;
            }
            $allNumbers = array_keys($allNumbers);
            $contractMap = $allNumbers
                ? DB::table('contract')
                    ->whereIn('number', $allNumbers)
                    ->whereNull('deletedAt')
                    ->get(['id', 'number', 'clientName'])
                    ->keyBy('number')
                : collect();

            // Локальный кэш period-freeze: для 1267 строк одного-двух
            // периодов вместо 1267 SELECT'ов делаем 1-2.
            $periodFreeze = app(\App\Services\PeriodFreezeService::class);
            $frozenCache = [];
            $isFrozen = function (int $y, int $m) use ($periodFreeze, &$frozenCache): bool {
                $key = "{$y}-{$m}";
                if (! array_key_exists($key, $frozenCache)) {
                    $frozenCache[$key] = $periodFreeze->isFrozen($y, $m);
                }
                return $frozenCache[$key];
            };

            $errors = [];
            $warnings = [];
            $prepared = [];
            foreach ($rows as $i => $row) {
                $lineNo = $i + 2;
                $contractNumber = trim((string) ($row['contract_number'] ?? $row['number'] ?? $row['номер_контракта'] ?? $row['contract'] ?? ''));
                $amount = (float) str_replace([' ', ','], ['', '.'], (string) ($row['amount'] ?? $row['сумма'] ?? $row['sum'] ?? '0'));
                $date = $row['date'] ?? $row['дата'] ?? $row['payment_date'] ?? null;

                if ($contractNumber === '') {
                    $errors[] = "Строка {$lineNo}: пустой номер контракта";
                    continue;
                }

                // 1) exact из batch-map (O(1))
                $contract = $contractMap->get($contractNumber);
                $matchedByIlike = false;
                if (! $contract) {
                    // 2) Fallback ilike: ТОЛЬКО при уникальном совпадении.
                    // Раньше брался первый попавшийся, и «1001» матчил
                    // «10010», «100123» — оператор получал warning и
                    // деньги уходили в чужой контракт. Теперь >1 совпадения
                    // = ошибка с перечислением кандидатов.
                    $candidates = DB::table('contract')
                        ->where('number', 'ilike', '%' . $contractNumber . '%')
                        ->whereNull('deletedAt')
                        ->limit(5)
                        ->get(['id', 'number', 'clientName']);
                    if ($candidates->count() === 1) {
                        $contract = $candidates->first();
                        $matchedByIlike = true;
                    } elseif ($candidates->count() > 1) {
                        $list = $candidates->pluck('number')->join(', ');
                        $errors[] = "Строка {$lineNo}: контракт «{$contractNumber}» — несколько совпадений ({$list}). Уточните номер для точного совпадения.";
                        continue;
                    }
                }
                if (! $contract) {
                    $errors[] = "Строка {$lineNo}: контракт «{$contractNumber}» не найден в БД (ни по точному, ни по частичному совпадению)";
                    continue;
                }

                // Period-freeze: строки в закрытом месяце ПРОПУСКАЕМ
                // (не валим импорт целиком).
                if ($date) {
                    $ts = strtotime($date);
                    if ($ts === false) {
                        $errors[] = "Строка {$lineNo}: не удалось распарсить дату «{$date}» (ожидается YYYY-MM-DD или DD.MM.YYYY)";
                        continue;
                    }
                    $year = (int) date('Y', $ts);
                    $month = (int) date('m', $ts);
                    if ($year && $month && $isFrozen($year, $month)) {
                        $warnings[] = sprintf(
                            'Строка %d: дата %s в закрытом периоде %02d.%d — строка пропущена.',
                            $lineNo, date('Y-m-d', $ts), $month, $year,
                        );
                        continue;
                    }
                }

                if ($amount <= 0) {
                    $errors[] = "Строка {$lineNo}: сумма должна быть > 0 (получено: «{$amount}»)";
                    continue;
                }

                if ($matchedByIlike && $contract->number !== $contractNumber) {
                    $warnings[] = sprintf(
                        'Строка %d: точного совпадения нет, найден по частичному → контракт «%s» (id %d, клиент %s). Проверьте.',
                        $lineNo,
                        $contract->number ?? '?',
                        $contract->id,
                        $contract->clientName ?? '—',
                    );
                }

                $amountRub = $amount * $currencyRate;
                $amountUsd = $usdRate > 0 ? $amountRub / $usdRate : 0;

                // ds_percent: «0,028» (русская локаль Excel) — приходит из
                // Google Sheets и валит PG bulk INSERT с invalid numeric
                // syntax. Нормализуем запятую → точка и проверяем тип.
                $dsPercentRaw = $row['ds_percent'] ?? $row['процент_дс'] ?? null;
                $dsPercent = null;
                if ($dsPercentRaw !== null && $dsPercentRaw !== '') {
                    $s = str_replace([' ', ','], ['', '.'], (string) $dsPercentRaw);
                    if (is_numeric($s)) $dsPercent = (float) $s;
                }

                // property → commissionCalcProperty.id. Принимаем:
                //   - числовой id (профиль уже резолвил, напр. IB MF → 9)
                //   - строку-название («МФ»/«Апфронт»/«5 год») — резолвим
                //     через commissionCalcProperty.title (case-insensitive).
                $propertyRaw = $row['property'] ?? $row['свойство'] ?? null;
                $propertyId = null;
                if ($propertyRaw !== null && $propertyRaw !== '') {
                    if (is_numeric($propertyRaw)) {
                        $propertyId = (int) $propertyRaw;
                    } else {
                        $propertyId = $this->resolvePropertyId((string) $propertyRaw);
                    }
                }

                $prepared[] = [
                    'line' => $lineNo,
                    'contract_id' => $contract->id,
                    'amount' => $amount,
                    'amountRub' => $amountRub,
                    'amountUsd' => $amountUsd,
                    'date' => $date,
                    'ds_percent' => $dsPercent,
                    'property' => $propertyId,
                ];

                // Tracker — каждые 200 строк (вместо 50: меньше cache-overhead).
                if (($i + 1) % 200 === 0 || $i === $total - 1) {
                    $this->putTracker([
                        'status' => 'running', 'total' => $total, 'processed' => $i + 1,
                        'success' => 0, 'errors' => count($errors),
                        'phase' => 'validate',
                    ]);
                }
            }

            // Атомарность: если есть ошибки валидации — ничего не вставляем.
            // Закрытые периоды НЕ считаются ошибкой (они в warnings).
            if ($errors) {
                $this->finalizeError(
                    'Импорт отменён: найдено ' . count($errors) . ' ошибок валидации. Ничего не загружено. См. список ниже.',
                    $errors,
                    $warnings,
                );
                return;
            }

            if (empty($prepared)) {
                // Все строки в закрытых периодах — нечего вставлять.
                $this->finalizeSkipped(
                    'Импорт завершён: все строки в закрытых периодах, ничего не загружено.',
                    $warnings,
                );
                return;
            }

            // === STEP 3: bulk INSERT (chunks по 500, RETURNING id) ===
            // Раньше: 1267 раздельных insertGetId внутри одной DB::transaction
            // (1267 round-trip к Postgres). Теперь: ~3 INSERT'а на чанк по
            // 500 строк с RETURNING — на порядок быстрее.
            $createdIds = [];
            $this->putTracker([
                'status' => 'running', 'total' => $total, 'processed' => $total,
                'success' => 0, 'errors' => 0, 'phase' => 'insert',
            ]);

            try {
                DB::beginTransaction();
                foreach (array_chunk($prepared, 500) as $chunk) {
                    $ids = $this->bulkInsertChunk($chunk, $resolvedCurrency, $currencyRate);
                    $createdIds = array_merge($createdIds, $ids);

                    $this->putTracker([
                        'status' => 'running', 'total' => $total, 'processed' => $total,
                        'success' => count($createdIds), 'errors' => 0, 'phase' => 'insert',
                    ]);
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->finalizeError(
                    'Импорт отменён из-за ошибки БД. Ничего не загружено.',
                    $this->parseSqlError($e),
                    $warnings,
                );
                Log::error('Import bulk insert failed', [
                    'importId' => $this->importLogId, 'error' => $e->getMessage(),
                ]);
                return;
            }

            $successCount = count($createdIds);

            // STEP 4 (авто-расчёт комиссий) намеренно убран: расчёт каскадом
            // наставников — самая медленная часть и занимает ~80-95% всего
            // времени. Оператор запускает расчёт явно кнопкой «Рассчитать»
            // в истории импортов, когда удобно. Импорт = только загрузка.
            $update = [
                'status' => 'success',
                'total_rows' => $total,
                'success_count' => $successCount,
                'error_count' => 0,
                'created_ids' => json_encode($createdIds),
                'errors' => null,
                'updated_at' => now(),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('transaction_import_log', 'warnings')) {
                $update['warnings'] = $warnings ? json_encode($warnings, JSON_UNESCAPED_UNICODE) : null;
            }
            DB::table('transaction_import_log')->where('id', $this->importLogId)->update($update);

            $msg = "Импорт завершён: {$successCount} транзакций загружено";
            if ($warnings) $msg .= ', предупреждений: ' . count($warnings);
            $msg .= '. Запустите расчёт комиссий кнопкой «Рассчитать» в истории.';

            $this->putTracker([
                'status' => 'done', 'total' => $total, 'processed' => $total,
                'success' => $successCount, 'errors' => 0,
                'warnings' => count($warnings),
                'importId' => $this->importLogId, 'message' => $msg,
                'needsCalc' => true,
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

                // commissionCalcProperty: дефолт из профиля (например, IB MF → 9,
                // IB UP → 10), плюс fallback на колонку «Свойство» / «property»
                // в самом листе для профилей, где свойство per-row.
                $profileProperty = isset($profile['commissionCalcProperty'])
                    ? (int) $profile['commissionCalcProperty'] : null;
                $propertyHeaderIdx = null;
                foreach ($headers as $i => $h) {
                    $hLower = mb_strtolower(trim((string) $h));
                    if ($hLower === 'свойство' || $hLower === 'property') {
                        $propertyHeaderIdx = $i;
                        break;
                    }
                }

                $rows = [];
                foreach ($rawRows as $row) {
                    $a = SheetProfiles::alignRow($row, $headers, $profile);
                    $rowProperty = $profileProperty;
                    if ($propertyHeaderIdx !== null && ! empty($row[$propertyHeaderIdx])) {
                        $rowProperty = $row[$propertyHeaderIdx];
                    }
                    $rows[] = [
                        'contract_number' => (string) ($a['contract_number'] ?? ''),
                        'amount' => $a['amount'] ?? ($a['commission'] ?? 0),
                        'date' => $a['date'] ?? null,
                        'ds_percent' => $a['commission_pct'] ?? null,
                        'property' => $rowProperty,
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
    private function finalizeError(string $message, array $details = [], array $warnings = []): void
    {
        $update = [
            'status' => 'error',
            'success_count' => 0,
            'error_count' => max(1, count($details)),
            'errors' => json_encode(array_slice($details, 0, 100) ?: [$message], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ];
        if ($warnings && \Illuminate\Support\Facades\Schema::hasColumn('transaction_import_log', 'warnings')) {
            $update['warnings'] = json_encode($warnings, JSON_UNESCAPED_UNICODE);
        }
        DB::table('transaction_import_log')->where('id', $this->importLogId)->update($update);

        $current = Cache::get("import:tracker:{$this->tracker}") ?? [];
        $this->putTracker([
            'status' => 'done',
            'total' => $current['total'] ?? 0,
            'processed' => $current['total'] ?? 0,
            'success' => 0,
            'errors' => max(1, count($details)),
            'warnings' => count($warnings),
            'importId' => $this->importLogId,
            'message' => $message,
            'errorDetails' => array_slice($details, 0, 100),
        ]);

        if ($this->source === 'csv' && file_exists($this->sourceRef)) {
            @unlink($this->sourceRef);
        }
    }

    /**
     * Все строки попали в закрытый период — ничего не загрузили, но это
     * не ошибка. Status=success, success_count=0, всё в warnings.
     */
    private function finalizeSkipped(string $message, array $warnings = []): void
    {
        $update = [
            'status' => 'success',
            'success_count' => 0,
            'error_count' => 0,
            'errors' => null,
            'updated_at' => now(),
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('transaction_import_log', 'warnings')) {
            $update['warnings'] = $warnings ? json_encode($warnings, JSON_UNESCAPED_UNICODE) : null;
        }
        DB::table('transaction_import_log')->where('id', $this->importLogId)->update($update);

        $current = Cache::get("import:tracker:{$this->tracker}") ?? [];
        $this->putTracker([
            'status' => 'done',
            'total' => $current['total'] ?? 0,
            'processed' => $current['total'] ?? 0,
            'success' => 0,
            'errors' => 0,
            'warnings' => count($warnings),
            'importId' => $this->importLogId,
            'message' => $message,
        ]);

        if ($this->source === 'csv' && file_exists($this->sourceRef)) {
            @unlink($this->sourceRef);
        }
    }

    /**
     * Bulk INSERT чанка строк в "transaction" с RETURNING id (Postgres).
     * Возвращает массив новых id в порядке вставки.
     *
     * 12 колонок × 500 строк = 6000 параметров (Postgres лимит 65535 — ок).
     */
    private function bulkInsertChunk(array $chunk, ?int $currency, float $currencyRate): array
    {
        if (! $chunk) return [];

        $columns = ['contract', 'amount', 'amountRUB', 'amountUSD', 'currency',
            'currencyRate', 'date', 'dateMonth', 'dateYear', 'comment',
            'dsCommissionPercentage', 'commissionCalcProperty'];
        $quotedCols = array_map(fn ($c) => '"' . $c . '"', $columns);

        $placeholderRow = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(',', array_fill(0, count($chunk), $placeholderRow));

        $bindings = [];
        $comment = 'Импорт #' . $this->importLogId;
        foreach ($chunk as $p) {
            $ts = $p['date'] ? strtotime($p['date']) : false;
            $bindings[] = $p['contract_id'];
            $bindings[] = $p['amount'];
            $bindings[] = round($p['amountRub'], 2);
            $bindings[] = round($p['amountUsd'], 2);
            $bindings[] = $currency;
            $bindings[] = $currencyRate;
            $bindings[] = $ts !== false ? date('Y-m-d\TH:i:s', $ts) : now()->toIso8601String();
            $bindings[] = $ts !== false ? date('Y-m', $ts) : now()->format('Y-m');
            $bindings[] = $ts !== false ? date('Y', $ts) : now()->format('Y');
            $bindings[] = $comment;
            $bindings[] = $p['ds_percent'];
            $bindings[] = $p['property'];
        }

        $sql = 'INSERT INTO "transaction" (' . implode(',', $quotedCols) . ') VALUES '
            . $placeholders . ' RETURNING id';

        $rows = DB::select($sql, $bindings);
        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /**
     * Расшифровка PDO/PG-ошибок в человеко-читаемые сообщения для
     * операторов. Тупо смотреть «SQLSTATE[23502]:...» бесполезно —
     * нужно сказать «поле X обязательное» или «контракт N не существует».
     */
    private function parseSqlError(\Throwable $e): array
    {
        $raw = $e->getMessage();
        $out = [];

        // NOT NULL violation: "null value in column X violates not-null"
        if (preg_match('/null value in column\s+"?([^"\s]+)"?[^"]*violates not-null/i', $raw, $m)) {
            $out[] = "В одной из строк пустое обязательное поле «{$m[1]}» — заполните в источнике и перезалейте.";
        }
        // FK violation: "violates foreign key constraint ... Key (col)=(val) is not present"
        if (preg_match('/violates foreign key constraint.*Key\s*\(([^)]+)\)\s*=\s*\(([^)]+)\)\s*is not present/is', $raw, $m)) {
            $out[] = "Внешний ключ: «{$m[1]}»={$m[2]} не существует в БД (контракт/валюта/контрагент удалён или ID опечатан).";
        }
        // Duplicate key
        if (preg_match('/duplicate key value violates unique constraint\s+"([^"]+)"/i', $raw, $m)) {
            $out[] = "Дубликат: нарушено уникальное ограничение «{$m[1]}». Возможно эти транзакции уже импортированы.";
        }
        // Invalid type / out of range
        if (preg_match('/invalid input syntax for type\s+(\w+):\s*"([^"]*)"/i', $raw, $m)) {
            $out[] = "Неверный формат данных: ожидался тип «{$m[1]}», получено «{$m[2]}». Проверьте формат сумм/дат в файле.";
        }
        if (! $out) {
            // Fallback: вытаскиваем хотя бы первую содержательную строку.
            $first = trim(explode("\n", $raw)[0]);
            $out[] = 'Ошибка БД: ' . mb_substr($first, 0, 400);
        }
        $out[] = 'Полный текст ошибки в логе job_failed (importId=' . $this->importLogId . ').';
        return $out;
    }

    private function putTracker(array $state): void
    {
        Cache::put("import:tracker:{$this->tracker}", $state, 1800);
    }

    /**
     * Кэшируем lookup commissionCalcProperty.title → id на время импорта.
     * Размер справочника ~30 строк, читаем целиком при первом обращении —
     * иначе на 1267 строк × ILIKE-запрос даём ненужную нагрузку.
     */
    private ?array $propertyTitleMap = null;
    private function resolvePropertyId(string $title): ?int
    {
        if ($this->propertyTitleMap === null) {
            $this->propertyTitleMap = [];
            foreach (DB::table('commissionCalcProperty')->get(['id', 'title']) as $p) {
                $this->propertyTitleMap[mb_strtolower(trim((string) $p->title))] = (int) $p->id;
            }
        }
        $key = mb_strtolower(trim($title));
        if (isset($this->propertyTitleMap[$key])) return $this->propertyTitleMap[$key];

        // Лёгкие алиасы: «MF», «UP» — английские варианты МФ/Апфронт.
        $aliases = [
            'mf' => 'мф',
            'up' => 'апфронт',
            'upfront' => 'апфронт',
        ];
        if (isset($aliases[$key], $this->propertyTitleMap[$aliases[$key]])) {
            return $this->propertyTitleMap[$aliases[$key]];
        }
        return null;
    }
}
