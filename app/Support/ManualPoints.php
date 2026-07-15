<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Ручные баллы из «Прочих начислений» (other_accruals.points).
 *
 * Спека §3: баллы, начисленные через «Прочие», прибавляются к объёму продаж
 * партнёра (ЛП, а значит и ГП/НГП) и учитываются для поддержания статуса.
 *
 * Раньше эти баллы попадали только в денормализованный consultant.personalVolume
 * (который затем затирался recomputeVolumeAndActivate из транзакций) и НЕ входили
 * в отчётный ЛП (он считается из commission). Итог: партнёр, которому баллы
 * начислили ради статуса, всё равно получал −20% ОП и терял пул.
 *
 * Этот helper даёт единый источник «ручных баллов за месяц», который добавляется
 * к ЛП во ВСЕХ местах расчёта: отчёт партнёра, живые квалификации, финализация
 * (ОП/отрыв). Дата берётся из accrual_date.
 */
class ManualPoints
{
    /** Сумма ручных баллов партнёра за месяц 'YYYY-MM'. */
    public static function forMonth(int $consultantId, string $month): float
    {
        return (float) DB::table('other_accruals')
            ->where('consultant', $consultantId)
            ->whereRaw("to_char(accrual_date, 'YYYY-MM') = ?", [$month])
            ->sum('points');
    }

    /**
     * Ручные баллы за месяц для набора партнёров: [consultantId => points].
     * Возвращаются только ненулевые.
     *
     * @param  int[]  $consultantIds
     * @return array<int, float>
     */
    public static function byMonth(array $consultantIds, string $month): array
    {
        if (empty($consultantIds)) {
            return [];
        }

        return DB::table('other_accruals')
            ->whereIn('consultant', $consultantIds)
            ->whereRaw("to_char(accrual_date, 'YYYY-MM') = ?", [$month])
            ->groupBy('consultant')
            ->selectRaw('consultant, SUM(points) AS pts')
            ->pluck('pts', 'consultant')
            ->map(fn ($v) => (float) $v)
            ->filter(fn ($v) => $v != 0.0)
            ->all();
    }
}
