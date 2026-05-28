<?php

namespace App\Console\Commands;

use App\Services\MonthlyPenaltyRunner;
use Illuminate\Console\Command;

/**
 * Применить Отрыв + недобор ОП к commission-строкам месяца.
 *
 *   php artisan finalize:apply              # текущий месяц
 *   php artisan finalize:apply 2026 5       # явно за месяц
 *   php artisan finalize:apply --dry-run    # превью без записи
 *
 * Используется ежедневным расписанием (routes/console.php) — пересчёт
 * текущего месяца ночью, чтобы карточка партнёра, отчёт Зарипова и
 * прочие сводные виды всегда отражали актуальный отрыв/ОП без
 * необходимости вручную жать «Применить штрафы».
 *
 * MonthlyPenaltyRunner идемпотентный: повторный запуск откатывает
 * прошлые withheld* и считает заново от brutto, поэтому ежедневный
 * автоматический прогон безопасен и для уже финализированных месяцев.
 *
 * На закрытом периоде runner вернёт frozen=true и ничего не запишет.
 */
class FinalizeMonthPenalties extends Command
{
    protected $signature = 'finalize:apply
                            {year? : Год (default: текущий)}
                            {month? : Месяц 1-12 (default: текущий)}
                            {--dry-run : Только превью, без записи}';

    protected $description = 'Применить отрыв + недобор ОП к commission-строкам месяца';

    public function handle(MonthlyPenaltyRunner $runner): int
    {
        $year = (int) ($this->argument('year') ?: now()->year);
        $month = (int) ($this->argument('month') ?: now()->month);
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s финализация за %04d-%02d ...',
            $dryRun ? 'Превью' : 'Применяю',
            $year,
            $month,
        ));

        $result = $runner->run($year, $month, applyWrite: ! $dryRun);

        if ($result['frozen'] ?? false) {
            $this->warn("Период {$month}.{$year} закрыт — финализация пропущена.");
            return self::SUCCESS;
        }

        $withheld = 0.0;
        foreach ($result['consultants'] as $c) {
            $withheld += (float) ($c['withheldTotalRub'] ?? 0);
        }

        $this->line(sprintf(
            '  consultants_processed=%d  affected_commissions=%d  withheld_rub=%s',
            $result['processed'] ?? 0,
            $result['affected'] ?? 0,
            number_format($withheld, 2, '.', ' '),
        ));

        return self::SUCCESS;
    }
}
