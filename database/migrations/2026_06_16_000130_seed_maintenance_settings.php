<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Режим обслуживания: настройки в system_settings (категория maintenance,
 * видна в разделе «Настройки»). При enabled=true не-админы получают 503.
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
            ['maintenance.enabled', '0', 'bool', 'maintenance', 'Режим обслуживания', 'Когда включён — платформа недоступна всем, кроме админов (503).', 1],
            ['maintenance.message', 'Идут технические работы. Скоро вернёмся.', 'string', 'maintenance', 'Сообщение режима обслуживания', 'Текст, который видят пользователи во время техработ.', 2],
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
            DB::table('system_settings')->whereIn('key', ['maintenance.enabled', 'maintenance.message'])->delete();
        }
    }
};
