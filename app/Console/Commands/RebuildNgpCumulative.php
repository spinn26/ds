<?php

namespace App\Console\Commands;

use App\Services\CommissionCalculator;
use App\Services\MonthlyPenaltyRunner;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Бэкфилл НГП (groupVolumeCumulative) в qualificationLog.
 *
 * До июня 2026 месячный снимок с приростом НГП писал Directual. Платформенная
 * финализация (MonthlyPenaltyRunner) переносила cumulative без прибавки ГП, а
 * партнёрам уровня < 3 снимок не писала вовсе → с июня НГП замер, отчёт
 * «Квалификации» показывал НГП(тек) = НГП(пред).
 *
 * Команда пересобирает снимки по формуле НГП(месяц) = НГП(до месяца) + ГП(месяц),
 * НЕ трогая комиссии, штрафы и балансы. Месяцы идут по возрастанию.
 */
class RebuildNgpCumulative extends Command
{
    protected $signature = 'partners:rebuild-ngp
        {--from=2026-06 : первый месяц, YYYY-MM (не раньше HISTORICAL_CUTOFF)}
        {--to= : последний месяц, YYYY-MM (по умолчанию текущий)}
        {--apply : записать изменения; без флага — сухой прогон}';

    protected $description = 'Пересобрать накопительный НГП в qualificationLog за диапазон месяцев';

    public function handle(MonthlyPenaltyRunner $runner): int
    {
        $from = Carbon::createFromFormat('Y-m', (string) $this->option('from'))->startOfMonth();
        $to = $this->option('to')
            ? Carbon::createFromFormat('Y-m', (string) $this->option('to'))->startOfMonth()
            : now()->startOfMonth();

        // Исторические периоды (< 2026-06) — эталон Directual, не переписываем.
        $cutoff = Carbon::parse(CommissionCalculator::HISTORICAL_CUTOFF)->startOfMonth();
        if ($from->lt($cutoff)) {
            $this->error('--from раньше HISTORICAL_CUTOFF (' . $cutoff->format('Y-m') . ') — история Directual не переписывается');

            return self::FAILURE;
        }
        if ($to->lt($from)) {
            $this->error('--to раньше --from');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $totalUpdated = 0;
        $totalInserted = 0;

        for ($m = $from->copy(); $m->lte($to); $m->addMonth()) {
            $label = $m->format('Y-m');

            if (! $apply) {
                $this->line("[dry] {$label}: " . $this->previewLine($m));

                continue;
            }

            $res = DB::transaction(fn () => $runner->rebuildMonthlySnapshots($m->year, $m->month));
            $totalUpdated += $res['updated'];
            $totalInserted += $res['inserted'];
            $this->info("{$label}: обновлено {$res['updated']}, добавлено {$res['inserted']}");
        }

        if (! $apply) {
            $this->warn('Сухой прогон. Повторите с --apply, чтобы записать.');

            return self::SUCCESS;
        }

        $this->info("Готово: обновлено {$totalUpdated}, добавлено {$totalInserted}");

        return self::SUCCESS;
    }

    /** Сколько строк на конец месяца имеют НГП, не равный «база + ГП». */
    private function previewLine(Carbon $m): string
    {
        $monthStart = $m->copy()->startOfMonth()->toDateTimeString();
        $monthEnd = $m->copy()->endOfMonth()->toDateTimeString();

        $rows = DB::select(<<<'SQL'
            SELECT COUNT(*) AS total,
                   COUNT(*) FILTER (
                       WHERE ROUND(COALESCE(q."groupVolumeCumulative", 0)::numeric, 2)
                         <> ROUND((COALESCE((
                                SELECT p."groupVolumeCumulative"
                                FROM "qualificationLog" p
                                WHERE p.consultant = q.consultant
                                  AND p."dateDeleted" IS NULL
                                  AND p."groupVolumeCumulative" IS NOT NULL
                                  AND p.date < ?
                                ORDER BY p.date DESC
                                LIMIT 1
                            ), 0) + COALESCE(q."groupVolume", 0))::numeric, 2)
                   ) AS drifted
            FROM "qualificationLog" q
            WHERE q.date = ? AND q."dateDeleted" IS NULL
            SQL, [$monthStart, $monthEnd]);

        $r = $rows[0] ?? null;

        return sprintf('строк на конец месяца %d, расходится НГП у %d', $r->total ?? 0, $r->drifted ?? 0);
    }
}
