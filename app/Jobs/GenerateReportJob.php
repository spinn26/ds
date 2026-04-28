<?php

namespace App\Jobs;

use App\Services\ReportGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async-генерация отчёта (per spec ✅Отчеты.md §2.1).
 *
 * Создаётся через AdminFinanceController::generateReport, попадает
 * в очередь, обрабатывается воркером (queue:work). Запись в
 * report_archive создаётся СРАЗУ со статусом «generating», поэтому
 * пользователь видит её в архиве и может уйти в другой раздел.
 *
 * При успехе job переключает статус на «ready» и кладёт CSV в storage.
 * При ошибке — статус «error» с сообщением.
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 мин на тяжёлый отчёт
    public int $tries = 1;

    public function __construct(
        public int $reportId,
    ) {}

    public function handle(ReportGenerator $generator): void
    {
        try {
            $generator->generateAsArchived($this->reportId);
        } catch (\Throwable $e) {
            Log::error('GenerateReportJob failed', [
                'reportId' => $this->reportId,
                'error' => $e->getMessage(),
            ]);
            DB::table('report_archive')->where('id', $this->reportId)->update([
                'status' => 'error',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        DB::table('report_archive')->where('id', $this->reportId)->update([
            'status' => 'error',
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
            'updated_at' => now(),
        ]);
    }
}
