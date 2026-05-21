<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Урок теперь может содержать несколько видео и несколько ссылок-документов
 * одновременно (а также произвольный текст). Заводим JSONB-массивы
 * video_urls / document_urls; legacy-колонки video_url / document_url
 * оставлены для бакауорд-совместимости — в них продолжает класться первый
 * элемент массива.
 *
 * Существующие данные мигрируем: ненулевой video_url/document_url
 * упаковываем в массив из одного элемента.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('education_lessons', 'video_urls')) {
            Schema::table('education_lessons', function (Blueprint $table) {
                $table->jsonb('video_urls')->nullable();
            });
        }
        if (! Schema::hasColumn('education_lessons', 'document_urls')) {
            Schema::table('education_lessons', function (Blueprint $table) {
                $table->jsonb('document_urls')->nullable();
            });
        }

        // Бэкфилл: уже залитые single-URL’ы → массив из одного элемента.
        // Только для строк, где новые поля ещё пустые.
        DB::statement(<<<SQL
            UPDATE education_lessons
            SET video_urls = jsonb_build_array(video_url)
            WHERE video_url IS NOT NULL AND video_url <> ''
              AND video_urls IS NULL
        SQL);
        DB::statement(<<<SQL
            UPDATE education_lessons
            SET document_urls = jsonb_build_array(document_url)
            WHERE document_url IS NOT NULL AND document_url <> ''
              AND document_urls IS NULL
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('education_lessons', 'video_urls')) {
            Schema::table('education_lessons', function (Blueprint $table) {
                $table->dropColumn('video_urls');
            });
        }
        if (Schema::hasColumn('education_lessons', 'document_urls')) {
            Schema::table('education_lessons', function (Blueprint $table) {
                $table->dropColumn('document_urls');
            });
        }
    }
};
