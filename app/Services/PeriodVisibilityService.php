<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per spec ✅Доступность отчётов §2.
 *
 * Управляет видимостью отчётов партнёрам для конкретного месяца.
 *
 * Правило по умолчанию: текущий месяц скрыт от партнёров (идёт сбор
 * транзакций); прошлые месяцы — видимы. Запись в `period_visibility`
 * означает явное переопределение этого дефолта.
 *
 * ⚠ РЕШЕНИЕ АДМИНА ГЛАВНЕЕ ЗАКРЫТИЯ (2026-08-17). Раньше закрытый период
 * ВСЕГДА показывался партнёрам, а явная пометка «скрыть», поставленная ДО
 * закрытия, считалась протухшей. На практике это выстрелило дважды и в разные
 * стороны: сначала закрытые апр/май прятались от ФК (из-за чего правило и
 * появилось), потом — июль сам открылся партнёрам, потому что фиксация пула
 * закрыла период поверх свежего «скрыть» (владелец скрыл отчёты до окончания
 * сверки). Теперь порядок простой и предсказуемый: есть явная пометка —
 * работает она; нет пометки — закрытый период виден, иначе дефолт по дате.
 */
class PeriodVisibilityService
{
    public function isVisible(int $year, int $month): bool
    {
        $row = $this->row($year, $month);

        // Явное решение админа — высший приоритет, в обе стороны.
        if ($row !== null) {
            return (bool) $row->is_visible;
        }

        // Пометки нет: закрытый период виден (сверка окончена, отчёт финальный).
        if ($this->closedAt($year, $month) !== null) {
            return true;
        }
        // Дефолт: текущий месяц — скрыт; прошлые — видны.
        $now = now();
        $isCurrent = ((int) $now->format('Y') === $year) && ((int) $now->format('n') === $month);
        if ($isCurrent) return false;
        $isPast = $year < (int) $now->format('Y')
            || ($year === (int) $now->format('Y') && $month < (int) $now->format('n'));
        return $isPast;
    }

    /** Момент закрытия периода (null — если не закрыт / был разморожен). */
    private function closedAt(int $year, int $month): ?string
    {
        if (! Schema::hasTable('period_closures')) {
            return null;
        }
        $row = DB::table('period_closures')
            ->where('year', $year)
            ->where('month', $month)
            ->whereNull('reopened_at')
            ->first(['closed_at']);

        return $row?->closed_at;
    }

    public function setVisibility(int $year, int $month, bool $visible, ?int $userId = null): void
    {
        if (! Schema::hasTable('period_visibility')) {
            return;
        }
        $row = DB::table('period_visibility')
            ->where('year', $year)
            ->where('month', $month)
            ->first();
        $now = now();
        if ($row) {
            DB::table('period_visibility')->where('id', $row->id)->update([
                'is_visible' => $visible,
                'changed_by' => $userId,
                'changed_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('period_visibility')->insert([
                'year' => $year,
                'month' => $month,
                'is_visible' => $visible,
                'changed_by' => $userId,
                'changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Карта явных переопределений → array<"YYYY-MM" => bool> (для UI-индикаторов). */
    public function explicitMap(): array
    {
        if (! Schema::hasTable('period_visibility')) {
            return [];
        }
        return DB::table('period_visibility')
            ->select('year', 'month', 'is_visible')
            ->get()
            ->mapWithKeys(fn ($r) => [
                sprintf('%04d-%02d', $r->year, $r->month) => (bool) $r->is_visible,
            ])
            ->toArray();
    }

    private function row(int $year, int $month): ?object
    {
        if (! Schema::hasTable('period_visibility')) {
            return null;
        }
        return DB::table('period_visibility')
            ->where('year', $year)
            ->where('month', $month)
            ->first();
    }
}
