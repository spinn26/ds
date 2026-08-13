<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Уборка мёртвых таблиц вокруг продуктов — первый шаг к одному каталогу.
 *
 * Вокруг продуктов крутится десять таблиц; три из них не читает ни строка кода:
 *   • legacy_catalog (217 строк) — промежуточная выгрузка каталога из таблицы,
 *     осталась от разбора продуктов в мае;
 *   • productMatrix (1 строка) — таблица-заготовка Directual. Метод
 *     CalculatorController::productMatrix() к ней отношения не имеет, он
 *     собирает матрицу из каталога;
 *   • productTags (1 строка) — НЕ трогаем здесь: на неё смотрит внешний ключ
 *     product.productTags, а сам product уходит позже, при слиянии каталогов.
 *     Снимем вместе с ним, чтобы не оставлять висящую колонку.
 *
 * Данные сохранены в общем дампе прода, снятом сегодня.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS legacy_catalog');
        DB::statement('DROP TABLE IF EXISTS "productMatrix"');
    }

    public function down(): void
    {
        // Восстанавливаются только из дампа: обе пришли из Directual,
        // миграциями никогда не создавались.
    }
};
