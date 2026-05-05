<?php

namespace App\Jobs;

use App\Http\Controllers\Api\NotificationController;
use App\Services\PeriodFreezeService;
use App\Services\PoolRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Async-применение пула за период.
 *
 * Раньше POST /admin/pool/apply делал расчёт и DELETE+INSERT в poolLog
 * синхронно в HTTP-контексте. На 500K commission-строк это легко вешает
 * php-fpm worker на минуты, а если nginx-таймаут < времени расчёта —
 * 504 в браузере и расчёт прерывается посреди.
 *
 * Job-вариант: контроллер кладёт сюда задачу, фронт получает batch_id
 * и поллит `Cache::get("pool-apply:{batch_id}")` за прогрессом.
 */
class ApplyPoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;   // 10 мин — пул считается обычно за секунды, запас на 500K строк
    public int $tries = 1;       // ретраи опасны: DELETE+INSERT идемпотентен, но дублировать pool log нельзя
    public int $maxExceptions = 1;

    public function __construct(
        public readonly string $batchId,
        public readonly int $year,
        public readonly int $month,
        public readonly ?int $userId = null,
    ) {}

    public function handle(PoolRunner $runner, PeriodFreezeService $freeze): void
    {
        $this->setProgress('running', 0, 'Старт расчёта пула…');

        try {
            if ($freeze->isFrozen($this->year, $this->month)) {
                $this->setProgress('error', 100,
                    sprintf('Период %02d.%d уже зафиксирован — перезапись запрещена.',
                        $this->month, $this->year));
                return;
            }

            $this->setProgress('running', 30, 'Сбор participants и расчёт долей…');
            $result = $runner->run($this->year, $this->month, applyWrite: true);

            if ($result['frozen'] ?? false) {
                $this->setProgress('error', 100, 'Период заморозился пока шёл расчёт');
                return;
            }

            $this->setProgress('running', 80, 'Закрытие периода…');
            $freeze->close($this->year, $this->month, $this->userId,
                'Зафиксировано через расчёт пула');

            // Уведомление staff — как было в синхронном flow.
            NotificationController::notifyStaff(
                'payment',
                sprintf('Пул зафиксирован: %02d.%d', $this->month, $this->year),
                sprintf('Записано %d строк, выплачено %s ₽',
                    $result['written'] ?? 0,
                    number_format($result['totalPaid'] ?? 0, 0, '.', ' ')),
                sprintf('/manage/periods/%d-%02d', $this->year, $this->month),
            );

            $this->setProgress('done', 100, sprintf(
                'Готово: %d строк, %s ₽',
                $result['written'] ?? 0,
                number_format($result['totalPaid'] ?? 0, 0, '.', ' ')
            ), [
                'written' => $result['written'] ?? 0,
                'totalPaid' => $result['totalPaid'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('ApplyPoolJob failed', [
                'batchId' => $this->batchId,
                'year' => $this->year,
                'month' => $this->month,
                'exception' => $e->getMessage(),
            ]);
            $this->setProgress('error', 100, $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->setProgress('error', 100, 'Job failed: ' . $e->getMessage());
    }

    /**
     * Прогресс пишется в Cache (TTL 30 мин). Контроллер читает его в
     * GET /admin/pool/progress?batch_id=…
     */
    private function setProgress(string $status, int $percent, string $message, array $extra = []): void
    {
        Cache::put("pool-apply:{$this->batchId}", array_merge([
            'status' => $status,         // running / done / error
            'percent' => $percent,
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ], $extra), now()->addMinutes(30));
    }
}
