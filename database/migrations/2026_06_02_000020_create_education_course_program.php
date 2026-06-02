<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Many-to-many link between education_courses and `programs_catalog`.
 *
 * Mirrors education_course_product but at the program granularity: a course
 * bound to a product can additionally be narrowed to specific programs of
 * that product. Semantics (read-side / future gate):
 *   - product bound, NO program rows here  → course opens for ALL programs
 *     of that product,
 *   - specific program rows here           → course opens ONLY for them.
 *
 * program_id references programs_catalog(id). We intentionally keep it as a
 * plain BIGINT without a hard FK: programs_catalog is recreated wholesale by
 * the catalog split/import migrations, and a hard FK would break those — same
 * soft approach the product pivot uses for its legacy product_id.
 *
 * Idempotent (skips when table exists) so it is safe on prod where the
 * education_* tables are also lazy-created by AdminEducationController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('education_course_program')) {
            DB::statement('CREATE TABLE education_course_program (
                id BIGSERIAL PRIMARY KEY,
                course_id BIGINT NOT NULL REFERENCES education_courses(id) ON DELETE CASCADE,
                program_id BIGINT NOT NULL,
                created_at TIMESTAMP,
                UNIQUE (course_id, program_id)
            )');
            DB::statement('CREATE INDEX education_course_program_program_idx ON education_course_program (program_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('education_course_program');
    }
};
