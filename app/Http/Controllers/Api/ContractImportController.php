<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiSettingsService;
use App\Services\GoogleSheetsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Импорт контрактов из Google Sheets (аналог TransactionImportController).
 *
 * Источник: таблица с ID из api_settings['google.sheets.contracts_id'].
 * Каждый лист — отдельная пачка контрактов (например, разбивка по месяцам).
 * Колонки (формат гибкий — принимаются и русские, и английские шапки):
 *   number, client_name, consultant_name, product_name, program_name,
 *   amount, currency, open_date (DD.MM.YYYY), term
 *
 * Пишет в таблицу `contract`. Матчит client/consultant/product/program по
 * имени (ILIKE). Строки с неполным мэтчингом пропускаются с ошибкой.
 */
class ContractImportController extends Controller
{
    /** Получить список листов в таблице контрактов. */
    public function sheetNames(): JsonResponse
    {
        $settings = app(ApiSettingsService::class);
        $apiKey = $settings->get('google.sheets.api_key');
        $spreadsheetId = $settings->get('google.sheets.contracts_id');

        if (! $spreadsheetId || ! $apiKey) {
            $missing = array_filter([
                ! $apiKey        ? '«Google Sheets API Key»' : null,
                ! $spreadsheetId ? '«ID таблицы Импорт контрактов»' : null,
            ]);
            return response()->json([
                'sheets' => [],
                'message' => 'Не заполнено: ' . implode(' и ', $missing) . '. См. /admin/api-keys',
            ]);
        }

        $sheets = app(GoogleSheetsReader::class)->getSheetNames($spreadsheetId, $apiKey);
        if (empty($sheets)) {
            return response()->json([
                'sheets' => [],
                'message' => 'Не удалось получить список листов. Проверь валидность API-ключа и что таблица расшарена «Anyone with link».',
            ]);
        }

        return response()->json(['sheets' => $sheets]);
    }

    /** Справочные данные для формы редактирования строки буфера. */
    public function formData(): JsonResponse
    {
        $currencies = DB::table('currency')
            ->where('selectable', true)
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'symbol' => $c->symbol, 'name' => $c->nameRu ?? $c->currencyName]);

        $products = DB::table('product')
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]);

        $statuses = DB::table('contractStatus')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);

        return response()->json([
            'currencies' => $currencies,
            'products'   => $products,
            'statuses'   => $statuses,
        ]);
    }

    /** Поиск клиентов по имени (для autocomplete в форме редактирования). */
    public function clientSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['data' => []]);
        }
        $rows = DB::table('client')
            ->where('personName', 'ilike', "%{$q}%")
            ->whereNull('dateDeleted')
            ->orderBy('personName')
            ->limit(30)
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->personName]);

        return response()->json(['data' => $rows]);
    }

    /** Программы по product_id (для зависимого select). */
    public function programsByProduct(int $productId): JsonResponse
    {
        $rows = DB::table('program')
            ->where('product', $productId)
            ->whereNull('dateDeleted')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]);

        return response()->json(['data' => $rows]);
    }

    /** Импорт контрактов из выбранного листа. */
    /**
     * Preview-режим (per spec ✅Загрузка контрактов §1.2-§3):
     * читаем строки из Sheets, прогоняем через валидаторы, складываем
     * в буферную таблицу contract_import_preview и возвращаем session_id.
     * Записи в `contract` НЕ создаются — только после finalize().
     */
    public function previewFromSheets(Request $request, \App\Services\ContractImportPreviewService $preview): JsonResponse
    {
        $request->validate([
            'sheet' => 'required|string',
            'currency' => 'nullable|integer',
            'statusId' => 'nullable|integer',
        ]);

        $rows = $this->readRowsFromSheet($request);
        if (isset($rows['error'])) {
            return response()->json(['message' => $rows['error']], 422);
        }

        $stats = $preview->bufferRows($rows, $request->user()?->id);
        return response()->json($stats);
    }

    /** Список строк буфера за конкретную сессию. */
    public function previewList(string $sessionId): JsonResponse
    {
        $rows = DB::table('contract_import_preview')
            ->where('session_id', $sessionId)
            ->orderBy('id')
            ->get();

        $decoded = $rows->map(fn ($r) => [
            'id' => $r->id,
            'sessionId' => $r->session_id,
            'rowData' => json_decode($r->row_data, true) ?: [],
            'errors' => json_decode($r->errors ?: '[]', true),
            'status' => $r->status,
        ]);

        // Резолвим названия (клиент/продукт/программа/статус/валюта), чтобы
        // в реестре показывать имена, а не ID — одним батчем на всю выдачу.
        $pluckIds = fn ($key) => $decoded->pluck("rowData.$key")->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique();
        $clients = DB::table('client')->whereIn('id', $pluckIds('client'))->pluck('personName', 'id');
        $products = DB::table('product')->whereIn('id', $pluckIds('product'))->pluck('name', 'id');
        $programs = DB::table('program')->whereIn('id', $pluckIds('program'))->pluck('name', 'id');
        $statuses = DB::table('contractStatus')->whereIn('id', $pluckIds('status'))->pluck('name', 'id');
        $currencies = DB::table('currency')->whereIn('id', $pluckIds('currency'))->pluck('symbol', 'id');

        $data = $decoded->map(function ($row) use ($clients, $products, $programs, $statuses, $currencies) {
            $rd = $row['rowData'];
            $rd['clientName'] = $rd['clientName'] ?? ($clients[(int) ($rd['client'] ?? 0)] ?? null);
            $rd['productName'] = $rd['productName'] ?? ($products[(int) ($rd['product'] ?? 0)] ?? null);
            $rd['programName'] = $rd['programName'] ?? ($programs[(int) ($rd['program'] ?? 0)] ?? null);
            $rd['statusName'] = $statuses[(int) ($rd['status'] ?? 0)] ?? null;
            $rd['currencySymbol'] = $currencies[(int) ($rd['currency'] ?? 0)] ?? null;
            $row['rowData'] = $rd;

            return $row;
        });

        return response()->json([
            'data' => $data,
            'total' => $rows->count(),
            'validCount' => $rows->where('status', 'valid')->count(),
            'invalidCount' => $rows->where('status', 'invalid')->count(),
        ]);
    }

    /** Inline-edit одной строки + перезапуск валидации. */
    public function previewUpdate(Request $request, int $id, \App\Services\ContractImportPreviewService $preview): JsonResponse
    {
        $patch = $request->validate([
            'number' => 'nullable|string',
            'client' => 'nullable|integer',
            'product' => 'nullable|integer',
            'program' => 'nullable|integer',
            'currency' => 'nullable|integer',
            'status' => 'nullable|integer',
            'ammount' => 'nullable|numeric',
            'createDate' => 'nullable|date',
            'openDate' => 'nullable|date',
            'closeDate' => 'nullable|date',
            'comment' => 'nullable|string|max:2000',
            'counterpartyContractId' => 'nullable|string|max:255',
        ]);

        try {
            $r = $preview->updateRow($id, $patch);
            return response()->json($r);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Удалить строку из буфера. */
    public function previewDelete(int $id): JsonResponse
    {
        DB::table('contract_import_preview')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** Очистить всю сессию (per spec §1.3 «Удалить все контракты»). */
    public function previewClear(string $sessionId): JsonResponse
    {
        $deleted = DB::table('contract_import_preview')
            ->where('session_id', $sessionId)
            ->delete();
        return response()->json(['deleted' => $deleted]);
    }

    /** Зафиксировать valid-строки в `contract` (per spec §3.3). */
    public function previewFinalize(string $sessionId, \App\Services\ContractImportPreviewService $preview, Request $request): JsonResponse
    {
        $r = $preview->finalize($sessionId, $request->user()?->id);
        return response()->json($r);
    }

    /** Вспомогательный — читает строки листа. Возвращает list<assoc> или {error}. */
    private function readRowsFromSheet(Request $request): array
    {
        $settings = app(ApiSettingsService::class);
        $apiKey = $settings->get('google.sheets.api_key');
        $spreadsheetId = $settings->get('google.sheets.contracts_id');
        if (! $spreadsheetId || ! $apiKey) {
            return ['error' => 'Google Sheets не настроен — заполни ключи в /admin/api-keys'];
        }

        try {
            $range = urlencode($request->sheet);
            $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?key={$apiKey}&majorDimension=ROWS";
            $response = \Illuminate\Support\Facades\Http::timeout(30)->get($url);
            if (! $response->ok()) return ['error' => "Google API HTTP {$response->status()}"];
            $values = $response->json('values') ?? [];
        } catch (\Throwable $e) {
            return ['error' => 'Ошибка чтения листа: ' . $e->getMessage()];
        }

        if (count($values) < 2) return ['error' => 'Лист пустой'];

        $headers = $values[0];
        $rows = [];
        for ($i = 1; $i < count($values); $i++) {
            $row = [];
            foreach ($headers as $j => $h) {
                $row[trim((string) $h)] = $values[$i][$j] ?? null;
            }
            if (array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                $rows[] = $row;
            }
        }
        return $rows;
    }



    /** GET /admin/contract-import/history */
    public function history(Request $request): JsonResponse
    {
        if (! \Schema::hasTable('contract_import_log')) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $rows = DB::table('contract_import_log')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'source' => $r->source,
                'status' => $r->status,
                'totalRows' => $r->total_rows,
                'successCount' => $r->success_count,
                'errorCount' => $r->error_count,
                'createdAt' => $r->created_at,
                'errors' => $r->errors ? json_decode($r->errors, true) : [],
            ]);

        return response()->json(['data' => $rows, 'total' => $rows->count()]);
    }

    /**
     * POST /admin/contract-import/{id}/rollback — удалить контракты прогона.
     * Блокируется если у любого из них есть не-удалённые транзакции
     * (нельзя осиротить транзакцию).
     */
    public function rollback(int $importId): JsonResponse
    {
        $log = DB::table('contract_import_log')->where('id', $importId)->first();
        if (! $log) {
            return response()->json(['message' => 'Импорт не найден'], 404);
        }

        // created_ids может быть в двух форматах:
        //   legacy:  [1, 2, 3]               — только созданные
        //   new:     {"created": [...], "updated": [...], "skipped": N}
        // Откатываем только созданные контракты (обновлённые — не трогаем,
        // они были до импорта и должны остаться).
        $raw = $log->created_ids ? json_decode($log->created_ids, true) : [];
        $contractIds = [];
        if (is_array($raw)) {
            if (isset($raw['created']) && is_array($raw['created'])) {
                $contractIds = array_filter($raw['created']);
            } elseif (array_is_list($raw)) {
                $contractIds = array_filter($raw);
            }
        }
        if (empty($contractIds)) {
            return response()->json(['message' => 'Нет ID для отката (в этом прогоне созданных контрактов не было — только обновления)'], 422);
        }

        // Guard: если по контракту есть активные транзакции — блокируем.
        $withTx = DB::table('transaction')
            ->whereIn('contract', $contractIds)
            ->whereNull('deletedAt')
            ->distinct()
            ->count('contract');
        if ($withTx > 0) {
            return response()->json([
                'message' => "Откат невозможен: у {$withTx} контрактов этого импорта уже есть транзакции. Сначала удалите транзакции или откатите их импорт.",
            ], 422);
        }

        $deleted = DB::transaction(function () use ($contractIds, $importId) {
            $d = DB::table('contract')->whereIn('id', $contractIds)->update([
                'deletedAt' => now(),
            ]);

            DB::table('contract_import_log')->where('id', $importId)->update([
                'status' => 'rolled_back',
                'updated_at' => now(),
            ]);

            return $d;
        });

        return response()->json(['message' => "Откат выполнен: удалено {$deleted} контрактов"]);
    }








}
