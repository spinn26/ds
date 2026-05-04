<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per spec ✅Бизнес-логика: Расчет вознаграждений §2 (матрица):
 * Co-founder DS — ОП по ГП = 150 000 (а не 100 000 как было ошибочно
 * проставлено миграцией 2026_04_15_000001_add_mandatory_gp_to_status_levels).
 *
 * Затрагивает: расчёт ОП-штрафа (MonthlyPenaltyRunner), расчёт
 * eligibility пула (PoolRunner / DashboardService).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('status_levels')
            ->where('id', 10)
            ->where('mandatoryGP', 100000)
            ->update(['mandatoryGP' => 150000]);
    }

    public function down(): void
    {
        DB::table('status_levels')
            ->where('id', 10)
            ->where('mandatoryGP', 150000)
            ->update(['mandatoryGP' => 100000]);
    }
};
