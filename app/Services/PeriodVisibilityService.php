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
 * ⚠ ЗАКРЫТЫЙ (зафиксированный) период ВСЕГДА виден партнёру: фиксация =
 * финальная сверка, отчёт неизменен, скрывать нечего. Заморозка
 * перекрывает и дефолт, и УСТАРЕВШУЮ явную запись is_visible=false,
 * проставленную ДО закрытия (кейс апр/май 2026: админ скрыл их на время
 * пересчёта 3–5 июня, закрыл 1 июля — записи `false` протухли и прятали
 * закрытые отчёты от ФК). Если админ скрывает период уже ПОСЛЕ закрытия
 * (запись видимости новее closed_at) — его решение уважается.
 */
class PeriodVisibilityService
{
    public function isVisible(int $year, int $month): bool
    {
        $row = $this->row($year, $month);
        $closedAt = $this->closedAt($year, $month);

        // Закрытый период виден, если явная запись видимости не новее
        // момента закрытия (иначе — админ осознанно скрыл уже закрытый).
        if ($closedAt !== null) {
            $hiddenAfterClose = $row !== null
                && ! (bool) $row->is_visible
                && $row->changed_at !== null
                && strtotime((string) $row->changed_at) > strtotime((string) $closedAt);
            if (! $hiddenAfterClose) {
                return true;
            }
            return false;
        }

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
