<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Хранитель заморозки отчётных месяцев.
 *
 * Один метод — `guard($year, $month)` — бросает ошибку, если месяц
 * закрыт. Зовётся из контроллеров перед любой модификацией
 * transaction / commission / qualificationLog / poolLog за этот
 * период. Правка закрытого месяца запрещена по спеке
 * ./.claude/specs/✅Комиссии .md Part 2 §1.
 */
class PeriodFreezeService
{
    /** Вернуть true если месяц закрыт и не был заново открыт. */
    public function isFrozen(int $year, int $month): bool
    {
        return DB::table('period_closures')
            ->where('year', $year)
            ->where('month', $month)
            ->whereNull('reopened_at')
            ->exists();
    }

    /**
     * Закрыть месяц. Повторный вызов — no-op.
     */
    public function close(int $year, int $month, ?int $userId = null, ?string $note = null): void
    {
        $existing = DB::table('period_closures')
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existing) {
            // Re-close after a reopen: clear reopened_*, refresh closed_*.
            DB::table('period_closures')
                ->where('id', $existing->id)
                ->update([
                    'closed_at' => now(),
                    'closed_by' => $userId,
                    'reopened_at' => null,
                    'reopened_by' => null,
                    'note' => $note,
                    'updated_at' => now(),
                ]);
            return;
        }

        DB::table('period_closures')->insert([
            'year' => $year,
            'month' => $month,
            'closed_at' => now(),
            'closed_by' => $userId,
            'note' => $note,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Разморозить месяц для правок (редкая операция, под аудитом). */
    public function reopen(int $year, int $month, ?int $userId = null): void
    {
        DB::table('period_closures')
            ->where('year', $year)
            ->where('month', $month)
            ->whereNull('reopened_at')
            ->update([
                'reopened_at' => now(),
                'reopened_by' => $userId,
                'updated_at' => now(),
            ]);
    }

    /**
     * Выкинуть HTTP 422 если месяц закрыт. Удобный guard для
     * контроллерных веток update/delete.
     */
    public function guard(int $year, int $month): void
    {
        if ($this->isFrozen($year, $month)) {
            abort(422, "Период {$month}.{$year} закрыт. Изменения в закрытых месяцах запрещены — используйте раздел «Прочие начисления» для корректировок.");
        }
    }

    /** Нормализовать `date | year+month | dateMonth` в (int,int). */
    public function resolvePeriod(?string $date = null, ?int $year = null, ?int $month = null, ?string $dateMonth = null): ?array
    {
        if ($year && $month) {
            return [$year, $month];
        }
        if ($date) {
            $d = \Carbon\Carbon::parse($date);
            return [(int) $d->format('Y'), (int) $d->format('n')];
        }
        if ($dateMonth) {
            // Legacy "YYYY-MM" format used in transaction/commission tables.
            [$y, $m] = array_map('intval', explode('-', $dateMonth) + [0, 0]);
            return $y && $m ? [$y, $m] : null;
        }
        return null;
    }
}
