<?php

namespace App\Jobs;

use App\Services\CommissionCalculator;
use App\Services\CurrencyRecalculator;
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

    public function handle(CommissionCalculator $calculator, CurrencyRecalculator $currency): void
    {
        $cutoff = CommissionCalculator::HISTORICAL_CUTOFF;

        // 1) Переприменяем актуальные курсы валют ко ВСЕМ не-рублёвым курсам
        // открытых периодов (amountRUB = сумма × новый курс). Иначе пересчёт
        // комиссий ниже возьмёт устаревший рублёвый эквивалент. recalcForRate
        // сам пропускает исторические/закрытые месяцы.
        $rateIds = DB::table('currencyRate')
            ->where('currency', '!=', 67) // не RUB
            ->where('date', '>=', $cutoff)
            ->orderBy('date')
            ->pluck('id');
        $ratesApplied = 0;
        foreach ($rateIds as $rid) {
            try {
                $currency->recalcForRate((int) $rid);
                $ratesApplied++;
            } catch (\Throwable $e) {
                Log::warning('RecalculateAllCommissionsJob: курс упал', ['rate' => $rid, 'error' => $e->getMessage()]);
            }
        }
        Log::info('RecalculateAllCommissionsJob: курсы переприменены', ['rates' => $ratesApplied]);

        // 2) Пересчёт комиссий по всем открытым транзакциям.
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
