<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Авто-заморозка всех периодов, в которых уже есть строки в poolLog.
 *
 * После перехода на новую механику расчёта пула («live preview всегда,
 * фиксация одной кнопкой → пишет в poolLog + закрывает period_closures»)
 * все периоды, у которых уже есть данные в poolLog (исторические из CSV
 * Directual или ранее зафиксированные расчёты), должны быть закрыты,
 * иначе UI начнёт показывать пересчитанные на лету суммы вместо реально
 * выплаченных исторических.
 *
 * Идемпотентно: для уже закрытых месяцев — no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $months = DB::select("
            SELECT DISTINCT
                EXTRACT(YEAR  FROM date)::int  AS y,
                EXTRACT(MONTH FROM date)::int  AS m
            FROM \"poolLog\"
            ORDER BY y, m
        ");

        $closed = 0;
        foreach ($months as $row) {
            $exists = DB::table('period_closures')
                ->where('year', $row->y)
                ->where('month', $row->m)
                ->whereNull('reopened_at')
                ->exists();
            if ($exists) continue;

            DB::table('period_closures')->updateOrInsert(
                ['year' => $row->y, 'month' => $row->m],
                [
                    'closed_at'   => now(),
                    'closed_by'   => null,
                    'reopened_at' => null,
                    'reopened_by' => null,
                    'note'        => 'auto-freeze: имеет записи в poolLog',
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ],
            );
            $closed++;
        }

        if (app()->runningInConsole()) {
            echo "  freeze_historical_pool_periods: closed {$closed} period(s)\n";
        }
    }

    public function down(): void
    {
        // Откат: только те, что были закрыты этой миграцией (по note).
        DB::table('period_closures')
            ->where('note', 'auto-freeze: имеет записи в poolLog')
            ->whereNull('reopened_at')
            ->delete();
    }
};
