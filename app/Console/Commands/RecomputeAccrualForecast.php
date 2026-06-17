<?php

namespace App\Console\Commands;

use App\Services\AccrualForecastService;
use Illuminate\Console\Command;

/**
 * Ночной пересчёт прогноза начисления по всем контрактам.
 * Подхватывает появившиеся транзакции (прогноз → факт) и смену статусов.
 */
class RecomputeAccrualForecast extends Command
{
    protected $signature = 'contracts:recompute-accrual-forecast';

    protected $description = 'Recompute contract.accrual_forecast (activation-month forecast / actual accrual date)';

    public function handle(AccrualForecastService $service): int
    {
        $affected = $service->recomputeAll();
        $this->info("accrual_forecast recomputed: {$affected} contracts touched");

        return self::SUCCESS;
    }
}
