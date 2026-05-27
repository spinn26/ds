<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Перед загрузкой нового списка продуктов:
 *   - снимаем слепок текущих активных продуктов (кроме InSmart, id=94) в
 *     отдельную таблицу `legacy_products` — там же будет жить старый
 *     каталог для «старой платформы»;
 *   - в основной `product` помечаем эти 28 строк active=false,
 *     visibleToCalculator=false, publish_status='draft'. Это soft-delete —
 *     контракты по `program.product` остаются валидны, история не теряется.
 *
 * InSmart (id=94) и Insmart umbrella оставляем активными в основной таблице.
 */
return new class extends Migration
{
    private const KEEP_ACTIVE_IDS = [94]; // InSmart

    public function up(): void
    {
        // 1. Структура архива — копируем минимально-необходимые поля,
        //    без админских (publish_status, hero_image, и т.д.). Сохраняем
        //    исходный id, чтобы при ручной сверке с контрактами можно было
        //    идти 1:1 по `legacy_products.id ↔ product.id`.
        if (! Schema::hasTable('legacy_products')) {
            Schema::create('legacy_products', function (Blueprint $t) {
                $t->integer('id')->primary();
                $t->string('name')->nullable();
                $t->string('typeName')->nullable();
                $t->integer('productType')->nullable();
                $t->boolean('visibleToCalculator')->nullable();
                $t->boolean('visibleToResident')->nullable();
                $t->boolean('has_property')->default(false);
                $t->boolean('has_term')->default(false);
                $t->boolean('has_year_kv')->default(false);
                $t->text('description')->nullable();
                $t->text('imageUrl')->nullable();
                $t->text('educationUrl')->nullable();
                $t->text('instructionUrl')->nullable();
                $t->smallInteger('priority')->nullable();
                $t->timestamp('archived_at')->useCurrent();
            });
        }

        DB::transaction(function () {
            $keepIds = self::KEEP_ACTIVE_IDS;
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));

            // 2. Копируем все активные продукты, кроме InSmart, в архив.
            //    ON CONFLICT DO NOTHING — если миграцию перезапустят, не
            //    дублирует и не перетирает уже снятый слепок.
            DB::insert("
                INSERT INTO legacy_products
                    (id, name, \"typeName\", \"productType\", \"visibleToCalculator\", \"visibleToResident\",
                     has_property, has_term, has_year_kv, description,
                     \"imageUrl\", \"educationUrl\", \"instructionUrl\", priority, archived_at)
                SELECT id, name, \"typeName\", \"productType\", \"visibleToCalculator\", \"visibleToResident\",
                       has_property, has_term, has_year_kv, description,
                       \"imageUrl\", \"educationUrl\", \"instructionUrl\", priority, NOW()
                FROM product
                WHERE active = true
                  AND id NOT IN ($placeholders)
                ON CONFLICT (id) DO NOTHING
            ", $keepIds);

            // 3. Soft-delete этих же 28 продуктов из основного каталога.
            DB::table('product')
                ->where('active', true)
                ->whereNotIn('id', $keepIds)
                ->update([
                    'active' => false,
                    'visibleToCalculator' => false,
                    'publish_status' => 'draft',
                ]);
        });

        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix');
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Откат: re-activate те продукты, чьи id лежат в legacy_products.
            if (Schema::hasTable('legacy_products')) {
                DB::statement('
                    UPDATE product p
                    SET active = true,
                        "visibleToCalculator" = lp."visibleToCalculator",
                        publish_status = \'published\'
                    FROM legacy_products lp
                    WHERE lp.id = p.id
                ');

                Schema::drop('legacy_products');
            }
        });

        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix');
    }
};
