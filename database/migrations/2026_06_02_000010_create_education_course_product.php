<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Many-to-many link between education_courses and legacy `product`.
 *
 * Historically a course could reference a single product via
 * education_courses.product_id (1:1). The course constructor now needs to
 * bind several products to one course, so this pivot is added additively:
 *   - education_courses.product_id is kept (still set to the "primary"
 *     product and used by the product-side binding / partner storefront),
 *   - the pivot is the full set of products shown in the course form.
 *
 * Idempotent (skips when table exists) so it is safe on prod where the
 * education_* tables are lazy-created by AdminEducationController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('education_course_product')) {
            DB::statement('CREATE TABLE education_course_product (
                id BIGSERIAL PRIMARY KEY,
                course_id BIGINT NOT NULL REFERENCES education_courses(id) ON DELETE CASCADE,
                product_id BIGINT NOT NULL,
                created_at TIMESTAMP,
                UNIQUE (course_id, product_id)
            )');
            DB::statement('CREATE INDEX education_course_product_product_idx ON education_course_product (product_id)');
        }

        // Backfill from the existing 1:1 product_id so current links survive.
        DB::statement('INSERT INTO education_course_product (course_id, product_id, created_at)
            SELECT id, product_id, now() FROM education_courses
            WHERE product_id IS NOT NULL
            ON CONFLICT (course_id, product_id) DO NOTHING');
    }

    public function down(): void
    {
        Schema::dropIfExists('education_course_product');
    }
};
