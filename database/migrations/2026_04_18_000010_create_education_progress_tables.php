<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('education_lesson_views')) {
            DB::statement('CREATE TABLE education_lesson_views (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL,
                lesson_id BIGINT NOT NULL,
                viewed_at TIMESTAMP NOT NULL DEFAULT NOW(),
                created_at TIMESTAMP,
                updated_at TIMESTAMP,
                UNIQUE (user_id, lesson_id)
            )');
            DB::statement('CREATE INDEX education_lesson_views_user_idx ON education_lesson_views (user_id)');
            DB::statement('CREATE INDEX education_lesson_views_lesson_idx ON education_lesson_views (lesson_id)');
        }

        if (! Schema::hasTable('education_course_completions')) {
            DB::statement('CREATE TABLE education_course_completions (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL,
                course_id BIGINT NOT NULL,
                score INT NOT NULL DEFAULT 0,
                total INT NOT NULL DEFAULT 0,
                completed_at TIMESTAMP NOT NULL DEFAULT NOW(),
                created_at TIMESTAMP,
                updated_at TIMESTAMP,
                UNIQUE (user_id, course_id)
            )');
            DB::statement('CREATE INDEX education_course_completions_user_idx ON education_course_completions (user_id)');
            DB::statement('CREATE INDEX education_course_completions_course_idx ON education_course_completions (course_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('education_course_completions');
        Schema::dropIfExists('education_lesson_views');
    }
};
