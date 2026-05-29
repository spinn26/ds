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
     * Унифицированный 422-ответ импорта: всегда включает success/errors/
     * errorDetails — фронт показывает детали под общим сообщением вместо
     * скудного «Ошибка импорта» без подсказки.
     */
    private function importError(string $message, int $status = 422, array $extraDetails = []): JsonResponse
    {
        $details = array_filter(array_merge([$message], $extraDetails), fn ($v) => $v !== null && $v !== '');
        return response()->json([
            'message' => $message,
            'success' => 0,
            'errors' => 1,
            'errorDetails' => array_values($details),
        ], $status);
    }


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
     * Импорт из Google Sheets — выбрать лист (поставщика) → положить в очередь.
     *
     * Контроллер только валидирует параметры, создаёт запись в
     * transaction_import_log (status=processing) и диспатчит
     * ImportTransactionsJob. Сама работа (чтение листа, валидация, INSERT,
     * расчёт комиссий) идёт асинхронно — синхронно она не успевала
     * уложиться в 30s axios timeout на ~1500 строк × каскад наставников.
     *
     * Возвращает 202 + tracker для polling-прогресса.
     */
    public function importFromSheets(Request $request): JsonResponse
    {
        $request->validate([
            'sheet' => 'required|string',
            'counterparty' => 'nullable|integer',
            'currency' => 'nullable|integer',
            'tracker' => 'nullable|string',
        ]);

        // Read from api_settings first (admin UI), fall back to legacy env.
        $settings = app(\App\Services\ApiSettingsService::class);
        $spreadsheetId = $settings->get('google.sheets.transactions_id',
            config('services.google_sheets.spreadsheet_id', env('GOOGLE_SHEETS_SPREADSHEET_ID')));
        $apiKey = $settings->get('google.sheets.api_key',
            config('services.google_sheets.api_key', env('GOOGLE_SHEETS_API_KEY')));

        if (! $spreadsheetId || ! $apiKey) {
            return $this->importError('Google Sheets не настроен. Заполните «Google Sheets API Key» и «ID таблицы Импорт транзакций» в /admin/api-keys');
        }

        // Резолвим counterparty/currency ДО постановки в очередь — иначе
        // ошибки конфигурации (нет профиля + не выбран counterparty)
        // придётся ловить через polling, что ухудшает UX.
        $profile = \App\Services\SheetProfiles::profile($request->sheet);
        if (! $profile && ! $request->counterparty) {
            return $this->importError('Лист не распознан в профилях. Выберите поставщика вручную.');
        }

        $counterpartyId = $profile
            ? (\App\Services\SheetProfiles::resolveCounterpartyId($profile['counterpartyName'] ?? '')
                ?? ($request->counterparty ? (int) $request->counterparty : null))
            : (int) $request->counterparty;

        if (! $counterpartyId) {
            return $this->importError(
                'Counterparty «' . ($profile['counterpartyName'] ?? '—')
                . '» не найден в БД. Создайте его или выберите вручную.'
            );
        }

        $currencyId = $profile && isset($profile['currency'])
            ? \App\Services\SheetProfiles::resolveCurrencyId($profile['currency'], 67)
            : ($request->currency ? (int) $request->currency : 67);

        // Для Sheets хеш = spreadsheetId:sheetName — uniqueness достаточный
        // для anti-double-click окна, без обхода листа на чтение.
        $fileHash = hash('sha256', $spreadsheetId . ':' . $request->sheet);

        return $this->queueImport(
            source: 'sheets',
            sourceRef: (string) $request->sheet,
            counterpartyId: $counterpartyId,
            currencyId: $currencyId,
            tracker: (string) ($request->tracker ?: ('tx-' . uniqid('', true))),
            request: $request,
            fileHash: $fileHash,
        );
    }

    /**
     * Загрузить CSV/XLSX файл и поставить импорт в очередь.
     *
     * Файл копируется в storage/app/imports/ под уникальным именем,
     * путь передаётся в job. Job удалит файл после выполнения.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx|max:10240',
            'counterparty' => 'required|integer',
            'currency' => 'nullable|integer',
            'tracker' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension() ?: 'csv';
        $fileHash = @hash_file('sha256', $file->getPathname()) ?: null;

        $storedPath = storage_path('app/imports/' . uniqid('tx-', true) . '.' . $ext);
        if (! is_dir(dirname($storedPath))) {
            @mkdir(dirname($storedPath), 0775, true);
        }
        if (! @copy($file->getPathname(), $storedPath)) {
            return $this->importError('Не удалось сохранить загруженный файл во временную папку', 500);
        }

        return $this->queueImport(
            source: 'csv',
            sourceRef: $storedPath,
            counterpartyId: (int) $request->counterparty,
            currencyId: $request->currency ? (int) $request->currency : 67,
            tracker: (string) ($request->tracker ?: ('tx-' . uniqid('', true))),
            request: $request,
            fileHash: $fileHash,
        );
    }

    /**
     * Общая часть: создать transaction_import_log в статусе processing,
     * инициализировать tracker в Cache и поставить ImportTransactionsJob
     * в очередь. Возвращает 202 + importId + tracker.
     */
    private function queueImport(
        string $source,
        string $sourceRef,
        ?int $counterpartyId,
        ?int $currencyId,
        string $tracker,
        Request $request,
        ?string $fileHash = null,
    ): JsonResponse {
        $this->ensureImportLogTable();

        // Anti-double-click: блокируем повторный диспатч того же источника,
        // если активный job уже летит. Окно 5 минут — обычный импорт
        // 1500 строк укладывается, бо́льшие импорты вместе с calc редки.
        // Защищает от случаев: оператор дважды нажал «Импортировать»;
        // открыто 2 вкладки и оба тычут одно; сеть лагнула и пользователь
        // решил повторить.
        if ($fileHash && Schema::hasColumn('transaction_import_log', 'file_hash')) {
            $duplicate = DB::table('transaction_import_log')
                ->where('file_hash', $fileHash)
                ->whereIn('status', ['processing', 'pending'])
                ->where('created_at', '>=', now()->subMinutes(5))
                ->orderByDesc('id')
                ->first();
            if ($duplicate) {
                $minutes = round((now()->timestamp - strtotime($duplicate->created_at)) / 60, 1);
                return $this->importError(
                    "Этот источник уже импортируется ({$minutes} мин назад, импорт #{$duplicate->id}). Дождитесь завершения или откатите.",
                    409,
                );
            }
        }

        $logData = [
            'counterparty' => $counterpartyId,
            'currency' => $currencyId,
            'status' => 'processing',
            'total_rows' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'created_by' => $request->user()->id,
            'created_at' => now(),
        ];
        if ($fileHash && Schema::hasColumn('transaction_import_log', 'file_hash')) {
            $logData['file_hash'] = $fileHash;
        }
        $importLogId = DB::table('transaction_import_log')->insertGetId($logData);

        \Illuminate\Support\Facades\Cache::put(
            "import:tracker:{$tracker}",
            [
                'total' => 0,
                'processed' => 0,
                'success' => 0,
                'errors' => 0,
                'status' => 'starting',
                'importId' => $importLogId,
            ],
            1800,
        );

        \App\Jobs\ImportTransactionsJob::dispatch(
            source: $source,
            sourceRef: $sourceRef,
            counterpartyId: $counterpartyId,
            currencyId: $currencyId,
            importLogId: $importLogId,
            userId: (int) $request->user()->id,
            tracker: $tracker,
        );

        return response()->json([
            'message' => 'Импорт поставлен в очередь',
            'importId' => $importLogId,
            'tracker' => $tracker,
            'status' => 'queued',
        ], 202);
    }

    /**
     * История импортов.
     *
     * Поле `frozen` (bool) — у импорта есть хотя бы одна транзакция в
     * закрытом периоде. Фронт по этому флагу делает «Откатить» и
     * «Рассчитать» disabled, чтобы оператор не пытался — всё равно
     * упрётся в 422 от rollback/calculate.
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
        $rows = $query
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        // Batch-проверка «есть ли в импорте транзакции в закрытом периоде»
        // одним SQL для всей страницы — иначе на каждой записи делать
        // отдельный запрос (×25 строк = 25 round-trip).
        $frozenMap = $this->frozenMapForLogs($rows);

        $data = $rows->map(fn ($r) => [
            'id' => $r->id,
            'counterpartyName' => $r->counterparty ? DB::table('counterparty')->where('id', $r->counterparty)->value('counterpartyName') : '—',
            'status' => $r->status,
            'totalRows' => $r->total_rows,
            'successCount' => $r->success_count,
            'errorCount' => $r->error_count,
            'createdAt' => $r->created_at,
            'errors' => $r->errors ? json_decode($r->errors, true) : [],
            'warnings' => isset($r->warnings) && $r->warnings ? json_decode($r->warnings, true) : [],
            'frozen' => $frozenMap[$r->id] ?? false,
            // Индикатор расчёта комиссий — для бейджа в строке истории.
            'calcStatus' => $r->calc_status ?? null,
            'calcTotal' => $r->calc_total ?? null,
            'calcSuccess' => $r->calc_success ?? null,
            'calcErrors' => $r->calc_errors ?? null,
            'calcDoneAt' => $r->calc_done_at ?? null,
        ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /**
     * GET /admin/transaction-import/check-duplicate?counterparty=N
     *
     * Анти-дубли «для тупых»: если в текущем календарном месяце уже был
     * УСПЕШНЫЙ (или partial) импорт с этим counterparty — фронт
     * показывает предупреждение перед запуском второго импорта.
     * Считаем только не откаченные: rolled_back/processing/error в выдачу
     * не идут, т.к. они НЕ создадут дублей.
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $this->ensureImportLogTable();

        // Принимаем либо counterparty (id) — для file-импорта, либо sheet
        // (имя листа Google Sheets) — для sheets-импорта когда юзер
        // полагается на авторезолв из профиля. Во втором случае резолвим
        // counterparty на бэке тем же кодом, что и importFromSheets.
        $counterparty = (int) $request->query('counterparty');
        if (! $counterparty && $request->filled('sheet')) {
            $profile = \App\Services\SheetProfiles::profile((string) $request->query('sheet'));
            if ($profile) {
                $counterparty = \App\Services\SheetProfiles::resolveCounterpartyId(
                    $profile['counterpartyName'] ?? ''
                ) ?? 0;
            }
        }
        if (! $counterparty) {
            return response()->json(['has_recent' => false, 'recent' => []]);
        }

        $monthStart = now()->startOfMonth();
        $recent = DB::table('transaction_import_log')
            ->where('counterparty', $counterparty)
            ->whereIn('status', ['success', 'partial'])
            ->where('created_at', '>=', $monthStart)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'success_count', 'created_at']);

        return response()->json([
            'has_recent' => $recent->isNotEmpty(),
            'recent' => $recent->map(fn ($r) => [
                'id' => $r->id,
                'successCount' => $r->success_count,
                'createdAt' => $r->created_at,
            ]),
        ]);
    }

    /**
     * Внутренний helper: построить map [logId => bool frozen].
     * Используется в history() для блокировки UI-действий над импортами
     * закрытых периодов.
     */
    private function frozenMapForLogs(\Illuminate\Support\Collection $logs): array
    {
        if ($logs->isEmpty()) return [];

        // Сначала пытаемся найти frozen по точечному списку created_ids
        // (новые импорты), остатку — по comment='Импорт #N' (старые).
        $allTxIds = [];
        $idToTxIds = [];
        $fallbackIds = [];
        foreach ($logs as $log) {
            $txIds = isset($log->created_ids) && $log->created_ids
                ? array_filter((array) json_decode($log->created_ids, true))
                : [];
            if ($txIds) {
                $idToTxIds[$log->id] = $txIds;
                $allTxIds = array_merge($allTxIds, $txIds);
            } else {
                $fallbackIds[] = $log->id;
            }
        }

        // Frozen tx по id-списку.
        $frozenTxIds = [];
        if ($allTxIds) {
            $frozenTxIds = DB::table('transaction as t')
                ->join('period_closures as p', function ($j) {
                    $j->on(DB::raw('p.year::text'), '=', 't.dateYear')
                      ->on(DB::raw("LPAD(p.month::text, 2, '0')"), '=', DB::raw("RIGHT(t.\"dateMonth\", 2)"))
                      ->whereNull('p.reopened_at');
                })
                ->whereIn('t.id', $allTxIds)
                ->pluck('t.id')
                ->all();
            $frozenTxIds = array_flip($frozenTxIds);
        }

        $result = [];
        foreach ($idToTxIds as $logId => $txIds) {
            $result[$logId] = false;
            foreach ($txIds as $txId) {
                if (isset($frozenTxIds[$txId])) {
                    $result[$logId] = true;
                    break;
                }
            }
        }

        // Fallback для старых импортов: comment='Импорт #N'.
        foreach ($fallbackIds as $logId) {
            $result[$logId] = DB::table('transaction as t')
                ->join('period_closures as p', function ($j) {
                    $j->on(DB::raw('p.year::text'), '=', 't.dateYear')
                      ->on(DB::raw("LPAD(p.month::text, 2, '0')"), '=', DB::raw("RIGHT(t.\"dateMonth\", 2)"))
                      ->whereNull('p.reopened_at');
                })
                ->where('t.comment', 'Импорт #' . $logId)
                ->exists();
        }

        return $result;
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

        // Откат бьём на чанки по 100 id: на legacy-схеме у commission есть
        // FK-обратные проверки через "transaction.commissions" массив, и
        // DELETE на 1267 id'шниках разом пробивает statement_timeout PG
        // (FOR KEY SHARE по transaction × 1267 = серверная отмена).
        // Снимаем тайм-аут на этой сессии и удаляем порциями.
        $result = DB::transaction(function () use ($importId, $txIdsFromLog) {
            // Подняли таймаут на 5 минут (соединение всё равно одноразовое).
            DB::statement("SET LOCAL statement_timeout = '300s'");

            $txIds = $txIdsFromLog
                ? collect($txIdsFromLog)->all()
                : DB::table('transaction')
                    ->where('comment', 'Импорт #' . $importId)
                    ->pluck('id')
                    ->all();

            $deletedCommissions = 0;
            foreach (array_chunk($txIds, 100) as $chunk) {
                $deletedCommissions += DB::table('commission')
                    ->whereIn('transaction', $chunk)
                    ->delete();
            }

            $deletedTx = 0;
            foreach (array_chunk($txIds, 100) as $chunk) {
                $deletedTx += DB::table('transaction')
                    ->whereIn('id', $chunk)
                    ->delete();
            }

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
     *
     * Заморозка периода: если хоть одна транзакция импорта попадает в
     * закрытый месяц — пересчёт запрещён (правки закрытых периодов
     * идут через «Прочие начисления»).
     */
    public function calculateCommissions(Request $request, int $importId): JsonResponse
    {
        $log = DB::table('transaction_import_log')->where('id', $importId)->first();
        if (! $log) {
            return response()->json(['message' => 'Импорт не найден'], 404);
        }

        $logCol = collect([$log]);
        $frozenMap = $this->frozenMapForLogs($logCol);
        if ($frozenMap[$importId] ?? false) {
            return response()->json([
                'message' => 'Период этого импорта закрыт — расчёт комиссий запрещён. Для корректировки используйте «Прочие начисления».',
            ], 422);
        }

        // Async: 1267 транзакций × каскад наставников = 5-10 минут,
        // axios timeout 30s падал как «Ошибка расчёта». Теперь Job в
        // очереди, фронт поллит /admin/import-progress.
        $tracker = 'calc-' . $importId . '-' . uniqid('', true);
        \Illuminate\Support\Facades\Cache::put(
            "import:tracker:{$tracker}",
            ['status' => 'starting', 'total' => 0, 'processed' => 0, 'success' => 0, 'errors' => 0],
            1800,
        );
        \App\Jobs\CalculateImportCommissionsJob::dispatch(
            $importId, $tracker, (int) $request->user()->id,
        );

        return response()->json([
            'message' => 'Расчёт комиссий поставлен в очередь',
            'importId' => $importId,
            'tracker' => $tracker,
            'status' => 'queued',
        ], 202);
    }

    /**
     * GET /admin/transaction-import/{id}/errors.csv
     *
     * Скачать список ошибок/предупреждений импорта одним CSV-файлом —
     * чтобы оператор открыл в Excel, пробежал глазами, исправил
     * исходный файл/таблицу и перезалил, не копируя по одной строке
     * из модалки.
     */
    public function errorsCsv(int $importId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $log = DB::table('transaction_import_log')->where('id', $importId)->first();
        if (! $log) abort(404);

        $errors = $log->errors ? (json_decode($log->errors, true) ?? []) : [];
        $warnings = isset($log->warnings) && $log->warnings ? (json_decode($log->warnings, true) ?? []) : [];

        $filename = sprintf('import-%d-errors.csv', $importId);
        return response()->streamDownload(function () use ($errors, $warnings) {
            $h = fopen('php://output', 'w');
            // UTF-8 BOM — иначе Excel неправильно показывает кириллицу.
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Тип', 'Сообщение']);
            foreach ($errors as $e) fputcsv($h, ['Ошибка', is_array($e) ? json_encode($e, JSON_UNESCAPED_UNICODE) : (string) $e]);
            foreach ($warnings as $w) fputcsv($h, ['Предупреждение', is_array($w) ? json_encode($w, JSON_UNESCAPED_UNICODE) : (string) $w]);
            fclose($h);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
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

    /**
     * DELETE /admin/transactions/{id}
     *
     * Удаление зафиксированной транзакции в открытом периоде. Soft-delete
     * самой транзакции и всех её commission по цепочке наставников,
     * затем пересчёт consultantBalance для затронутых (consultant, dateMonth)
     * пар, чтобы snapshot не «висел» на старых суммах. Видимо в админ-UI
     * только admin / calculations (reports-access scope).
     *
     * Frozen period → 422. Нет транзакции → 404. Нет права → 403.
     */
    public function destroy(
        int $id,
        Request $request,
        \App\Services\PeriodFreezeService $freeze,
        \App\Services\CommissionCalculator $calculator
    ): JsonResponse {
        $user = $request->user();
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        if (! array_intersect($roles, ['admin', 'calculations'])) {
            return response()->json([
                'message' => 'Удаление зафиксированных транзакций доступно только администратору и руководителю по расчётам',
            ], 403);
        }

        $tx = DB::table('transaction')->where('id', $id)->whereNull('deletedAt')->first();
        if (! $tx) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }

        $period = $freeze->resolvePeriod(date: $tx->date);
        if ($period && $freeze->isFrozen($period[0], $period[1])) {
            return response()->json([
                'message' => sprintf('Период %02d.%d закрыт — транзакцию нельзя удалить.',
                    $period[1], $period[0]),
            ], 422);
        }

        // Снимаем уникальные (consultant, dateMonth, dateYear) ДО soft-delete,
        // иначе rebuildBalance не увидит ничего и старый snapshot останется.
        $affected = DB::table('commission')
            ->where('transaction', $id)
            ->whereNull('deletedAt')
            ->select('consultant', 'dateMonth', 'dateYear')
            ->distinct()
            ->get();

        // Soft-delete commission/transaction И пересчёт consultantBalance —
        // в одной транзакции. Если PHP упадёт между шагами (timeout/OOM),
        // снапшоты не разойдутся с фактическими commission. Раньше
        // rebuildBalanceFor вызывался ПОСЛЕ commit'а, что давало окно
        // несогласованности на проде.
        $rebuilt = 0;
        $deletedCommissions = DB::transaction(function () use ($id, $affected, $calculator, &$rebuilt) {
            $n = DB::table('commission')
                ->where('transaction', $id)
                ->whereNull('deletedAt')
                ->update(['deletedAt' => now()]);
            DB::table('transaction')
                ->where('id', $id)
                ->update(['deletedAt' => now(), 'dateChanged' => now()]);

            foreach ($affected as $p) {
                if (! $p->consultant || ! $p->dateMonth) continue;
                $calculator->rebuildBalanceFor(
                    (int) $p->consultant,
                    (string) $p->dateMonth,
                    (string) ($p->dateYear ?? substr($p->dateMonth, 0, 4)),
                );
                $rebuilt++;
            }
            return $n;
        });

        // Был ли пул за месяц транзакции уже применён? PoolRunner::persist()
        // пишет в "poolLog" строки с date = первое число месяца. Если запись
        // существует — выплаты партнёрам уже посчитаны старой суммой
        // commission, нужно явно перезапустить пул через карточку периода.
        $poolWasApplied = false;
        $poolPeriod = null;
        if ($period) {
            [$pyear, $pmonth] = $period;
            $from = sprintf('%04d-%02d-01', $pyear, $pmonth);
            $to = date('Y-m-t', strtotime($from));
            $poolWasApplied = DB::table('poolLog')
                ->whereBetween('date', [$from, $to])
                ->exists();
            $poolPeriod = sprintf('%04d-%02d', $pyear, $pmonth);
        }

        \App\Support\Audit::log('delete', 'transaction', $id, [
            'amount' => $tx->amount ?? null,
            'currency' => $tx->currency ?? null,
            'date' => $tx->date ?? null,
            'dateMonth' => $tx->dateMonth ?? null,
            'deletedCommissions' => $deletedCommissions,
            'rebuiltBalances' => $rebuilt,
            'poolWasApplied' => $poolWasApplied,
            'consultants' => $affected->pluck('consultant')->filter()->values()->all(),
        ]);

        $message = sprintf(
            'Транзакция #%d удалена: %d комиссий, пересчитано %d балансов',
            $id, $deletedCommissions, $rebuilt,
        );
        if ($poolWasApplied) {
            $message .= '. Пул за этот месяц уже был применён — нужно пересчитать его вручную.';
        }

        return response()->json([
            'message' => $message,
            'id' => $id,
            'deletedCommissions' => $deletedCommissions,
            'rebuiltBalances' => $rebuilt,
            'poolWasApplied' => $poolWasApplied,
            'poolPeriod' => $poolPeriod,
        ]);
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
