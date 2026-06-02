<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switch education_course_product.product_id from legacy `product`.id to
 * `products_catalog`.id.
 *
 * Why: the course-unlock dropdown must offer every product that is active and
 * visible on the storefront (products_catalog). Some catalog umbrellas are
 * catalog-native (no single legacy `product` row — they collapse several legacy
 * variants), so they have legacy_product_id = NULL and could never be bound
 * while the pivot keyed on legacy ids. products_catalog.id covers all of them.
 *
 * Scope is contained: the pivot is read only by the education endpoints
 * (AdminEducationController / EducationTreeService / EducationController). The
 * storefront and the product-side reverse binding use the SEPARATE scalar
 * education_courses.product_id, which stays a legacy id — so the storefront is
 * untouched.
 *
 * Order matters (and makes this safe to run once): delete orphans BEFORE the
 * update. After the update the rows hold catalog ids, which would themselves
 * look like "orphans" to the legacy-anchor check.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('education_course_product') || ! Schema::hasTable('products_catalog')) {
            return;
        }

        // 1. Drop links to legacy products that have no catalog umbrella
        //    (deactivated / deleted / not migrated). They are not storefront
        //    products; the operator re-binds those courses to the right catalog
        //    product in the editor (all 22 are now available).
        DB::statement('DELETE FROM education_course_product ecp
            WHERE NOT EXISTS (
                SELECT 1 FROM products_catalog pc WHERE pc.legacy_product_id = ecp.product_id
            )');

        // 2. Remap the rest legacy id -> catalog id.
        DB::statement('UPDATE education_course_product ecp
            SET product_id = pc.id
            FROM products_catalog pc
            WHERE pc.legacy_product_id = ecp.product_id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('education_course_product') || ! Schema::hasTable('products_catalog')) {
            return;
        }

        // Best-effort reverse: catalog id -> its legacy anchor. Rows that point
        // at catalog-native products (legacy_product_id IS NULL) cannot be mapped
        // back and are left as-is; deleted orphan links cannot be restored.
        DB::statement('UPDATE education_course_product ecp
            SET product_id = pc.legacy_product_id
            FROM products_catalog pc
            WHERE pc.id = ecp.product_id AND pc.legacy_product_id IS NOT NULL');
    }
};
