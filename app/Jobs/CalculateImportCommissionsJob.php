<?php

namespace App\Jobs;

use App\Services\CommissionCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async расчёт комиссий по всем транзакциям импорта.
 *
 * Раньше TransactionImportController::calculateCommissions делал это
 * синхронно. На 1267 транзакций × каскад (5-10 наставников × commission
 * INSERT × consultantBalance rebuild) уходит 5-10 минут — axios падает
 * по 30s timeout'у, фронт показывает «Ошибка расчёта», хотя сервер
 * добил работу.
 *
 * Теперь контроллер сразу отдаёт 202 + tracker, фронт поллит
 * /admin/import-progress, а Job пишет прогресс по ходу выполнения.
 */
class CalculateImportCommissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;
    public int $maxExceptions = 1;

    public function __construct(
        public readonly int $importLogId,
        public readonly string $tracker,
        public readonly int $userId,
    ) {}

    public function handle(CommissionCalculator $calculator): void
    {
        // Источник — transaction_import_log.created_ids (JSON массив id'ов,
        // которые реально вставил Job). Раньше брали из comment='Импорт #N',
        // но это давало coupling-баг: если оператор вручную создал
        // транзакцию с таким же комментом — попадала в расчёт. created_ids
        // фиксируется атомарно в bulk INSERT (ImportTransactionsJob:307).
        $log = DB::table('transaction_import_log')->find($this->importLogId);
        $createdIds = is_string($log?->created_ids ?? null)
            ? (json_decode($log->created_ids, true) ?: [])
            : ((array) ($log->created_ids ?? []));
        $txIds = array_values(array_filter(array_map('intval', $createdIds)));

        // Fallback на comment — для legacy-логов до миграции 2026_04_21
        // (когда created_ids ещё не было). Если этих логов на проде нет,
        // ветка не сработает.
        if (! $txIds) {
            $txIds = DB::table('transaction')
                ->where('comment', 'Импорт #' . $this->importLogId)
                ->pluck('id')
                ->all();
        }

        $total = count($txIds);
        $this->putTracker([
            'status' => 'running', 'total' => $total, 'processed' => 0,
            'success' => 0, 'errors' => 0, 'phase' => 'calc',
        ]);
        $this->updateImportLog([
            'calc_status' => 'running',
            'calc_total' => $total,
            'calc_success' => 0,
            'calc_errors' => 0,
            'calc_done_at' => null,
        ]);

        if ($total === 0) {
            $this->putTracker([
                'status' => 'done', 'total' => 0, 'processed' => 0,
                'success' => 0, 'errors' => 0,
                'importId' => $this->importLogId,
                'message' => 'В импорте нет транзакций для расчёта.',
            ]);
            $this->updateImportLog([
                'calc_status' => 'done',
                'calc_done_at' => now(),
            ]);
            return;
        }

        $success = 0;
        $errors = 0;
        $errorDetails = [];

        foreach ($txIds as $i => $txId) {
            try {
                $result = $calculator->calculateForTransaction($txId);
                if (! empty($result['success'])) {
                    $success++;
                } else {
                    $errors++;
                    $errorDetails[] = "tx#{$txId}: " . ($result['error'] ?? 'unknown');
                }
            } catch (\Throwable $e) {
                $errors++;
                $errorDetails[] = "tx#{$txId}: " . $e->getMessage();
                Log::warning('Calc failed in job', ['tx' => $txId, 'error' => $e->getMessage()]);
            }

            // Прогресс — каждые 25 строк (или на последней). Пишем и
            // в tracker (для polling-фронта), и в transaction_import_log
            // (для бейджа «Рассчитано: 350/1267» в истории).
            if (($i + 1) % 25 === 0 || $i === $total - 1) {
                $this->putTracker([
                    'status' => 'running', 'total' => $total, 'processed' => $i + 1,
                    'success' => $success, 'errors' => $errors, 'phase' => 'calc',
                ]);
                $this->updateImportLog([
                    'calc_success' => $success,
                    'calc_errors' => $errors,
                ]);
            }
        }

        $finalStatus = $errors === 0 ? 'done' : ($success > 0 ? 'partial' : 'error');
        $logUpdate = [
            'calc_status' => $finalStatus,
            'calc_total' => $total,
            'calc_success' => $success,
            'calc_errors' => $errors,
            'calc_done_at' => now(),
        ];

        // В `errors` дописываем ТОЛЬКО реально упавшие транзакции.
        // Сводка «Расчёт комиссий: X из Y» больше не пишется туда — она
        // есть в новых колонках calc_*, а иконка «Ошибки» в UI должна
        // подсвечиваться лишь когда есть что-то реально требующее
        // внимания (валидация импорта, FK violation, упавшие расчёты).
        if (! empty($errorDetails)) {
            $existingErrors = DB::table('transaction_import_log')
                ->where('id', $this->importLogId)->value('errors');
            $existingErrors = $existingErrors ? (json_decode($existingErrors, true) ?? []) : [];
            $combined = array_merge(array_slice($errorDetails, 0, 50), $existingErrors);
            $logUpdate['errors'] = json_encode($combined, JSON_UNESCAPED_UNICODE);
        }

        $this->updateImportLog($logUpdate);

        $this->putTracker([
            'status' => 'done', 'total' => $total, 'processed' => $total,
            'success' => $success, 'errors' => $errors,
            'importId' => $this->importLogId,
            'message' => "Расчёт завершён: {$success} из {$total}"
                . ($errors > 0 ? ", ошибок: {$errors}" : ''),
            'errorDetails' => array_slice($errorDetails, 0, 50),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->updateImportLog([
            'calc_status' => 'error',
            'calc_done_at' => now(),
        ]);
        $this->putTracker([
            'status' => 'done', 'total' => 0, 'processed' => 0,
            'success' => 0, 'errors' => 1,
            'importId' => $this->importLogId,
            'message' => 'Расчёт не выполнен: ' . $e->getMessage(),
            'errorDetails' => [$e->getMessage()],
        ]);
    }

    private function putTracker(array $state): void
    {
        Cache::put("import:tracker:{$this->tracker}", $state, 1800);
    }

    /**
     * Безопасный апдейт transaction_import_log: пишем только те поля,
     * для которых соответствующие колонки реально существуют. Миграция
     * 2026_05_23_000010 могла не накатиться (старая ветка / dev) —
     * без guard будет 42703.
     */
    private function updateImportLog(array $data): void
    {
        $allowed = ['calc_status', 'calc_total', 'calc_success', 'calc_errors',
            'calc_done_at', 'errors'];
        $update = ['updated_at' => now()];
        foreach ($data as $col => $val) {
            if (! in_array($col, $allowed, true)) continue;
            if (\Illuminate\Support\Facades\Schema::hasColumn('transaction_import_log', $col)) {
                $update[$col] = $val;
            }
        }
        DB::table('transaction_import_log')->where('id', $this->importLogId)->update($update);
    }
}
