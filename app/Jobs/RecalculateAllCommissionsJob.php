<?php

namespace App\Jobs;

use App\Services\CommissionCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Полный перерасчёт комиссий по ВСЕМ транзакциям открытых периодов.
 *
 * Для каждой не удалённой транзакции с датой >= HISTORICAL_CUTOFF вызывает
 * CommissionCalculator::calculateForTransaction — тот резолвит %ДС из матрицы
 * dsCommission, пересобирает цепочку и балансы. Исторические (< cutoff) и
 * закрытые (period_closures) месяцы calculateForTransaction пропускает сам,
 * возвращая error — считаем их skipped, деньги за них не двигаются.
 *
 * Запускается кнопкой «Полный перерасчёт» (роль admin/calculations).
 */
class RecalculateAllCommissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200; // до 2 часов — транзакций может быть тысячи
    public int $tries = 1;

    public function handle(CommissionCalculator $calculator): void
    {
        $cutoff = CommissionCalculator::HISTORICAL_CUTOFF;

        $txIds = DB::table('transaction')
            ->whereNull('deletedAt')
            ->where(function ($q) use ($cutoff) {
                $q->where('date', '>=', $cutoff)->orWhereNull('date');
            })
            ->orderBy('id')
            ->pluck('id');

        Log::info('RecalculateAllCommissionsJob: старт', ['total' => $txIds->count()]);

        $recomputed = 0;
        $skipped = 0;
        foreach ($txIds as $txId) {
            try {
                $res = $calculator->calculateForTransaction((int) $txId);
                if (! empty($res['error'])) {
                    $skipped++;
                } else {
                    $recomputed++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('RecalculateAllCommissionsJob: транзакция упала', [
                    'tx' => $txId, 'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('RecalculateAllCommissionsJob завершён', [
            'recomputed' => $recomputed, 'skipped' => $skipped, 'total' => $txIds->count(),
        ]);
    }
}
