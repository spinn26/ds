<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Дополняет палитры существующих design_themes недостающими токенами
 * (on-surface, surface-variant, outline и т.д.), чтобы редактор «Дизайн»
 * показывал их текущие значения. Только ДОБАВЛЯЕТ отсутствующие ключи —
 * пользовательские правки не перезаписываются.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('design_themes')) {
            return;
        }

        $defaults = [
            'light' => [
                'on-surface' => '#1A1F1B', 'on-surface-variant' => '#4A524C',
                'surface-variant' => '#E9EBE9', 'outline' => '#BDC4BE',
                'outline-variant' => '#DDE2DE', 'brand-ink' => '#0A2B10',
            ],
            'dark' => [
                'on-surface' => '#E2E4E2', 'on-surface-variant' => '#C2C8C3',
                'surface-variant' => '#24292A', 'outline' => '#3D4540',
                'outline-variant' => '#2A312C', 'brand-ink' => '#0A2B10',
            ],
        ];

        foreach (DB::table('design_themes')->get(['id', 'config']) as $row) {
            $config = json_decode($row->config, true) ?: [];
            $config['colors'] = $config['colors'] ?? ['light' => [], 'dark' => []];
            foreach (['light', 'dark'] as $theme) {
                $config['colors'][$theme] = $config['colors'][$theme] ?? [];
                foreach ($defaults[$theme] as $key => $val) {
                    if (! array_key_exists($key, $config['colors'][$theme])) {
                        $config['colors'][$theme][$key] = $val;
                    }
                }
            }
            DB::table('design_themes')->where('id', $row->id)
                ->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Необратимо по дизайну (только добавление дефолтных токенов).
    }
};
