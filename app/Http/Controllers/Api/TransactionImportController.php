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

        return response()->json(['sheets' => $sheets]);
    }

    /**
     * Импорт из Google Sheets — выбрать лист (поставщика) → загрузить данные.
     */
    public function importFromSheets(Request $request): JsonResponse
    {
        $request->validate([
            'sheet' => 'required|string',
            'counterparty' => 'required|integer',
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

        $reader = app(\App\Services\GoogleSheetsReader::class);

        try {
            $rows = $reader->readSheet($spreadsheetId, $request->sheet, $apiKey);
        } catch (\Exception $e) {
            \Log::error('Sheet read failed: ' . $e->getMessage());
            return response()->json(['message' => 'Ошибка чтения листа. Проверьте ID и название листа.'], 422);
        }

        if (empty($rows)) {
            return response()->json(['message' => 'Лист пустой или не содержит данных'], 422);
        }

        return $this->processRows($rows, (int) $request->counterparty, $request->currency ? (int) $request->currency : 67, $request);
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
     */
    private function processRows(array $rows, int $counterpartyId, int $currencyId, Request $request): JsonResponse
    {
        $this->ensureImportLogTable();

        // Создаём запись лога импорта
        $importLogId = DB::table('transaction_import_log')->insertGetId([
            'counterparty' => $counterpartyId,
            'currency' => $currencyId,
            'status' => 'processing',
            'total_rows' => count($rows),
            'success_count' => 0,
            'error_count' => 0,
            'created_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        $successCount = 0;
        $errorCount = 0;
        $skippedDupes = 0;
        $errors = [];
        $createdIds = [];

        foreach ($rows as $i => $row) {
            $contractNumber = trim($row['contract_number'] ?? $row['number'] ?? $row['номер_контракта'] ?? $row['contract'] ?? '');
            $amount = (float) str_replace([' ', ','], ['', '.'], $row['amount'] ?? $row['сумма'] ?? $row['sum'] ?? '0');
            $date = $row['date'] ?? $row['дата'] ?? $row['payment_date'] ?? null;

            if (empty($contractNumber)) {
                $errors[] = "Строка " . ($i + 2) . ": пустой номер контракта";
                $errorCount++;
                continue;
            }

            // Матчинг — ищем контракт по номеру
            $contract = DB::table('contract')
                ->where('number', $contractNumber)
                ->whereNull('deletedAt')
                ->first();

            if (! $contract) {
                // Попробуем частичное совпадение
                $contract = DB::table('contract')
                    ->where('number', 'ilike', '%' . $contractNumber . '%')
                    ->whereNull('deletedAt')
                    ->first();
            }

            if (! $contract) {
                $errors[] = "Строка " . ($i + 2) . ": контракт «{$contractNumber}» не найден";
                $errorCount++;
                continue;
            }

            // Дедупликация: если транзакция по этому контракту с такой же
            // датой и суммой уже существует — пропускаем (spec: основной
            // параметр сверки = номер контракта + дата/сумма).
            $txDate = $date ? date('Y-m-d', strtotime($date)) : null;
            if ($txDate) {
                $dup = DB::table('transaction')
                    ->where('contract', $contract->id)
                    ->whereRaw('DATE(date) = ?', [$txDate])
                    ->whereBetween('amount', [$amount - 0.01, $amount + 0.01])
                    ->whereNull('deletedAt')
                    ->exists();
                if ($dup) {
                    $skippedDupes++;
                    continue;
                }
            }

            // Получаем курс валюты
            $currencyRate = 1.0;
            if ($currencyId !== 67) {
                $rate = DB::table('currencyRate')
                    ->where('currency', $currencyId)
                    ->orderByDesc('date')
                    ->first();
                $currencyRate = (float) ($rate->rate ?? 1);
            }

            $amountRub = $amount * $currencyRate;
            $usdRate = 1.0;
            $rateUsd = DB::table('currencyRate')->where('currency', 5)->orderByDesc('date')->first();
            if ($rateUsd) $usdRate = (float) $rateUsd->rate;
            $amountUsd = $usdRate > 0 ? $amountRub / $usdRate : 0;

            // Создаём транзакцию
            try {
                $txId = DB::table('transaction')->insertGetId([
                    'contract' => $contract->id,
                    'amount' => $amount,
                    'amountRUB' => round($amountRub, 2),
                    'amountUSD' => round($amountUsd, 2),
                    'currency' => $currencyId,
                    'currencyRate' => $currencyRate,
                    'date' => $date ? date('Y-m-d\TH:i:s', strtotime($date)) : now()->toIso8601String(),
                    'dateMonth' => $date ? date('Y-m', strtotime($date)) : now()->format('Y-m'),
                    'dateYear' => $date ? date('Y', strtotime($date)) : now()->format('Y'),
                    'comment' => 'Импорт #' . $importLogId,
                    'dsCommissionPercentage' => $row['ds_percent'] ?? $row['процент_дс'] ?? null,
                    'commissionCalcProperty' => $row['property'] ?? $row['свойство'] ?? null,
                ]);
                $createdIds[] = (int) $txId;
                $successCount++;
            } catch (\Exception $e) {
                \Log::warning("Import row " . ($i + 2) . " failed: " . $e->getMessage());
                $errors[] = "Строка " . ($i + 2) . ": ошибка создания";
                $errorCount++;
            }
        }

        if ($skippedDupes > 0) {
            $errors[] = "Пропущено дублей (contract+date+amount уже существуют): {$skippedDupes}";
        }

        // Обновляем лог
        DB::table('transaction_import_log')->where('id', $importLogId)->update([
            'status' => $errorCount === 0 && $skippedDupes === 0 ? 'success' : ($successCount > 0 ? 'partial' : 'error'),
            'success_count' => $successCount,
            'error_count' => $errorCount + $skippedDupes,
            'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
            'created_ids' => json_encode($createdIds),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Импорт завершён: {$successCount} успешно, {$errorCount} ошибок, {$skippedDupes} дублей пропущено",
            'importId' => $importLogId,
            'success' => $successCount,
            'errors' => $errorCount,
            'skipped' => $skippedDupes,
            'errorDetails' => array_slice($errors, 0, 20),
        ]);
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
        $frozenQuery = DB::table('transaction as t')
            ->join('period_closures as p', function ($j) {
                $j->on('p.year', '=', 't.dateYear')
                  ->on('p.month', '=', 't.dateMonth')
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
