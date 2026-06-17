<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Бэкфилл: для уже сданных тестов (education_course_completions) помечаем
 * урок(и) is_test курса как просмотренные. Иначе в списке «Уроки курса» тест
 * висит «не изучен» и прогресс не доходит до 100%, хотя тест сдан.
 * Идемпотентно (ON CONFLICT DO NOTHING по UNIQUE(user_id, lesson_id)).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('education_lessons', 'is_test')) {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO education_lesson_views (user_id, lesson_id, viewed_at, created_at, updated_at)
            SELECT comp.user_id, l.id, NOW(), NOW(), NOW()
            FROM education_course_completions comp
            JOIN education_lessons l
              ON l.course_id = comp.course_id AND l.is_test = true
            ON CONFLICT (user_id, lesson_id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        // Данные-бэкфилл: безопасно откатить нельзя (не отличить от реальных
        // просмотров). No-op.
    }
};
