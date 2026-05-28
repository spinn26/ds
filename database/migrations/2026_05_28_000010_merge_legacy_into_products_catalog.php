<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Делает products_catalog единственным UI-источником продуктов.
 *
 *  1. Добавляет колонки image_url, hero_image, description, legacy_product_id.
 *  2. Бэкфилл (через нормализованный matcher по name):
 *     - заматчивает существующие пары products_catalog ↔ product;
 *     - копирует imageUrl/hero_image/description из legacy в catalog
 *       только если в catalog поле NULL/пустое (не затирает уже введённое);
 *     - переносит категорию из productType→productCategory в catalog.type,
 *       если catalog.type пуст;
 *     - вставляет legacy-only активные продукты (например, InSmart) в catalog
 *       со ссылкой legacy_product_id и заполненными полями.
 *
 * FK таблиц contract/dsCommission/etc. на legacy product.id остаются —
 * мы не сливаем строки, только агрегируем UI на одну сторону.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products_catalog')) {
            return; // на средах без catalog (например, чистая локалка) ничего не делаем
        }

        // ---- Schema ----
        if (! Schema::hasColumn('products_catalog', 'image_url')) {
            DB::statement('ALTER TABLE products_catalog ADD COLUMN image_url VARCHAR(1000) NULL');
        }
        if (! Schema::hasColumn('products_catalog', 'hero_image')) {
            DB::statement('ALTER TABLE products_catalog ADD COLUMN hero_image VARCHAR(1000) NULL');
        }
        if (! Schema::hasColumn('products_catalog', 'description')) {
            DB::statement('ALTER TABLE products_catalog ADD COLUMN description TEXT NULL');
        }
        if (! Schema::hasColumn('products_catalog', 'legacy_product_id')) {
            DB::statement('ALTER TABLE products_catalog ADD COLUMN legacy_product_id INTEGER NULL');
            DB::statement('ALTER TABLE products_catalog
                ADD CONSTRAINT products_catalog_legacy_product_id_fkey
                FOREIGN KEY (legacy_product_id) REFERENCES product(id) DEFERRABLE');
            DB::statement('CREATE INDEX IF NOT EXISTS products_catalog_legacy_product_id_idx
                ON products_catalog (legacy_product_id)');
        }

        // ---- Backfill: link existing matches + copy fields ----
        // Нормализация имени = lowercase + collapse whitespace. На проде
        // даёт 22 пары без коллизий (см. dry-run).
        DB::statement("
            UPDATE products_catalog c
            SET legacy_product_id = l.id,
                image_url   = COALESCE(c.image_url,   l.\"imageUrl\"),
                hero_image  = COALESCE(c.hero_image,  l.hero_image),
                description = COALESCE(c.description, l.description),
                type        = COALESCE(NULLIF(c.type, ''), (
                    SELECT pc.\"productCategoryName\"
                    FROM \"productType\" pt
                    JOIN \"productCategory\" pc ON pc.id = pt.\"productTypeCategory\"
                    WHERE pt.id = l.\"productType\"
                    LIMIT 1
                )),
                updated_at = now()
            FROM product l
            WHERE c.legacy_product_id IS NULL
              AND lower(regexp_replace(c.name, '\\s+', ' ', 'g'))
                = lower(regexp_replace(l.name, '\\s+', ' ', 'g'))
        ");

        // ---- Insert legacy-only active products into catalog ----
        // Чтобы партнёр продолжил видеть карточки (например, InSmart).
        // products_catalog.name UNIQUE — двойной NOT EXISTS защищает от дублей
        // при повторном запуске и от конфликта имён.
        DB::statement("
            INSERT INTO products_catalog
                (name, type, active, image_url, hero_image, description,
                 open_product_url, legacy_product_id, imported_from, created_at, updated_at)
            SELECT
                l.name,
                (SELECT pc.\"productCategoryName\"
                   FROM \"productType\" pt
                   JOIN \"productCategory\" pc ON pc.id = pt.\"productTypeCategory\"
                  WHERE pt.id = l.\"productType\" LIMIT 1),
                l.active,
                l.\"imageUrl\",
                l.hero_image,
                l.description,
                l.\"openProductUrl\",
                l.id,
                'legacy-merge',
                now(), now()
            FROM product l
            WHERE l.active = true
              AND NOT EXISTS (
                SELECT 1 FROM products_catalog c WHERE c.legacy_product_id = l.id
              )
              AND NOT EXISTS (
                SELECT 1 FROM products_catalog c
                 WHERE lower(regexp_replace(c.name, '\\s+', ' ', 'g'))
                     = lower(regexp_replace(l.name, '\\s+', ' ', 'g'))
              )
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('products_catalog')) {
            return;
        }

        // Откатываем вставленные legacy-merge строки (idempotent — отмечены
        // imported_from='legacy-merge').
        DB::table('products_catalog')->where('imported_from', 'legacy-merge')->delete();

        if (Schema::hasColumn('products_catalog', 'legacy_product_id')) {
            DB::statement('ALTER TABLE products_catalog DROP CONSTRAINT IF EXISTS products_catalog_legacy_product_id_fkey');
            DB::statement('DROP INDEX IF EXISTS products_catalog_legacy_product_id_idx');
            DB::statement('ALTER TABLE products_catalog DROP COLUMN legacy_product_id');
        }
        if (Schema::hasColumn('products_catalog', 'description')) {
            DB::statement('ALTER TABLE products_catalog DROP COLUMN description');
        }
        if (Schema::hasColumn('products_catalog', 'hero_image')) {
            DB::statement('ALTER TABLE products_catalog DROP COLUMN hero_image');
        }
        if (Schema::hasColumn('products_catalog', 'image_url')) {
            DB::statement('ALTER TABLE products_catalog DROP COLUMN image_url');
        }
    }
};
