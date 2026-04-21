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
        ]);

        $settings = app(ApiSettingsService::class);
        $apiKey = $settings->get('google.sheets.api_key');
        $spreadsheetId = $settings->get('google.sheets.contracts_id');
        if (! $spreadsheetId || ! $apiKey) {
            return response()->json(['message' => 'Google Sheets не настроен — заполни ключи в /admin/api-keys'], 422);
        }

        try {
            $rows = app(GoogleSheetsReader::class)
                ->readSheet($spreadsheetId, $request->sheet, $apiKey);
        } catch (\Throwable $e) {
            Log::error('contracts sheet read failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Ошибка чтения листа: ' . $e->getMessage()], 422);
        }

        if (empty($rows)) {
            return response()->json(['message' => 'Лист пустой'], 422);
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

        DB::transaction(function () use ($rows, $defaultCurrency, $defaultStatus, $source, &$success, &$errors, &$createdIds) {
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
            }

            // Пишем в лог импорта (таблица transaction_import_log используется для
            // любых импортов — используем её и для контрактов через разные counterparty)
            if (\Schema::hasTable('transaction_import_log')) {
                DB::table('transaction_import_log')->insert([
                    'counterparty' => 0,
                    'currency' => $defaultCurrency ?? 0,
                    'status' => count($errors) === 0 ? 'success' : ($success > 0 ? 'partial' : 'error'),
                    'total_rows' => count($rows),
                    'success_count' => $success,
                    'error_count' => count($errors),
                    'errors' => json_encode(array_slice($errors, 0, 50), JSON_UNESCAPED_UNICODE),
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return [
            'source' => $source,
            'total' => count($rows),
            'success' => $success,
            'errors' => count($errors),
            'errorsList' => array_slice($errors, 0, 50),
            'createdIds' => $createdIds,
        ];
    }

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
            'open_date'       => ['open_date', 'date', 'дата', 'дата открытия', 'openDate'],
            'term'            => ['term', 'срок'],
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
        foreach (['d.m.Y', 'd/m/Y', 'Y-m-d', 'd-m-Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, trim((string) $raw));
                if ($d) return $d->format('Y-m-d');
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
