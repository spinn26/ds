<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Полная кастомизация: сид расширенных --ds-* токенов в шаблоны дизайна
 * (отступы, скругления-доп, высоты контролов, анимации, тени-уровни).
 * Значения — полные CSS-строки (с единицами), из ds-tokens.css. Только
 * добавляет недостающие ключи.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('design_themes')) {
            return;
        }

        $tokens = [
            // отступы
            'space-1' => '4px', 'space-2' => '8px', 'space-3' => '12px', 'space-4' => '16px',
            'space-5' => '20px', 'space-6' => '24px', 'space-7' => '32px', 'space-8' => '40px',
            // скругления доп
            'radius-xs' => '4px', 'radius-2xl' => '24px', 'radius-pill' => '999px',
            // высоты контролов
            'h-control' => '40px', 'h-control-sm' => '32px', 'h-control-lg' => '48px',
            'h-row' => '48px', 'h-row-compact' => '40px',
            // анимации
            'dur-fast' => '120ms', 'dur-medium' => '200ms', 'dur-slow' => '320ms',
            'ease-standard' => 'cubic-bezier(0.2, 0, 0, 1)',
            // тени по уровням
            'shadow-1' => '0 1px 2px rgba(15,30,15,0.04), 0 1px 3px rgba(15,30,15,0.06)',
            'shadow-2' => '0 2px 4px rgba(15,30,15,0.05), 0 4px 8px rgba(15,30,15,0.06)',
            'shadow-3' => '0 4px 8px rgba(15,30,15,0.06), 0 8px 24px rgba(15,30,15,0.08)',
            'shadow-4' => '0 8px 16px rgba(15,30,15,0.08), 0 16px 40px rgba(15,30,15,0.10)',
        ];

        foreach (DB::table('design_themes')->get(['id', 'config']) as $row) {
            $config = json_decode($row->config, true) ?: [];
            $config['tokens'] = $config['tokens'] ?? [];
            foreach ($tokens as $k => $v) {
                if (! array_key_exists($k, $config['tokens'])) {
                    $config['tokens'][$k] = $v;
                }
            }
            DB::table('design_themes')->where('id', $row->id)
                ->update(['config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Необратимо (только добавление дефолтных токенов).
    }
};
