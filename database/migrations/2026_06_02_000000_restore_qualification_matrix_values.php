<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Восстанавливает каноническую матрицу квалификаций в status_levels.
 *
 * Часть значений (groupVolumeCumulative у ФК/Мастер ФК и otrif у уровней
 * 6–10) была откачена к старой бизнес-модели при destructive Directual
 * replace 2026-06-01 (db:directual-replace перезалил status_levels из CSV
 * со старыми числами). В результате:
 *   - НГП ФК = 50 000 (надо 30 000), Мастер ФК = 200 000 (надо 150 000);
 *   - otrif = 4800/6600/9000/24000/60000 (абсолютные баллы старой БМ)
 *     вместо процента 70 — Дашборд показывал «4800%», а расчёт отрыва
 *     (MonthlyFinalisationRunner §5.1, DashboardService breakaway tiers
 *     70/90) получал неверный порог.
 *
 * up() переустанавливает каноническую матрицу (источник — таблица условий
 * квалификации новой БМ). Не трогаем title/personalVolume/dsShare.
 */
return new class extends Migration
{
    /** id => [percent, groupVolumeCumulative (НГП), mandatoryGP (ОП/мес), otrif (%), pool (%)] */
    private const CANON = [
        1  => [15, 0,       0,      0,  0],
        2  => [20, 2000,    0,      0,  0],
        3  => [25, 7000,    300,    0,  0],
        4  => [30, 30000,   3000,   0,  0],
        5  => [35, 150000,  8000,   0,  0],
        6  => [40, 350000,  12000,  70, 1],
        7  => [45, 600000,  20000,  70, 1],
        8  => [49, 1000000, 45000,  70, 1],
        9  => [52, 2000000, 75000,  70, 1],
        10 => [55, 4000000, 150000, 70, 1],
    ];

    /** Значения, к которым Directual-replace откатил таблицу (для отката миграции). */
    private const CLOBBERED = [
        1  => [15, 0,       0,      0,     0],
        2  => [20, 2000,    0,      0,     0],
        3  => [25, 7000,    300,    0,     0],
        4  => [30, 50000,   3000,   0,     0],
        5  => [35, 200000,  8000,   0,     0],
        6  => [40, 350000,  12000,  4800,  1],
        7  => [45, 600000,  20000,  6600,  1],
        8  => [49, 1000000, 45000,  9000,  1],
        9  => [52, 2000000, 75000,  24000, 1],
        10 => [55, 4000000, 150000, 60000, 1],
    ];

    public function up(): void
    {
        $this->apply(self::CANON);
    }

    public function down(): void
    {
        $this->apply(self::CLOBBERED);
    }

    private function apply(array $matrix): void
    {
        foreach ($matrix as $id => [$percent, $ngp, $mandatoryGP, $otrif, $pool]) {
            DB::table('status_levels')->where('id', $id)->update([
                'percent' => $percent,
                'groupVolumeCumulative' => $ngp,
                'mandatoryGP' => $mandatoryGP,
                'otrif' => $otrif,
                'pool' => $pool,
            ]);
        }
    }
};
