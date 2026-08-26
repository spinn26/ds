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
     * Расхождение профиля с фактической шапкой листа (поставщик переименовал
     * колонку). Заполняется при чтении источника и подмешивается ПЕРВОЙ строкой
     * в ошибки/предупреждения импорта — иначе оператор видит только «пустой
     * номер контракта» на каждой строке и не понимает причину.
     */
    private ?string $profileWarning = null;

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

            // === STEP 2: валидация строк — в TransactionImportValidator ===
            $checked = app(\App\Services\TransactionImportValidator::class)
                ->validate($rows, $resolvedCurrency, $this->profileWarning,
                    function (int $processed, int $errorCount) use ($total) {
                        $this->putTracker([
                            'status' => 'running', 'total' => $total, 'processed' => $processed,
                            'success' => 0, 'errors' => $errorCount,
                            'phase' => 'validate',
                        ]);
                    });
            $prepared = $checked['prepared'];
            $errors = $checked['errors'];
            $warnings = $checked['warnings'];

            // Атомарность: если есть ошибки валидации — ничего не вставляем.
            // Закрытые периоды НЕ считаются ошибкой (они в warnings).
            if ($errors) {
                $count = count($errors);
                // Импорт всё равно падает — выносим причину наверх списка.
                // Без этого оператор видит 172 одинаковые строки «пустой номер
                // контракта» и не понимает, что поставщик переименовал колонку.
                if ($this->profileWarning) {
                    array_unshift($errors, $this->profileWarning);
                }
                $this->finalizeError(
                    'Импорт отменён: найдено ' . $count . ' ошибок валидации. Ничего не загружено. См. список ниже.',
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
                    $ids = $this->bulkInsertChunk($chunk, $resolvedCurrency);
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

            // Лист БЕЗ строки заголовков (напр. IB MF: первая строка пустая,
            // дальше сразу данные). Если профиль объявляет позиционные заголовки
            // 'headerless' и фактическая шапка пустая — берём заголовки из
            // профиля и трактуем ВСЕ строки как данные. Иначе alignRow не мапит
            // ничего → импорт падает (история: IB MF «0 / 187 ош.»).
            if ($profile && ! empty($profile['headerless'])
                && count(array_filter($headers, fn ($h) => trim((string) $h) !== '')) === 0) {
                $headers = $profile['headerless'];
                $rawRows = $values;
            }

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
                // IB UP → 10), плюс fallback на per-row колонку свойства в листе.
                //
                // Детект по ВХОЖДЕНИЮ (не ===): реальные выгрузки Робо разделяют
                // МФ/Апфронт колонкой «Вид услуги» / «Свойство продукта», с лишними
                // пробелами и регистром. Прежнее точное сравнение не срабатывало →
                // все строки падали в хардкод-дефолт МФ (9), и Апфронт-строки
                // импортировались с неверным свойством и %ДС. NBSP в заголовке
                // тоже нормализуем (Sheets отдаёт неразрывные пробелы).
                $profileProperty = isset($profile['commissionCalcProperty'])
                    ? (int) $profile['commissionCalcProperty'] : null;
                $propertyHeaderIdx = null;
                foreach ($headers as $i => $h) {
                    $hLower = preg_replace('/[\pZ\s]+/u', ' ', mb_strtolower(trim((string) $h)));
                    if (str_contains($hLower, 'свойство') || str_contains($hLower, 'property')
                        || str_contains($hLower, 'вид услуг')) {
                        $propertyHeaderIdx = $i;
                        break;
                    }
                }

                // commission_pct_scale: часть профилей (InvestorsTrust) хранит
                // ставку ДОЛЕЙ («Размер комиссии» 0.055 = 5.5%), а не процентами.
                // Платформа хранит dsCommissionPercentage в процентах → без ×100
                // импорт давал «очень маленькие» проценты и доход/100.
                $pctScale = isset($profile['commission_pct_scale'])
                    ? (float) $profile['commission_pct_scale'] : 1.0;

                // Колонки профиля, которых в листе нет. Поставщик переименовал
                // заголовок — и импорт валится «пустой номер контракта» на всех
                // строках подряд, не говоря, в чём дело (IB MF, 172 строки:
                // «ID сделки» стало «Контракт»). Сообщаем прямо, с фактической
                // шапкой листа, чтобы правка занимала минуту.
                $missingColumns = [];
                foreach ($profile['fields'] as $canonical => $headerName) {
                    if (SheetProfiles::headerIndex($headers, $headerName) === null) {
                        $missingColumns[] = is_array($headerName)
                            ? implode(' / ', $headerName)
                            : (string) $headerName;
                    }
                }
                if ($missingColumns) {
                    $present = implode(', ', array_filter(array_map(
                        fn ($h) => trim((string) $h), $headers
                    )));
                    $this->profileWarning = sprintf(
                        'В листе «%s» не найдены колонки профиля: %s. Фактическая шапка: %s.',
                        $this->sourceRef,
                        implode('; ', $missingColumns),
                        $present !== '' ? $present : '(пустая)',
                    );
                }

                $rows = [];
                foreach ($rawRows as $row) {
                    $a = SheetProfiles::alignRow($row, $headers, $profile);
                    $rowProperty = $profileProperty;
                    if ($propertyHeaderIdx !== null && ! empty($row[$propertyHeaderIdx])) {
                        $rowProperty = $row[$propertyHeaderIdx];
                    }

                    // Per-row валюта (профили с колонкой «Валюта»: Trust USD/EUR,
                    // Woodville, Medlife…). null → упадём на currency импорта.
                    $rowCurrency = null;
                    if (! empty($a['currency'])) {
                        $rowCurrency = SheetProfiles::resolveCurrencyId((string) $a['currency'], null);
                    }

                    $dsPercent = $a['commission_pct'] ?? null;
                    if ($dsPercent !== null && $dsPercent !== '' && $pctScale !== 1.0) {
                        $v = \App\Support\Numbers::decimal($dsPercent, 0);
                        if ($v !== 0.0) $dsPercent = round($v * $pctScale, 6);
                    }

                    // Сумма контракта: основная колонка профиля, при пустом
                    // значении — запасная (ГГА: «исходник» → «база»).
                    $rowAmount = $a['amount'] ?? null;
                    if (($rowAmount === null || $rowAmount === '' || \App\Support\Numbers::decimal($rowAmount, 0) == 0.0)
                        && ! empty($a['amount_fallback'])) {
                        $rowAmount = $a['amount_fallback'];
                    }
                    $rowAmount ??= ($a['commission'] ?? 0);

                    // «Своя комиссия»: сумма комиссии из отчёта — это доход ДС
                    // как есть, а ставка выводится из неё, а не берётся из
                    // отчёта. Включается либо на весь профиль
                    // ('custom_commission' — ГГА), либо только для отдельных
                    // свойств ('custom_commission_properties' — Робо: МФ идёт
                    // по факту оплаты, Апфронт остаётся на тарифе 2%).
                    $customCommission = false;
                    $commissionAbs = null;
                    $customByProperty = false;
                    if (empty($profile['custom_commission']) && ! empty($profile['custom_commission_properties'])) {
                        $pid = SheetProfiles::resolvePropertyId($rowProperty);
                        $customByProperty = $pid !== null
                            && in_array($pid, (array) $profile['custom_commission_properties'], true);
                    }
                    $customMissing = false;
                    if (! empty($profile['custom_commission']) || $customByProperty) {
                        $commRaw = \App\Support\Numbers::decimal($a['commission'] ?? null, 0);
                        $base = \App\Support\Numbers::decimal($rowAmount, 0);

                        // ⚠ dsCommissionAbsolute хранится БЕЗ НДС (так его
                        // читает CommissionCalculator). У ГГА сумма в отчёте
                        // уже без НДС, а у робо — С НДС: брокер платит доход ДС
                        // целиком. Без снятия НДС файловая 1 000 ₽ ложилась в
                        // «Доход ДС без НДС», а «Доход ДС» раздувался до 1 050 ₽.
                        $comm = $commRaw;
                        if (! empty($profile['custom_commission_gross'])) {
                            $vat = \App\Support\VatRate::percentOrDefault($a['date'] ?? null);
                            if ($vat > 0) {
                                $comm = $commRaw / (1 + $vat / 100);
                            }
                        }

                        if (abs($comm) > 0.000001) {
                            $customCommission = true;
                            $commissionAbs = $comm;
                            // %ДС по ТЗ: (сумма комиссии / сумма контракта) × 100.
                            // Для «с НДС» берём исходные величины отчёта: НДС
                            // сокращается (доход_без_НДС / база_без_НДС), а
                            // делить снятую сумму на базу С НДС было бы неверно.
                            $pctNum = ! empty($profile['custom_commission_gross']) ? $commRaw : $comm;
                            $dsPercent = abs($base) > 0.000001
                                ? round($pctNum / $base * 100, 6)
                                : null;
                        } else {
                            // Пустая/нулевая сумма комиссии — строка уходит на
                            // тариф из «Продуктов». Молча это делать нельзя:
                            // для МФ тариф заведомо расходится с фактом оплаты.
                            $customMissing = true;
                        }
                    }

                    $rows[] = [
                        'contract_number' => (string) ($a['contract_number'] ?? ''),
                        'amount' => $rowAmount,
                        'date' => $a['date'] ?? null,
                        'ds_percent' => $dsPercent,
                        'property' => $rowProperty,
                        'currency' => $rowCurrency,
                        // «Год КВ» (score) — напр. Trust-профиль: колонка «Год».
                        // Раньше терялась → в транзакции score=NULL, «Год КВ» пустой.
                        'year' => $a['year'] ?? null,
                        'custom_commission' => $customCommission,
                        'commission_abs' => $commissionAbs,
                        'custom_commission_missing' => $customMissing,
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

        // ⚠ mb_strtolower, НЕ strtolower: последний работает побайтово и
        // кириллицу не трогает. Из-за этого «Сумма» и «Дата» — самые обычные
        // заголовки русской выгрузки — не совпадали с ключевыми словами ниже
        // НИКОГДА: колонки терялись молча, сумма уходила в 0 (ноль разрешён
        // осознанно, для сделок без движения денег), дата подменялась
        // сегодняшней, а импорт при этом рапортовал success. Совпадение
        // случалось, только если ключевое слово стояло внутри заголовка уже
        // строчным («Общая сумма» проходило, «Сумма» — нет).
        $headers = array_map(
            fn ($h) => mb_strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', (string) $h))),
            $headers
        );

        $headerMap = [];
        foreach ($headers as $i => $h) {
            if (str_contains($h, 'контракт') || str_contains($h, 'contract') || str_contains($h, 'номер')) {
                $headerMap[$i] = 'contract_number';
            } elseif (str_contains($h, 'сумма') || str_contains($h, 'amount') || str_contains($h, 'sum')) {
                $headerMap[$i] = 'amount';
            } elseif (str_contains($h, 'дата') || str_contains($h, 'date')) {
                $headerMap[$i] = 'date';
            } elseif (str_contains($h, 'свойство') || str_contains($h, 'property') || str_contains($h, 'вид услуг')) {
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
    private function bulkInsertChunk(array $chunk, ?int $fallbackCurrency): array
    {
        if (! $chunk) return [];

        // Выравниваем PK-сиквенс перед вставкой. Вставляем БЕЗ id, полагаясь на
        // сиквенс, а он отстаёт всякий раз, когда кто-то пишет id явно (так
        // делал вебхук Insmart через LegacyId::next). Тогда импорт падал на
        // «нарушено уникальное ограничение transaction_pkey» и отменялся
        // целиком — 1195 строк робоэдвайзера, 10.08.2026. setval идемпотентен и
        // дёшев; источник лага починен отдельно, но защита нужна и здесь:
        // явные id может проставить любой будущий импорт данных.
        \App\Support\LegacyId::syncSequence('transaction');

        $columns = ['contract', 'amount', 'amountRUB', 'amountUSD', 'currency',
            'currencyRate', 'date', 'dateMonth', 'dateYear', 'comment',
            'dsCommissionPercentage', 'commissionCalcProperty', 'score',
            // «Своя комиссия»: доход ДС берётся из отчёта поставщика, а не
            // считается по тарифу (профили с custom_commission — ГГА).
            'customCommission', 'dsCommissionAbsolute'];
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
            // Per-row валюта/курс (Trust USD/EUR). Fallback — валюта импорта.
            $bindings[] = ($p['currency'] ?? null) ?: $fallbackCurrency;
            $bindings[] = $p['currencyRate'] ?? 1.0;
            $bindings[] = $ts !== false ? date('Y-m-d\TH:i:s', $ts) : now()->toIso8601String();
            $bindings[] = $ts !== false ? date('Y-m', $ts) : now()->format('Y-m');
            $bindings[] = $ts !== false ? date('Y', $ts) : now()->format('Y');
            $bindings[] = $comment;
            $bindings[] = $p['ds_percent'];
            $bindings[] = $p['property'];
            // score = «Год КВ» (год выплаты вознаграждения), напр. Trust.
            $bindings[] = (isset($p['year']) && $p['year'] !== '')
                ? (int) $p['year'] : null;
            $bindings[] = ! empty($p['custom_commission']);
            // dsCommissionAbsolute хранится БЕЗ НДС — так его читает
            // CommissionCalculator, выводя %ДС обратным расчётом от суммы без
            // НДС. Сумма комиссии в отчёте ГГА — это доход ДС, НДС в ней нет,
            // поэтому пишем как есть.
            $bindings[] = $p['commission_abs'] ?? null;
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

}
