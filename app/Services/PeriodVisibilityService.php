<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per spec ✅Доступность отчётов §2.
 *
 * Управляет видимостью отчётов партнёрам для конкретного месяца.
 * Это НЕ заморозка — закрытый период всё ещё может быть скрыт от
 * партнёров (и наоборот, открытый можно временно закрыть для правок).
 *
 * Правило по умолчанию: текущий месяц скрыт от партнёров (идёт сбор
 * транзакций); прошлые месяцы — видимы. Запись в `period_visibility`
 * означает явное переопределение этого дефолта.
 */
class PeriodVisibilityService
{
    public function isVisible(int $year, int $month): bool
    {
        $row = $this->row($year, $month);
        if ($row !== null) {
            return (bool) $row->is_visible;
        }
        // Дефолт: текущий месяц — скрыт; прошлые — видны.
        $now = now();
        $isCurrent = ((int) $now->format('Y') === $year) && ((int) $now->format('n') === $month);
        if ($isCurrent) return false;
        $isPast = $year < (int) $now->format('Y')
            || ($year === (int) $now->format('Y') && $month < (int) $now->format('n'));
        return $isPast;
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
