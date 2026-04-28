<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Архив сгенерированных отчётов (per spec ✅Отчеты.md §1.2).
 * Хранит метаданные: тип, период, статус, путь к файлу, автор.
 * Сами CSV/Excel файлы кладутся в storage/app/reports/{id}.csv.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_archive', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 60);
            $table->date('date_from');
            $table->date('date_to');
            $table->jsonb('filters')->nullable();
            $table->string('status', 20)->default('generating');
            $table->string('file_path', 500)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('type', 'report_archive_type_idx');
            $table->index('status', 'report_archive_status_idx');
            $table->index('created_at', 'report_archive_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_archive');
    }
};
