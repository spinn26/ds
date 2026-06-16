<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Шаблоны дизайна (раздел админки «Дизайн», как в CMS).
 *
 * Каждая строка — именованный шаблон с JSON-конфигом: логотип, палитры
 * light/dark, кастомный CSS. Один шаблон активен (is_active) — его SPA
 * применяет в рантайме (GET /design/active): мутирует цвета темы Vuetify,
 * инжектит CSS, подставляет логотип. Импорт/экспорт = JSON конфига.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('design_themes')) {
            Schema::create('design_themes', function ($t) {
                $t->id();
                $t->string('name');
                $t->boolean('is_active')->default(false);
                $t->jsonb('config');
                $t->timestamps();
            });
            DB::statement('CREATE UNIQUE INDEX design_themes_one_active ON design_themes (is_active) WHERE is_active = true');
        }

        // Сид: «DS по умолчанию» — текущая палитра/логотип (из app.js).
        if (DB::table('design_themes')->count() === 0) {
            $config = [
                'brandName' => 'DS ПЛАТФОРМА',
                'logoText'  => 'DS',
                'logoUrl'   => null,
                'colors' => [
                    'light' => [
                        'primary' => '#2E7D32', 'secondary' => '#00796B', 'tertiary' => '#4361A8',
                        'success' => '#2E7D32', 'warning' => '#ED6C02', 'error' => '#C62828', 'info' => '#0277BD',
                        'background' => '#F8F9F8', 'surface' => '#FFFFFF', 'brand' => '#6EE87A',
                    ],
                    'dark' => [
                        'primary' => '#6EE87A', 'secondary' => '#A4E0AC', 'tertiary' => '#B3C5FF',
                        'success' => '#A4E0AC', 'warning' => '#FFB77A', 'error' => '#FFB4AB', 'info' => '#93CCFF',
                        'background' => '#0F1311', 'surface' => '#161A17', 'brand' => '#6EE87A',
                    ],
                ],
                'customCss' => '',
            ];
            DB::table('design_themes')->insert([
                'name' => 'DS по умолчанию',
                'is_active' => true,
                'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_themes');
    }
};
