<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per spec ✅Обучение §3 — структура каталога по 9 смысловым блокам.
 * Block 0 = «База знаний» (без основного блока).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('education_courses') && ! Schema::hasColumn('education_courses', 'block')) {
            DB::statement('ALTER TABLE education_courses ADD COLUMN block INT DEFAULT 0');
            DB::statement('CREATE INDEX education_courses_block_idx ON education_courses (block)');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('education_courses', 'block')) {
            DB::statement('DROP INDEX IF EXISTS education_courses_block_idx');
            DB::statement('ALTER TABLE education_courses DROP COLUMN block');
        }
    }
};
