<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PeriodFreezeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransactionImportController extends Controller
{
    public function __construct(
        private readonly PeriodFreezeService $periodFreeze,
    ) {}


    /**
     * Справочники для формы импорта.
     */
    public function formData(): JsonResponse
    {
        $counterparties = DB::table('counterparty')
            ->orderBy('counterpartyName')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->counterpartyName]);

        // Оставили только RUB/USD/EUR/GBP — остальные валюты в селекторах
        // почти никогда не нужны. Управляется через currency.selectable
        // (миграция 2026_04_21_000020).
        $currencies = DB::table('currency')
            ->where('selectable', true)
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'symbol' => $c->symbol, 'name' => $c->nameRu ?? $c->currencyName]);

        return response()->json([
            'counterparties' => $counterparties,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Получить список листов из Google Sheets (= список поставщиков).
     */
    public function sheetNames(): JsonResponse
    {
        // Read from api_settings first (admin UI), fall back to legacy env.
        $settings = app(\App\Services\ApiSettingsService::class);
        $spreadsheetId = $settings->get('google.sheets.transactions_id',
            config('services.google_sheets.spreadsheet_id', env('GOOGLE_SHEETS_SPREADSHEET_ID')));
        $apiKey = $settings->get('google.sheets.api_key',
            config('services.google_sheets.api_key', env('GOOGLE_SHEETS_API_KEY')));

        if (! $spreadsheetId || ! $apiKey) {
            $missing = array_filter([
                ! $apiKey        ? '«Google Sheets API Key»' : null,
                ! $spreadsheetId ? '«ID таблицы Импорт транзакций»' : null,
            ]);
            return response()->json([
                'sheets' => [],
                'message' => 'Не заполнено: ' . implode(' и ', $missing) . '. См. /admin/api-keys',
            ]);
        }

        $reader = app(\App\Services\GoogleSheetsReader::class);
        $sheets = $reader->getSheetNames($spreadsheetId, $apiKey);

        if (empty($sheets)) {
            return response()->json([
                'sheets' => [],
                'message' => 'Не удалось получить список листов. Проверь валидность API-ключа и что таблица расшарена «Anyone with link».',
            ]);
        }

        // Обогащаем каждый лист инфой о профиле (авто-распознанный counterparty).
        $withMeta = array_map(function ($name) {
            $profile = \App\Services\SheetProfiles::profile($name);
            return [
                'name' => $name,
                'profiled' => $profile !== null,
                'counterpartyName' => $profile['counterpartyName'] ?? null,
                'currency' => $profile['currency'] ?? null,
                'productHint' => $profile['productHint'] ?? null,
            ];
        }, $sheets);

        return response()->json(['sheets' => $withMeta]);
    }

    /**
     * Импорт из Google Sheets — выбрать лист (поставщика) → загрузить данные.
     *
     * Если лист есть в SheetProfiles::PROFILES — counterparty и маппинг
     * колонок берутся автоматически из профиля (пользовательский выбор
     * counterparty/currency игнорируется в пользу профиля). Иначе
     * используется generic-парсер.
     */
    public function importFromSheets(Request $request): JsonResponse
    {
        $request->validate([
            'sheet' => 'required|string',
            'counterparty' => 'nullable|integer',
            'currency' => 'nullable|integer',
        ]);

        // Read from api_settings first (admin UI), fall back to legacy env.
        $settings = app(\App\Services\ApiSettingsService::class);
        $spreadsheetId = $settings->get('google.sheets.transactions_id',
            config('services.google_sheets.spreadsheet_id', env('GOOGLE_SHEETS_SPREADSHEET_ID')));
        $apiKey = $settings->get('google.sheets.api_key',
            config('services.google_sheets.api_key', env('GOOGLE_SHEETS_API_KEY')));

        if (! $spreadsheetId || ! $apiKey) {
            return response()->json(['message' => 'Google Sheets не настроен. Заполните «Google Sheets API Key» и «ID таблицы Импорт транзакций» в /admin/api-keys'], 422);
        }

        // Прямой запрос в Sheets API — нам нужна и шапка, и строки, generic-
        // reader приводит к ассоциативным уже с header-стрипом.
        $range = urlencode($request->sheet);
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?key={$apiKey}&majorDimension=ROWS";
        $response = \Illuminate\Support\Facades\Http::timeout(20)->get($url);
        if (! $response->ok()) {
            \Log::error('Sheet read failed', ['status' => $response->status(), 'body' => mb_substr((string) $response->body(), 0, 200)]);
            return response()->json(['message' => "Ошибка чтения листа: HTTP {$response->status()}. Проверьте ID и название листа."], 422);
        }

        $values = $response->json('values') ?? [];
        if (count($values) < 2) {
            return response()->json(['message' => 'Лист пустой или нет строк данных'], 422);
        }

        $headers = $values[0];
        $rows = array_slice($values, 1);

        $profile = \App\Services\SheetProfiles::profile($request->sheet);
        if ($profile) {
            return $this->processProfiledRows($headers, $rows, $profile, $request);
        }

        // Fallback: старый generic-парсер требует counterparty явно.
        if (! $request->counterparty) {
            return response()->json(['message' => 'Лист не распознан в профилях. Выберите поставщика вручную.'], 422);
        }
        $assoc = array_map(function ($row) use ($headers) {
            $out = [];
            foreach ($headers as $i => $h) $out[mb_strtolower(trim($h))] = $row[$i] ?? null;
            return $out;
        }, $rows);
        return $this->processRows($assoc, (int) $request->counterparty, $request->currency ? (int) $request->currency : 67, $request);
    }

    /**
     * Загрузить и обработать CSV файл с транзакциями.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx|max:10240',
            'counterparty' => 'required|integer',
            'currency' => 'nullable|integer',
        ]);

        $file = $request->file('file');
        $rows = $this->parseCsv($file->getPathname());

        if (empty($rows)) {
            return response()->json(['message' => 'Файл пустой или неверный формат'], 422);
        }

        return $this->processRows($rows, (int) $request->counterparty, $request->currency ? (int) $request->currency : 67, $request);
    }

    /**
     * Общая логика обработки строк (для файла и Google Sheets).
     *
     * Правила (заказ продакта 2026-05-21):
     *  1. Никаких проверок на дубли — оператор сам отвечает за повторный
     *     залив того же файла.
     *  2. Импорт атомарный «всё или ничего»: если на ЭТАПЕ ВАЛИДАЦИИ хоть
     *     одна строка не прошла (пустой номер контракта, контракт не
     *     найден), НИЧЕГО не вставляется — возвращаем 422 со списком
     *     ошибок, transaction_import_log не создаётся.
     *  3. Если все валидны — INSERT внутри DB::transaction(). Любая ошибка
     *     INSERT'а — rollback всего файла.
     *  4. После успешного commit'а сразу запускается расчёт комиссий
     *     по всем импортированным транзакциям.
     */
    private function processRows(array $rows, int $counterpartyId, int $currencyId, Request $request): JsonResponse
    {
        $this->ensureImportLogTable();

        $tracker = $request->input('tracker');
        $totalRows = count($rows);

        if ($tracker) {
            \Illuminate\Support\Facades\Cache::put(
                "import:tracker:{$tracker}",
                ['total' => $totalRows, 'processed' => 0, 'success' => 0, 'errors' => 0, 'status' => 'running'],
                600,
            );
        }

        // === PHASE 1: validation ===
        // Парсим, матчим контракт, готовим payload — НЕ пишем в БД. На любой
        // ошибке копим её, продолжаем дальше: оператор получает полный список
        // того, что нужно исправить в файле.
        $errors = [];
        $prepared = []; // [[i => int, contract => row, amount, date, raw], ...]

        // Курс целевой валюты — один на весь импорт (currencyRate колонки
        // не зависят от даты строки в текущей логике).
        $currencyRate = 1.0;
        if ($currencyId !== 67) {
            $rate = DB::table('currencyRate')
                ->where('currency', $currencyId)
                ->orderByDesc('date')
                ->first();
            $currencyRate = (float) ($rate->rate ?? 1);
        }
        $usdRate = 1.0;
        $rateUsd = DB::table('currencyRate')->where('currency', 5)->orderByDesc('date')->first();
        if ($rateUsd) $usdRate = (float) $rateUsd->rate;

        foreach ($rows as $i => $row) {
            $contractNumber = trim($row['contract_number'] ?? $row['number'] ?? $row['номер_контракта'] ?? $row['contract'] ?? '');
            $amount = (float) str_replace([' ', ','], ['', '.'], $row['amount'] ?? $row['сумма'] ?? $row['sum'] ?? '0');
            $date = $row['date'] ?? $row['дата'] ?? $row['payment_date'] ?? null;

            if (empty($contractNumber)) {
                $errors[] = "Строка " . ($i + 2) . ": пустой номер контракта";
                continue;
            }

            // Матчинг — ищем контракт по точному номеру, потом по ilike.
            $contract = DB::table('contract')
                ->where('number', $contractNumber)
                ->whereNull('deletedAt')
                ->first();
            if (! $contract) {
                $contract = DB::table('contract')
                    ->where('number', 'ilike', '%' . $contractNumber . '%')
                    ->whereNull('deletedAt')
                    ->first();
            }
            if (! $contract) {
                $errors[] = "Строка " . ($i + 2) . ": контракт «{$contractNumber}» не найден";
                continue;
            }

            $amountRub = $amount * $currencyRate;
            $amountUsd = $usdRate > 0 ? $amountRub / $usdRate : 0;

            $prepared[] = [
                'i' => $i,
                'contract_id' => $contract->id,
                'amount' => $amount,
                'amountRub' => $amountRub,
                'amountUsd' => $amountUsd,
                'date' => $date,
                'ds_percent' => $row['ds_percent'] ?? $row['процент_дс'] ?? null,
                'property' => $row['property'] ?? $row['свойство'] ?? null,
            ];
        }

        if ($errors) {
            // Ничего не вставляем, лог импорта не создаём.
            if ($tracker) {
                \Illuminate\Support\Facades\Cache::put(
                    "import:tracker:{$tracker}",
                    [
                        'total' => $totalRows,
                        'processed' => $totalRows,
                        'success' => 0,
                        'errors' => count($errors),
                        'status' => 'done',
                    ],
                    600,
                );
            }
            return response()->json([
                'message' => 'Импорт отменён: найдено ' . count($errors) . ' ошибок. Ничего не загружено.',
                'success' => 0,
                'errors' => count($errors),
                'errorDetails' => array_slice($errors, 0, 100),
            ], 422);
        }

        // === PHASE 2: atomic insert + auto-calc ===
        // Создаём лог + вставляем строки в одной DB::transaction(). Любая
        // ошибка INSERT'а откатит файл целиком.
        $importLogId = null;
        $createdIds = [];
        try {
            [$importLogId, $createdIds] = DB::transaction(function () use (
                $prepared, $counterpartyId, $currencyId, $currencyRate, $totalRows, $request
            ) {
                $logId = DB::table('transaction_import_log')->insertGetId([
                    'counterparty' => $counterpartyId,
                    'currency' => $currencyId,
                    'status' => 'processing',
                    'total_rows' => $totalRows,
                    'success_count' => 0,
                    'error_count' => 0,
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                ]);

                $ids = [];
                foreach ($prepared as $p) {
                    $txId = DB::table('transaction')->insertGetId([
                        'contract' => $p['contract_id'],
                        'amount' => $p['amount'],
                        'amountRUB' => round($p['amountRub'], 2),
                        'amountUSD' => round($p['amountUsd'], 2),
                        'currency' => $currencyId,
                        'currencyRate' => $currencyRate,
                        'date' => $p['date'] ? date('Y-m-d\TH:i:s', strtotime($p['date'])) : now()->toIso8601String(),
                        'dateMonth' => $p['date'] ? date('Y-m', strtotime($p['date'])) : now()->format('Y-m'),
                        'dateYear' => $p['date'] ? date('Y', strtotime($p['date'])) : now()->format('Y'),
                        'comment' => 'Импорт #' . $logId,
                        'dsCommissionPercentage' => $p['ds_percent'],
                        'commissionCalcProperty' => $p['property'],
                    ]);
                    $ids[] = (int) $txId;
                }
                return [$logId, $ids];
            });
        } catch (\Throwable $e) {
            \Log::error('Import atomic insert failed: ' . $e->getMessage());
            $msg = $e->getMessage();
            if (preg_match('/([^:\n]+(?:violation|violates|exists|not-null|duplicate)[^\n]*)/i', $msg, $m)) {
                $msg = trim($m[1]);
            }
            if ($tracker) {
                \Illuminate\Support\Facades\Cache::put(
                    "import:tracker:{$tracker}",
                    ['total' => $totalRows, 'processed' => $totalRows, 'success' => 0, 'errors' => 1, 'status' => 'done'],
                    600,
                );
            }
            return response()->json([
                'message' => 'Импорт отменён из-за ошибки БД. Ничего не загружено.',
                'success' => 0,
                'errors' => 1,
                'errorDetails' => [mb_substr($msg, 0, 300)],
            ], 422);
        }

        $successCount = count($createdIds);

        // === PHASE 3: auto-calc commissions ===
        // Расчёт идёт ВНЕ insert-транзакции — частичные ошибки расчёта
        // не должны откатывать вставленные транзакции (это отдельная
        // операция, её можно потом повторить через /calculate).
        $calcStats = null;
        try {
            $calculator = app(\App\Services\CommissionCalculator::class);
            $calcStats = $calculator->calculateForImport($importLogId);
        } catch (\Throwable $e) {
            \Log::warning("Auto-calc commissions failed for import #{$importLogId}: " . $e->getMessage());
            // Не валим импорт — транзакции есть в БД, расчёт можно повторить
            // через ручную кнопку «Рассчитать комиссии».
        }

        // Финальный апдейт лога: всегда success (всё вставилось),
        // расчёт-метаданные просто пишем в errors как информационный лог.
        $infoMessages = [];
        if ($calcStats) {
            $infoMessages[] = "Расчёт комиссий: {$calcStats['success']} из {$calcStats['total']}";
            if (! empty($calcStats['errors'])) {
                foreach (array_slice($calcStats['errors'], 0, 20) as $err) {
                    $infoMessages[] = is_array($err) ? json_encode($err, JSON_UNESCAPED_UNICODE) : (string) $err;
                }
            }
        }

        DB::table('transaction_import_log')->where('id', $importLogId)->update([
            'status' => 'success',
            'success_count' => $successCount,
            'error_count' => 0,
            'errors' => $infoMessages ? json_encode($infoMessages, JSON_UNESCAPED_UNICODE) : null,
            'created_ids' => json_encode($createdIds),
            'updated_at' => now(),
        ]);

        if ($tracker) {
            \Illuminate\Support\Facades\Cache::put(
                "import:tracker:{$tracker}",
                [
                    'total' => $totalRows,
                    'processed' => $totalRows,
                    'success' => $successCount,
                    'errors' => 0,
                    'status' => 'done',
                    'importId' => $importLogId,
                ],
                600,
            );
        }

        $msg = "Импорт завершён: {$successCount} транзакций загружено";
        if ($calcStats) {
            $msg .= ", комиссии рассчитаны: {$calcStats['success']} из {$calcStats['total']}";
        }

        return response()->json([
            'message' => $msg,
            'importId' => $importLogId,
            'success' => $successCount,
            'errors' => 0,
            'calc' => $calcStats,
        ]);
    }

    /**
     * Парсер строк по SheetProfile. Резолвит counterparty из профиля,
     * парсит колонки по canonical-ключам, сводит к формату processRows.
     */
    private function processProfiledRows(array $headers, array $rows, array $profile, Request $request): JsonResponse
    {
        $counterpartyId = \App\Services\SheetProfiles::resolveCounterpartyId($profile['counterpartyName'] ?? '')
            ?? (int) $request->counterparty;
        if (! $counterpartyId) {
            return response()->json([
                'message' => 'Counterparty «' . ($profile['counterpartyName'] ?? '—') . '» не найден в БД. Создайте его или выберите вручную.',
            ], 422);
        }

        $currencyId = isset($profile['currency'])
            ? (\App\Services\SheetProfiles::resolveCurrencyId($profile['currency'], 67))
            : ($request->currency ? (int) $request->currency : 67);

        // Приводим строки к generic-формату: contract_number, amount, date + extras.
        $assoc = [];
        foreach ($rows as $row) {
            $a = \App\Services\SheetProfiles::alignRow($row, $headers, $profile);

            // Если в профиле per-row валюта (InvestorsTrust, Woodville) — решаем на месте
            $rowCurrency = $currencyId;
            if (! empty($a['currency'])) {
                $resolved = \App\Services\SheetProfiles::resolveCurrencyId((string) $a['currency'], $currencyId);
                if ($resolved) $rowCurrency = $resolved;
            }

            $assoc[] = [
                'contract_number' => (string) ($a['contract_number'] ?? ''),
                'amount' => $a['amount'] ?? ($a['commission'] ?? 0),
                'date' => $a['date'] ?? null,
                'ds_percent' => $a['commission_pct'] ?? null,
                '_rowCurrency' => $rowCurrency,
                '_profileSheet' => $profile['productHint'] ?? null,
            ];
        }

        return $this->processRows($assoc, $counterpartyId, $currencyId, $request);
    }

    /**
     * История импортов.
     */
    public function history(Request $request): JsonResponse
    {
        $this->ensureImportLogTable();

        $query = DB::table('transaction_import_log')
            ->orderByDesc('created_at');

        if ($request->filled('counterparty')) {
            $query->where('counterparty', $request->counterparty);
        }

        $total = $query->count();
        $data = $query
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'counterpartyName' => $r->counterparty ? DB::table('counterparty')->where('id', $r->counterparty)->value('counterpartyName') : '—',
                'status' => $r->status,
                'totalRows' => $r->total_rows,
                'successCount' => $r->success_count,
                'errorCount' => $r->error_count,
                'createdAt' => $r->created_at,
                'errors' => $r->errors ? json_decode($r->errors, true) : [],
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /**
     * Откатить импорт — удалить все транзакции созданные этим импортом
     * ВМЕСТЕ с рассчитанными по ним комиссиями. Всё в одной транзакции,
     * чтобы rollback не оставил orphan-комиссии если что-то упадёт посередине.
     */
    public function rollback(int $importId): JsonResponse
    {
        // Предпочитаем точечный список created_ids (новые импорты). Для
        // старых импортов, где created_ids ещё не заполнялся, fallback
        // на comment='Импорт #N'.
        $log = DB::table('transaction_import_log')->where('id', $importId)->first();
        if (! $log) {
            return response()->json(['message' => 'Импорт не найден'], 404);
        }

        $txIdsFromLog = $log->created_ids
            ? array_filter((array) json_decode($log->created_ids, true))
            : [];

        // Заморозка: если хоть одна транзакция импорта попадает в закрытый
        // месяц — откатывать нельзя. Правки закрытых периодов идут только
        // через «Прочие начисления» (spec ✅Комиссии Part 2 §1).
        //
        // period_closures.year/month — smallint, transaction.dateYear —
        // varchar (legacy). Нужен явный каст в PG, иначе 500.
        $frozenQuery = DB::table('transaction as t')
            ->join('period_closures as p', function ($j) {
                $j->on(DB::raw('p.year::text'), '=', 't.dateYear')
                  ->on(DB::raw('LPAD(p.month::text, 2, \'0\')'), '=', DB::raw("RIGHT(t.\"dateMonth\", 2)"))
                  ->whereNull('p.reopened_at');
            });
        $frozenTxs = ($txIdsFromLog
            ? $frozenQuery->whereIn('t.id', $txIdsFromLog)
            : $frozenQuery->where('t.comment', 'Импорт #' . $importId)
        )->count();

        if ($frozenTxs > 0) {
            return response()->json([
                'message' => "Откат невозможен: {$frozenTxs} транзакций импорта находится в закрытых периодах. Для корректировки используйте «Прочие начисления».",
            ], 422);
        }

        $result = DB::transaction(function () use ($importId, $txIdsFromLog) {
            $txIds = $txIdsFromLog
                ? collect($txIdsFromLog)
                : DB::table('transaction')
                    ->where('comment', 'Импорт #' . $importId)
                    ->pluck('id');

            $deletedCommissions = 0;
            if ($txIds->isNotEmpty()) {
                $deletedCommissions = DB::table('commission')
                    ->whereIn('transaction', $txIds)
                    ->delete();
            }

            $deletedTx = DB::table('transaction')
                ->whereIn('id', $txIds)
                ->delete();

            DB::table('transaction_import_log')
                ->where('id', $importId)
                ->update([
                    'status' => 'rolled_back',
                    'updated_at' => now(),
                ]);

            return [
                'deleted_transactions' => $deletedTx,
                'deleted_commissions' => $deletedCommissions,
            ];
        });

        return response()->json([
            'message' => "Откат выполнен: удалено {$result['deleted_transactions']} транзакций и {$result['deleted_commissions']} комиссий",
            'deleted' => $result['deleted_transactions'],
            'deleted_commissions' => $result['deleted_commissions'],
        ]);
    }

    /**
     * Запустить расчёт комиссий для импорта.
     */
    public function calculateCommissions(Request $request, int $importId): JsonResponse
    {
        $calculator = app(\App\Services\CommissionCalculator::class);
        $results = $calculator->calculateForImport($importId);

        return response()->json([
            'message' => "Расчёт завершён: {$results['success']} из {$results['total']}",
            ...$results,
        ]);
    }

    /**
     * Запустить расчёт для одной транзакции.
     */
    public function calculateSingle(Request $request, int $transactionId): JsonResponse
    {
        $calculator = app(\App\Services\CommissionCalculator::class);
        $result = $calculator->calculateForTransaction($transactionId);

        return response()->json($result);
    }

    /**
     * PUT /admin/transactions/{id}
     *
     * Редактирование транзакции (amount, comment, date, dsCommissionPercentage).
     * Заморозка периода — 422. После успешного UPDATE автоматически
     * пересчитываются комиссии для этой транзакции (DELETE + INSERT в commission).
     */
    public function update(
        Request $request,
        int $id,
        \App\Services\PeriodFreezeService $freeze
    ): JsonResponse {
        $tx = DB::table('transaction')->where('id', $id)->whereNull('deletedAt')->first();
        if (! $tx) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }

        // Заморозка периода — нельзя править.
        $period = $freeze->resolvePeriod(date: $tx->date);
        if ($period && $freeze->isFrozen($period[0], $period[1])) {
            return response()->json([
                'message' => sprintf('Период %02d.%d закрыт — транзакцию нельзя редактировать.',
                    $period[1], $period[0]),
            ], 422);
        }

        $data = $request->validate([
            'amount' => 'nullable|numeric',
            'comment' => 'nullable|string|max:1000',
            'date' => 'nullable|date',
            'dsCommissionPercentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $update = ['dateChanged' => now()];
        if (array_key_exists('amount', $data)) $update['amount'] = $data['amount'];
        if (array_key_exists('comment', $data)) $update['comment'] = $data['comment'];
        if (array_key_exists('date', $data)) {
            $update['date'] = $data['date'];
            $d = \Carbon\Carbon::parse($data['date']);
            $update['dateMonth'] = $d->format('Y-m');
            $update['dateYear'] = (string) $d->year;
        }
        if (array_key_exists('dsCommissionPercentage', $data)) {
            $update['dsCommissionPercentage'] = $data['dsCommissionPercentage'];
        }

        DB::table('transaction')->where('id', $id)->update($update);

        // Пересчёт комиссий после правки: удаляем старые и вызываем calculator.
        try {
            DB::table('commission')->where('transaction', $id)->update([
                'deletedAt' => now(),
            ]);
            app(\App\Services\CommissionCalculator::class)->calculateForTransaction($id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('transaction recalc after edit failed', [
                'id' => $id, 'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Транзакция обновлена', 'id' => $id]);
    }

    private function parseCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if (! $handle) return [];

        // Detect delimiter
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = str_contains($firstLine, ';') ? ';' : ',';

        $headers = fgetcsv($handle, 0, $delimiter);
        if (! $headers) { fclose($handle); return []; }

        // Normalize headers
        $headers = array_map(fn ($h) => strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h))), $headers);

        // Map common column names
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

    private function ensureImportLogTable(): void
    {
        if (! Schema::hasTable('transaction_import_log')) {
            DB::statement('CREATE TABLE transaction_import_log (
                id BIGSERIAL PRIMARY KEY,
                counterparty INTEGER,
                currency INTEGER,
                status VARCHAR DEFAULT \'pending\',
                total_rows INTEGER DEFAULT 0,
                success_count INTEGER DEFAULT 0,
                error_count INTEGER DEFAULT 0,
                errors TEXT,
                created_by INTEGER,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }
    }
}
