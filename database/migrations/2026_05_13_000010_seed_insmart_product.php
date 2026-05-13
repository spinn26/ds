<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * InSmart — приоритетный продукт партнёра по спеке ✅Инсмарт.md.
 * Открывается во встроенном виджете на странице /insmart-widget,
 * последующая обработка автоматическая через webhook (см. InsmartWebhookController).
 *
 * description короткое — карточка в /products лимитирует две-три строки.
 * openProductUrl = '/insmart-widget' — внутренний роут SPA. Vue Products.vue
 * различает internal/external URL по ведущему слэшу.
 *
 * Идемпотентно: при повторном запуске обновляет существующий продукт
 * (поиск по name ilike 'insmart%'), id не меняет.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('product')->where('name', 'ilike', 'insmart%')->first();

        $payload = [
            'name' => 'InSmart',
            'description' => 'Маркетплейс страховых продуктов: ОСАГО, КАСКО, ипотечное и медицинское страхование. Подбор и оформление в одном виджете.',
            'active' => true,
            'visibleToResident' => true,
            'visibleToCalculator' => false,
            'noComission' => false,
            'publish_status' => 'published',
            'openProductUrl' => '/insmart-widget',
        ];

        if ($existing) {
            DB::table('product')->where('id', $existing->id)->update($payload);
            return;
        }

        $nextId = (int) DB::scalar('SELECT COALESCE(MAX(id),0) + 1 FROM product');
        DB::table('product')->insert(array_merge(['id' => $nextId], $payload));
    }

    public function down(): void
    {
        // Не удаляем — продукт может иметь привязанные контракты из webhook.
        // Снимаем с публикации, чтобы откатить «эффект миграции» без потери данных.
        DB::table('product')->where('name', 'InSmart')->update([
            'publish_status' => 'draft',
            'active' => false,
            'openProductUrl' => null,
        ]);
    }
};
