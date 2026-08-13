<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async откат импорта — удаление всех транзакций импорта вместе с
 * рассчитанными по ним комиссиями.
 *
 * Раньше TransactionImportController::rollback делал это синхронно. На
 * 1195 транзакциях (импорт #47) удаление комиссий + каскад-триггеры
 * идёт минутами, а axios рвёт запрос по 30s timeout'у — фронт показывал
 * «Нет связи с сервером», хотя сервер откат добивал. Ровно та же грабля,
 * что была у расчёта (см. CalculateImportCommissionsJob).
 *
 * Теперь контроллер сразу отдаёт 202 + tracker, фронт поллит
 * /admin/import-progress, а Job пишет прогресс по ходу удаления.
 */
class RollbackImportJob implements ShouldQueue
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

    public function handle(): void
    {
        // Предпочитаем точечный список created_ids (новые импорты). Для
        // старых импортов, где created_ids ещё не заполнялся, fallback
        // на comment='Импорт #N'.
        $rawCreated = DB::table('transaction_import_log')
            ->where('id', $this->importLogId)
            ->value('created_ids');
        $createdIds = is_string($rawCreated)
            ? (json_decode($rawCreated, true) ?: [])
            : (array) ($rawCreated ?? []);
        $txIds = array_values(array_filter(array_map('intval', $createdIds)));

        if (! $txIds) {
            $txIds = DB::table('transaction')
                ->where('comment', 'Импорт #' . $this->importLogId)
                ->pluck('id')
                ->all();
        }

        $total = count($txIds);
        $this->putTracker([
            'status' => 'running', 'total' => $total, 'processed' => 0,
            'success' => 0, 'errors' => 0, 'phase' => 'rollback',
        ]);

        if ($total === 0) {
            DB::table('transaction_import_log')
                ->where('id', $this->importLogId)
                ->update(['status' => 'rolled_back', 'updated_at' => now()]);

            $this->putTracker([
                'status' => 'done', 'total' => 0, 'processed' => 0,
                'success' => 0, 'errors' => 0,
                'importId' => $this->importLogId,
                'message' => 'В импорте нет транзакций — импорт помечен откаченным.',
            ]);
            return;
        }

        // Откат бьём на чанки по 100 id: на legacy-схеме у commission есть
        // FK-обратные проверки через "transaction.commissions" массив, и
        // DELETE на 1267 id'шниках разом пробивает statement_timeout PG
        // (FOR KEY SHARE по transaction × 1267 = серверная отмена).
        // Снимаем тайм-аут на этой сессии и удаляем порциями.
        //
        // Всё в одной транзакции, чтобы откат не оставил orphan-комиссии
        // если что-то упадёт посередине.
        $result = DB::transaction(function () use ($txIds, $total) {
            DB::statement("SET LOCAL statement_timeout = '1800s'");

            $deletedCommissions = 0;
            $deletedTx = 0;
            $processed = 0;

            foreach (array_chunk($txIds, 100) as $chunk) {
                // Порядок внутри чанка: сначала комиссии, потом сами
                // транзакции — иначе FK не даст удалить транзакцию.
                $deletedCommissions += DB::table('commission')
                    ->whereIn('transaction', $chunk)
                    ->delete();

                $deletedTx += DB::table('transaction')
                    ->whereIn('id', $chunk)
                    ->delete();

                $processed += count($chunk);
                $this->putTracker([
                    'status' => 'running', 'total' => $total, 'processed' => $processed,
                    'success' => $deletedTx, 'errors' => 0, 'phase' => 'rollback',
                    'deletedCommissions' => $deletedCommissions,
                ]);
            }

            DB::table('transaction_import_log')
                ->where('id', $this->importLogId)
                ->update([
                    'status' => 'rolled_back',
                    'updated_at' => now(),
                ]);

            return [
                'deleted_transactions' => $deletedTx,
                'deleted_commissions' => $deletedCommissions,
            ];
        });

        $this->putTracker([
            'status' => 'done', 'total' => $total, 'processed' => $total,
            'success' => $result['deleted_transactions'], 'errors' => 0,
            'importId' => $this->importLogId,
            'deletedCommissions' => $result['deleted_commissions'],
            'message' => "Откат выполнен: удалено {$result['deleted_transactions']} транзакций"
                . " и {$result['deleted_commissions']} комиссий",
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Rollback job failed', [
            'import' => $this->importLogId,
            'error' => $e->getMessage(),
        ]);

        // Статус лога НЕ трогаем: транзакция откатилась целиком, импорт
        // остался в прежнем состоянии — оператор может повторить откат.
        $this->putTracker([
            'status' => 'done', 'total' => 0, 'processed' => 0,
            'success' => 0, 'errors' => 1,
            'importId' => $this->importLogId,
            'message' => 'Откат не выполнен: ' . $e->getMessage(),
            'errorDetails' => [$e->getMessage()],
        ]);
    }

    private function putTracker(array $state): void
    {
        Cache::put("import:tracker:{$this->tracker}", $state, 1800);
    }
}
