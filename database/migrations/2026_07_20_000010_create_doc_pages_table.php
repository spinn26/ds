<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Редактируемые из админки документы (markdown) — напр. инструкция партнёра.
 * Отдельная таблица, а НЕ system_settings: контент крупный (десятки КБ), и он
 * не должен попадать в кэш-карту бизнес-настроек (SystemSetting::map читается
 * на многих запросах). Slug уникален; контент рендерится/правится админом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_pages');
    }
};
