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

    /** Импорт контрактов из выбранного листа. */
    public function importFromSheets(Request $request): JsonResponse
    {
        $request->validate([
            'sheet' => 'required|string',
            'currency' => 'nullable|integer',
            'statusId' => 'nullable|integer',
            'tracker' => 'nullable|string|max:80',
        ]);

        $settings = app(ApiSettingsService::class);
        $apiKey = $settings->get('google.sheets.api_key');
        $spreadsheetId = $settings->get('google.sheets.contracts_id');
        if (! $spreadsheetId || ! $apiKey) {
            return response()->json(['message' => 'Google Sheets не настроен — заполни ключи в /admin/api-keys'], 422);
        }

        // Читаем API напрямую — GoogleSheetsReader::normalizeRow портит колонки
        // типа createDate/openDate/closeDate в общий ключ `date`, затирая
        // друг друга. Для контрактов нужны оригинальные заголовки.
        try {
            $range = urlencode($request->sheet);
            $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?key={$apiKey}&majorDimension=ROWS";
            $response = \Illuminate\Support\Facades\Http::timeout(30)->get($url);
            if (! $response->ok()) {
                return response()->json(['message' => "Google API вернул HTTP {$response->status()}"], 422);
            }
            $values = $response->json('values') ?? [];
        } catch (\Throwable $e) {
            Log::error('contracts sheet read failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка чтения листа: ' . $e->getMessage()], 422);
        }

        if (count($values) < 2) {
            return response()->json(['message' => 'Лист пустой'], 422);
        }

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

        return response()->json($this->processRows($rows, $request, 'sheets:' . $request->sheet));
    }

    /**
     * Разобрать строки и записать контракты. Возвращает summary.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function processRows(array $rows, Request $request, string $source): array
    {
        $defaultCurrency = (int) ($request->currency ?? 0) ?: null;
        $defaultStatus = (int) ($request->statusId ?? 0) ?: $this->defaultStatusId();

        $success = 0;
        $errors = [];
        $createdIds = [];
        $tracker = $request->input('tracker');
        $total = count($rows);

        // Инициализируем прогресс-ключ сразу, чтобы фронт при поллинге
        // не получал null.
        if ($tracker) {
            \Illuminate\Support\Facades\Cache::put(
                "import:tracker:{$tracker}",
                ['total' => $total, 'processed' => 0, 'success' => 0, 'errors' => 0, 'status' => 'running'],
                600,
            );
        }

        DB::transaction(function () use ($rows, $defaultCurrency, $defaultStatus, $source, $tracker, $total, &$success, &$errors, &$createdIds) {
            foreach ($rows as $idx => $row) {
                try {
                    $norm = $this->normaliseRow($row);
                    $ref = $this->resolveReferences($norm, $defaultCurrency);

                    $amount = $this->parseAmount($norm['amount'] ?? null);
                    if ($amount === null) {
                        $errors[] = "Строка " . ($idx + 2) . ": не удалось распознать сумму («{$norm['amount']}»)";
                        continue;
                    }

                    $openDate = $this->parseDate($norm['open_date'] ?? null);
                    if (! $openDate) {
                        $errors[] = "Строка " . ($idx + 2) . ": не удалось распознать дату («{$norm['open_date']}»)";
                        continue;
                    }

                    if (! $ref['client']) {
                        $errors[] = "Строка " . ($idx + 2) . ": не найден клиент «{$norm['client_name']}»";
                        continue;
                    }
                    if (! $ref['consultant']) {
                        $errors[] = "Строка " . ($idx + 2) . ": не найден консультант «{$norm['consultant_name']}»";
                        continue;
                    }
                    if (! $ref['product']) {
                        $errors[] = "Строка " . ($idx + 2) . ": не найден продукт «{$norm['product_name']}»";
                        continue;
                    }

                    // Дедупликация по номеру
                    if (! empty($norm['number'])) {
                        $exists = DB::table('contract')
                            ->where('number', $norm['number'])
                            ->whereNull('deletedAt')
                            ->exists();
                        if ($exists) {
                            $errors[] = "Строка " . ($idx + 2) . ": контракт №{$norm['number']} уже существует";
                            continue;
                        }
                    }

                    $id = DB::table('contract')->insertGetId([
                        'number' => $norm['number'] ?? null,
                        'client' => $ref['client'],
                        'consultant' => $ref['consultant'],
                        'product' => $ref['product'],
                        'program' => $ref['program'],
                        'ammount' => $amount,  // legacy spelling
                        'currency' => $ref['currency'],
                        'openDate' => $openDate,
                        'status' => $defaultStatus,
                        'term' => $norm['term'] ?? null,
                    ]);
                    $createdIds[] = $id;
                    $success++;
                } catch (\Throwable $e) {
                    $errors[] = "Строка " . ($idx + 2) . ": " . $e->getMessage();
                }

                // Обновляем прогресс каждую строку (дешёвый cache put).
                if ($tracker) {
                    \Illuminate\Support\Facades\Cache::put(
                        "import:tracker:{$tracker}",
                        [
                            'total' => $total,
                            'processed' => $idx + 1,
                            'success' => $success,
                            'errors' => count($errors),
                            'status' => 'running',
                        ],
                        600,
                    );
                }
            }

            // Пишем в отдельную таблицу contract_import_log (миграция 2026_04_21_000010).
            if (\Schema::hasTable('contract_import_log')) {
                $logId = DB::table('contract_import_log')->insertGetId([
                    'source' => $source,
                    'status' => count($errors) === 0 && $success > 0 ? 'success' : ($success > 0 ? 'partial' : 'error'),
                    'total_rows' => count($rows),
                    'success_count' => $success,
                    'error_count' => count($errors),
                    'errors' => json_encode(array_slice($errors, 0, 50), JSON_UNESCAPED_UNICODE),
                    'created_ids' => json_encode($createdIds),
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->lastLogId = $logId;
            }
        });

        // Финализируем прогресс (done).
        if ($tracker) {
            \Illuminate\Support\Facades\Cache::put(
                "import:tracker:{$tracker}",
                [
                    'total' => $total,
                    'processed' => $total,
                    'success' => $success,
                    'errors' => count($errors),
                    'status' => 'done',
                    'importId' => $this->lastLogId,
                ],
                600,
            );
        }

        return [
            'source' => $source,
            'importId' => $this->lastLogId,
            'total' => count($rows),
            'success' => $success,
            'errors' => count($errors),
            'errorsList' => array_slice($errors, 0, 50),
            'createdIds' => $createdIds,
        ];
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

        $contractIds = $log->created_ids
            ? array_filter((array) json_decode($log->created_ids, true))
            : [];
        if (empty($contractIds)) {
            return response()->json(['message' => 'Нет ID для отката (старый импорт без created_ids?)'], 422);
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

    private ?int $lastLogId = null;

    /** Привести ключи к унифицированным (accept ru+en headers). */
    private function normaliseRow(array $row): array
    {
        $aliases = [
            'number'          => ['number', 'номер', 'номер контракта', '№', 'no'],
            'client_name'     => ['client_name', 'client', 'клиент', 'фио клиента'],
            'consultant_name' => ['consultant_name', 'consultant', 'партнер', 'партнёр', 'консультант', 'фио консультанта'],
            'product_name'    => ['product_name', 'product', 'продукт'],
            'program_name'    => ['program_name', 'program', 'программа'],
            'amount'          => ['amount', 'сумма'],
            'currency'        => ['currency', 'валюта'],
            'open_date'       => ['open_date', 'opendate', 'date', 'дата', 'дата открытия'],
            'term'            => ['term', 'срок'],
            'status'          => ['status', 'статус'],
            'create_date'     => ['createdate', 'created_at', 'дата создания'],
            'close_date'      => ['closedate', 'close_date', 'дата закрытия'],
        ];

        // Ключи — в lowercase, trim
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[mb_strtolower(trim((string) $k))] = is_string($v) ? trim($v) : $v;
        }

        $out = [];
        foreach ($aliases as $canonical => $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $lower) && $lower[$key] !== '' && $lower[$key] !== null) {
                    $out[$canonical] = $lower[$key];
                    break;
                }
            }
            $out[$canonical] ??= null;
        }
        return $out;
    }

    /** Найти id client/consultant/product/program/currency по имени. */
    private function resolveReferences(array $norm, ?int $defaultCurrency): array
    {
        $client = $this->findByName('client', 'personName', $norm['client_name'] ?? null);
        $consultant = $this->findByName('consultant', 'personName', $norm['consultant_name'] ?? null);
        $product = $this->findByName('product', 'name', $norm['product_name'] ?? null);

        $program = null;
        if ($product && ! empty($norm['program_name'])) {
            $program = DB::table('program')
                ->where('product', $product)
                ->where('name', 'ilike', '%' . $norm['program_name'] . '%')
                ->value('id');
        }

        $currency = $defaultCurrency;
        if (! empty($norm['currency'])) {
            $code = mb_strtoupper(trim($norm['currency']));
            $currency = DB::table('currency')
                ->where(function ($q) use ($code) {
                    $q->where('nameRu', 'ilike', $code)->orWhere('nameEn', 'ilike', $code);
                })
                ->value('id') ?? $defaultCurrency;
        }

        return compact('client', 'consultant', 'product', 'program', 'currency');
    }

    private function findByName(string $table, string $col, ?string $name): ?int
    {
        if (! $name) return null;
        $q = DB::table($table)->where($col, 'ilike', trim($name));
        if (\Schema::hasColumn($table, 'deletedAt')) $q->whereNull('deletedAt');
        if (\Schema::hasColumn($table, 'dateDeleted')) $q->whereNull('dateDeleted');
        return $q->value('id');
    }

    private function parseAmount(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        $s = is_string($raw) ? $raw : (string) $raw;
        $s = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $s);
        $s = preg_replace('/[^0-9.\-]/', '', $s);
        return is_numeric($s) ? (float) $s : null;
    }

    private function parseDate(mixed $raw): ?string
    {
        if (! $raw) return null;
        $raw = trim((string) $raw);
        // Форматы в таблицах: "17.4.2026" (без leading zero), "01.03.2026",
        // "01/03/2026", "2026-03-01". j.n.Y = день/месяц без нулей.
        foreach (['j.n.Y', 'd.m.Y', 'j/n/Y', 'd/m/Y', 'Y-m-d', 'd-m-Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $raw);
                if ($d && $d->format($fmt) === $raw) return $d->format('Y-m-d');
            } catch (\Throwable) {}
        }
        try { return Carbon::parse($raw)->format('Y-m-d'); } catch (\Throwable) { return null; }
    }

    private function defaultStatusId(): ?int
    {
        return DB::table('contractStatus')->where('name', 'ilike', '%Активирован%')->value('id')
            ?? DB::table('contractStatus')->orderBy('id')->value('id');
    }
}
