<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LMS этап 1: рекурсивная структура курсов + конструктор-блоки в уроках.
 *
 * Per ТЗ Жосан (Bitrix24 «Блок обучение.docx» от 25.05.2026):
 *   Курс → Модуль → Подмодуль → Урок (4 уровня вложенности)
 *   Уроки = конструктор блоков (текст / видео / аудио / файл / ссылка / ...).
 *
 * Решение: единая рекурсивная сущность education_courses с parent_id
 * (как у GetCourse). is_container=true помечает модуль/подмодуль
 * (контейнер без своих прямых уроков, только дочерние курсы).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('education_courses', function (Blueprint $table) {
            if (! Schema::hasColumn('education_courses', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->foreign('parent_id')->references('id')->on('education_courses')->nullOnDelete();
            }
            if (! Schema::hasColumn('education_courses', 'sort_order')) {
                $table->integer('sort_order')->default(0)->index();
            }
            if (! Schema::hasColumn('education_courses', 'is_container')) {
                $table->boolean('is_container')->default(false);
            }
            if (! Schema::hasColumn('education_courses', 'cover_url')) {
                $table->string('cover_url', 500)->nullable();
            }
            if (! Schema::hasColumn('education_courses', 'slug')) {
                $table->string('slug', 150)->nullable();
                $table->unique('slug', 'education_courses_slug_unique');
            }
        });

        // body — JSONB с массивом блоков урока. Структура одного блока:
        //   { type: 'text'|'video'|'audio'|'image'|'file'|'link'|'inner_link'|
        //           'presentation'|'attachment_group',
        //     value: 'текст / url / id урока', label: 'подпись', order: int,
        //     opts: { ... } }
        // Старые поля content / video_urls / document_urls оставляем для
        // legacy-уроков — рендерер на фронте умеет оба формата.
        Schema::table('education_lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('education_lessons', 'body')) {
                $table->jsonb('body')->nullable();
            }
            if (! Schema::hasColumn('education_lessons', 'sort_order')) {
                $table->integer('sort_order')->default(0)->index();
            }
        });

        // База знаний — самостоятельное дерево (Раздел → Подраздел →
        // Материал). НЕ переиспользуем instructions, потому что у БЗ:
        // — рекурсивная структура (а instructions плоская),
        // — материалы с JSONB-body (как уроки), а не html-blob,
        // — теги и поиск,
        // — без прогресса прохождения (не модуль курса).
        if (! Schema::hasTable('education_kb_sections')) {
            Schema::create('education_kb_sections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->foreign('parent_id')->references('id')->on('education_kb_sections')->nullOnDelete();
                $table->string('title', 200);
                $table->string('slug', 150)->nullable()->index();
                $table->string('icon', 80)->nullable();   // MDI key или emoji
                $table->string('cover_url', 500)->nullable();
                $table->text('description')->nullable();
                $table->integer('sort_order')->default(0);
                $table->softDeletes();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('education_kb_articles')) {
            Schema::create('education_kb_articles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('section_id')->index();
                $table->foreign('section_id')->references('id')->on('education_kb_sections')->cascadeOnDelete();
                $table->string('title', 300);
                $table->string('slug', 200)->nullable()->index();
                $table->text('description')->nullable();
                $table->jsonb('body')->nullable();     // тот же формат, что lesson.body
                $table->jsonb('tags')->nullable();     // массив строк
                $table->integer('sort_order')->default(0);
                $table->boolean('published')->default(true);
                $table->softDeletes();
                $table->timestamps();
            });
        }

        // Свободные теги (для поиска / фильтра в БЗ и курсах).
        if (! Schema::hasTable('education_tags')) {
            Schema::create('education_tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->string('slug', 120)->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('education_tags');
        Schema::dropIfExists('education_kb_articles');
        Schema::dropIfExists('education_kb_sections');

        Schema::table('education_lessons', function (Blueprint $table) {
            foreach (['body', 'sort_order'] as $c) {
                if (Schema::hasColumn('education_lessons', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('education_courses', function (Blueprint $table) {
            foreach (['parent_id', 'sort_order', 'is_container', 'cover_url', 'slug'] as $c) {
                if (Schema::hasColumn('education_courses', $c)) {
                    try { $table->dropForeign(['parent_id']); } catch (\Throwable) {}
                    try { $table->dropUnique('education_courses_slug_unique'); } catch (\Throwable) {}
                    $table->dropColumn($c);
                }
            }
        });
    }
};
