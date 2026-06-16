<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расширяет шаблоны дизайна каноничными секциями конструктора:
 * типографика (шрифт, базовый размер), скругления (radius-шкала),
 * основные настройки (favicon, заголовок входа). Только добавляет
 * недостающие ключи — пользовательские правки не перезаписываются.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('design_themes')) {
            return;
        }

        $defaults = [
            'faviconUrl' => null,
            'loginTitle' => null,
            'typography' => [
                'fontFamily' => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, system-ui, sans-serif",
                'baseSize' => 14,
            ],
            'radius' => ['sm' => 6, 'md' => 8, 'lg' => 12, 'xl' => 16],
        ];

        foreach (DB::table('design_themes')->get(['id', 'config']) as $row) {
            $config = json_decode($row->config, true) ?: [];
            foreach ($defaults as $key => $val) {
                if (! array_key_exists($key, $config)) {
                    $config[$key] = $val;
                }
            }
            DB::table('design_themes')->where('id', $row->id)
                ->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Необратимо по дизайну (только добавление дефолтов конструктора).
    }
};
