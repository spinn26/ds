<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix-up для InSmart umbrella-карточки.
 *
 * Старая миграция 2026_05_13_000010_seed_insmart_product создала строку
 * без priority (тогда колонки ещё не было) и без productType. После
 * добавления priority в 2026_05_22_000010_add_priority_to_product
 * ProductController стал фильтровать партнёрскую витрину по
 * `priority IS NOT NULL` — карточка InSmart перестала отображаться.
 *
 * Эта миграция «дотягивает» существующую InSmart-строку до полностью
 * рабочего состояния. Идемпотентно — обновляет ТОЛЬКО те поля, что
 * сейчас невалидны (NULL/false), чужие правки админа (например,
 * выставленный приоритет 3) не перетирает.
 */
return new class extends Migration {
    public function up(): void
    {
        $row = DB::table('product')
            ->where('name', 'InSmart')
            ->where('openProductUrl', '/insmart-widget')
            ->first();

        if (! $row) {
            return; // Карточки нет — создаст её предыдущая миграция (либо при следующем seed'е).
        }

        $update = [];
        if (empty($row->productType)) {
            // productType=12 — «Страховые продукты», совпадает с подпродуктами Inssmart.
            $update['productType'] = 12;
        }
        if (! $row->active) {
            $update['active'] = true;
        }
        if (property_exists($row, 'visibleToResident') && ! $row->visibleToResident) {
            $update['visibleToResident'] = true;
        }
        if (Schema::hasColumn('product', 'priority') && $row->priority === null) {
            // priority=1 → первая «полоса» каталога, как у Альфа/Зетта/БКС.
            $update['priority'] = 1;
        }
        if (empty($row->publish_status) || $row->publish_status !== 'published') {
            $update['publish_status'] = 'published';
        }

        if (! empty($update)) {
            DB::table('product')->where('id', $row->id)->update($update);
        }
    }

    public function down(): void
    {
        // Откат не нужен: эта миграция исправляет отсутствие/неверные
        // значения, обратное действие = снова сломать карточку.
    }
};
