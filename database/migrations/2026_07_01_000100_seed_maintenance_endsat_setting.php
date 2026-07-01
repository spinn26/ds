<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Режим обслуживания: время завершения для обратного отсчёта на странице
 * техработ. ISO-строка (или пусто = без отсчёта). Управляется кнопкой в админке
 * (MaintenanceController), читается middleware EnsureNotInMaintenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        $now = now();
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'maintenance.ends_at'],
            [
                'value' => '',
                'type' => 'string',
                'category' => 'maintenance',
                'label' => 'Ожидаемое завершение техработ',
                'description' => 'ISO-время окончания — для обратного отсчёта. Задаётся кнопкой «Режим обслуживания».',
                'sort_order' => 3,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('key', 'maintenance.ends_at')->delete();
        }
    }
};
