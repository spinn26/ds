<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formalizes education_courses / _lessons / _tests schema as a migration.
 * These tables used to be lazy-created from AdminEducationController::
 * ensureTablesExist(). Migration is idempotent (skips on prod where
 * the tables already exist) so it works on both fresh and live DBs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('education_courses')) {
            DB::statement('CREATE TABLE education_courses (
                id BIGSERIAL PRIMARY KEY,
                title TEXT NOT NULL,
                description TEXT,
                product_id BIGINT,
                active BOOLEAN DEFAULT true,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
            DB::statement('CREATE INDEX education_courses_active_idx ON education_courses (active)');
            DB::statement('CREATE INDEX education_courses_product_idx ON education_courses (product_id) WHERE product_id IS NOT NULL');
        }

        if (! Schema::hasTable('education_lessons')) {
            DB::statement('CREATE TABLE education_lessons (
                id BIGSERIAL PRIMARY KEY,
                course_id BIGINT NOT NULL,
                title TEXT NOT NULL,
                content TEXT,
                content_type TEXT DEFAULT \'text\',
                video_url TEXT,
                document_url TEXT,
                sort_order INT DEFAULT 0,
                active BOOLEAN DEFAULT true,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
            DB::statement('CREATE INDEX education_lessons_course_idx ON education_lessons (course_id)');
        }

        if (! Schema::hasTable('education_tests')) {
            DB::statement('CREATE TABLE education_tests (
                id BIGSERIAL PRIMARY KEY,
                course_id BIGINT NOT NULL,
                question TEXT NOT NULL,
                answers JSONB NOT NULL DEFAULT \'[]\',
                correct_answer INT NOT NULL DEFAULT 0,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
            DB::statement('CREATE INDEX education_tests_course_idx ON education_tests (course_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('education_tests');
        Schema::dropIfExists('education_lessons');
        Schema::dropIfExists('education_courses');
    }
};
