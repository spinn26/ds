<?php

namespace App\Console\Commands;

use App\Services\PeriodFreezeService;
use App\Services\PoolRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Per spec ✅Расчет пула — пересчёт пула за месяц по актуальной логике
 * (revenue 1% / fund / share per level + матрёшка + дисквалификация по
 * ОП и отрыву > 90%).
 *
 * Удаляет существующие записи poolLog за указанный период и записывает
 * новые. Безопасно повторно запускать (idempotent).
 *
 * ВАЖНО: исторические периоды (есть запись в poolLog от Directual)
 * по умолчанию НЕ перезаписываются — там источник правды CSV / Directual.
 * Чтобы всё-таки пересчитать историю, нужен явный --force-history.
 *
 *   php artisan pool:recalc 2026-04              # текущий открытый месяц
 *   php artisan pool:recalc 2024-06 --to=2026-03 # диапазон
 *   php artisan pool:recalc all                  # все месяцы где есть poolLog
 *   php artisan pool:recalc 2026-02 --dry-run    # без записи
 *   php artisan pool:recalc 2024-12 --force-history  # реально перезаписать legacy
 *   php artisan pool:recalc 2026-02 --force-frozen # обойти заморозку периода
 */
class PoolRecalc extends Command
{
    protected $signature = 'pool:recalc
                            {month : YYYY-MM или ключевое слово all}
                            {--to= : Конец диапазона YYYY-MM (включительно)}
                            {--dry-run : Не записывать в poolLog}
                            {--force-frozen : Игнорировать заморозку периода}
                            {--force-history : Перезаписать legacy-данные poolLog (по умолчанию пропускаются)}';

    protected $description = 'Пересчитать leader-пул за месяц/диапазон по новой формуле спеки.';

    public function handle(PoolRunner $runner, PeriodFreezeService $freeze): int
    {
        $months = $this->resolveMonths();
        if (! $months) {
            $this->error('Не удалось распарсить период.');
            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $forceFrozen = (bool) $this->option('force-frozen');
        $forceHistory = (bool) $this->option('force-history');

        $this->info(sprintf(
            'Будет обработано месяцев: %d%s',
            count($months),
            $dryRun ? ' (DRY-RUN — без записи)' : ''
        ));
        $totalOld = 0;
        $totalNew = 0;

        foreach ($months as [$year, $month]) {
            $isFrozen = $freeze->isFrozen($year, $month);
            if ($isFrozen && ! $forceFrozen) {
                $this->warn(sprintf('  %04d-%02d: ПРОПУЩЕН (период закрыт). --force-frozen чтобы пересчитать.', $year, $month));
                continue;
            }

            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = date('Y-m-t', strtotime($start));

            $oldSum = (float) DB::table('poolLog')
                ->whereBetween('date', [$start, $end])
                ->sum('poolBonus');
            $oldCount = DB::table('poolLog')
                ->whereBetween('date', [$start, $end])
                ->count();

            // Защита: исторические периоды (с уже записанным poolLog от
            // Directual или предыдущим расчётом) пропускаем по умолчанию.
            // Это предотвращает случайное перезатирание эталонных данных.
            if ($oldCount > 0 && ! $forceHistory && ! $dryRun) {
                $this->warn(sprintf(
                    '  %04d-%02d: ПРОПУЩЕН (уже есть %d записей в poolLog, %d ₽). --force-history чтобы перезаписать.',
                    $year, $month, $oldCount, round($oldSum)
                ));
                continue;
            }

            if ($dryRun) {
                // Без записи: запускаем в транзакции и откатываем.
                // PoolRunner::run() в applyWrite-режиме ходит мимо
                // legacy-данных в poolLog и считает заново — нам это и нужно.
                DB::beginTransaction();
                try {
                    DB::table('poolLog')
                        ->whereBetween('date', [$start, $end])
                        ->delete();
                    $res = $runner->run($year, $month, true);
                } finally {
                    DB::rollBack();
                }
                $newSum = (float) $res['totalPaid'];
                $newCount = count(array_filter(
                    $res['participants'],
                    fn ($p) => ($p['payoutRub'] ?? 0) > 0
                ));
                $marker = '[dry]';
            } else {
                // Удаляем legacy-записи и записываем заново.
                DB::table('poolLog')
                    ->whereBetween('date', [$start, $end])
                    ->delete();
                $res = $runner->run($year, $month, true);
                $newSum = (float) $res['totalPaid'];
                $newCount = (int) ($res['written'] ?? 0);
                $marker = '[written]';
            }

            $totalOld += $oldSum;
            $totalNew += $newSum;
            $this->line(sprintf(
                '  %04d-%02d %s leaders=%d  was: %d ₽ × %d  →  now: %d ₽ × %d  (Δ=%+d ₽)',
                $year, $month, $marker,
                count($res['participants']),
                round($oldSum), $oldCount,
                round($newSum), $newCount,
                round($newSum - $oldSum)
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            'ИТОГО: было %d ₽ → стало %d ₽ (Δ %+d ₽)',
            round($totalOld), round($totalNew), round($totalNew - $totalOld)
        ));

        return self::SUCCESS;
    }

    /** @return array<int,array{0:int,1:int}> */
    private function resolveMonths(): array
    {
        $arg = strtolower(trim((string) $this->argument('month')));

        if ($arg === 'all') {
            $rows = DB::select("
                SELECT DISTINCT to_char(date, 'YYYY-MM') AS m
                FROM \"poolLog\"
                ORDER BY m
            ");
            return array_map(fn ($r) => array_map('intval', explode('-', $r->m)), $rows);
        }

        $from = $this->parseMonth($arg);
        if (! $from) return [];
        $toArg = (string) ($this->option('to') ?? '');
        $to = $toArg ? $this->parseMonth($toArg) : $from;
        if (! $to) return [];

        // Перечень месяцев от $from до $to включительно.
        $cursor = \Carbon\Carbon::create($from[0], $from[1], 1)->startOfMonth();
        $end = \Carbon\Carbon::create($to[0], $to[1], 1)->startOfMonth();
        $out = [];
        while ($cursor->lte($end)) {
            $out[] = [(int) $cursor->format('Y'), (int) $cursor->format('n')];
            $cursor->addMonth();
        }
        return $out;
    }

    /** @return array{0:int,1:int}|null */
    private function parseMonth(string $s): ?array
    {
        if (! preg_match('/^(\d{4})-(\d{1,2})$/', trim($s), $m)) return null;
        $y = (int) $m[1];
        $mo = (int) $m[2];
        if ($mo < 1 || $mo > 12) return null;
        return [$y, $mo];
    }
}
