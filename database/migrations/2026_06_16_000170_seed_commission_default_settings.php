<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Финансовая фаза: дефолты комиссии в настройки (category business).
 * Значения по умолчанию = текущим в CommissionCalculator — поведение не
 * меняется до правки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        $now = now();
        $rows = [
            ['commission.default_ds_percent', '100', 'float', 'business', 'Фолбэк %ДС', 'Ставка %ДС, если у программы не задан тариф (фолбэк, 100 = 100%).', 80],
            ['commission.startup_percent', '15', 'float', 'business', 'Стартовый % (без квалификации)', 'Процент группового бонуса для партнёра без квалификации (15 = 15%).', 90],
        ];
        foreach ($rows as $r) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $r[0]],
                ['value' => $r[1], 'type' => $r[2], 'category' => $r[3], 'label' => $r[4], 'description' => $r[5], 'sort_order' => $r[6], 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->whereIn('key', ['commission.default_ds_percent', 'commission.startup_percent'])->delete();
        }
    }
};
