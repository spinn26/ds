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
        $txIds = DB::table('transaction')
            ->where('comment', 'Импорт #' . $this->importLogId)
            ->pluck('id')
            ->all();

        $total = count($txIds);
        $this->putTracker([
            'status' => 'running', 'total' => $total, 'processed' => 0,
            'success' => 0, 'errors' => 0, 'phase' => 'calc',
        ]);

        if ($total === 0) {
            $this->putTracker([
                'status' => 'done', 'total' => 0, 'processed' => 0,
                'success' => 0, 'errors' => 0,
                'importId' => $this->importLogId,
                'message' => 'В импорте нет транзакций для расчёта.',
            ]);
            return;
        }

        $success = 0;
        $errors = 0;
        $errorDetails = [];
        $errorRows = [];

        foreach ($txIds as $i => $txId) {
            try {
                $result = $calculator->calculateForTransaction($txId);
                if (! empty($result['success'])) {
                    $success++;
                } else {
                    $errors++;
                    $msg = "tx#{$txId}: " . ($result['error'] ?? 'unknown');
                    $errorDetails[] = $msg;
                    $errorRows[] = ['transaction' => $txId, 'error' => $result['error'] ?? null];
                }
            } catch (\Throwable $e) {
                $errors++;
                $msg = "tx#{$txId}: " . $e->getMessage();
                $errorDetails[] = $msg;
                $errorRows[] = ['transaction' => $txId, 'error' => $e->getMessage()];
                Log::warning('Calc failed in job', ['tx' => $txId, 'error' => $e->getMessage()]);
            }

            // Прогресс — каждые 25 строк (или на последней).
            if (($i + 1) % 25 === 0 || $i === $total - 1) {
                $this->putTracker([
                    'status' => 'running', 'total' => $total, 'processed' => $i + 1,
                    'success' => $success, 'errors' => $errors, 'phase' => 'calc',
                ]);
            }
        }

        $existingErrors = DB::table('transaction_import_log')->where('id', $this->importLogId)->value('errors');
        $existingErrors = $existingErrors ? (json_decode($existingErrors, true) ?? []) : [];
        $calcSummary = ["Расчёт комиссий: {$success} из {$total}"];
        $combined = array_merge($calcSummary, array_slice($errorDetails, 0, 50), $existingErrors);

        DB::table('transaction_import_log')->where('id', $this->importLogId)->update([
            'errors' => json_encode($combined, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

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
}
