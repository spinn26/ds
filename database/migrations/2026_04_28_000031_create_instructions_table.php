<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * База знаний / инструкций (per spec ✅Инструкции.md).
 * Раздел делится по audience (partner / staff) и категориям, дублирующим
 * пункты главного меню. Контент — markdown + опциональное видео + TOC
 * автогенерится по H2/H3 на лету.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 200)->unique();
            $table->string('title', 300);
            $table->string('category', 100)->index();    // "Менеджер контрактов" и т.д.
            $table->string('audience', 20)->default('both'); // partner | staff | both
            $table->text('body_md')->nullable();
            $table->string('video_url', 500)->nullable();
            $table->string('publish_status', 20)->default('draft'); // draft | published
            $table->integer('order_index')->default(0);
            $table->unsignedInteger('author_id')->nullable();
            $table->timestampsTz();

            $table->index(['audience', 'publish_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructions');
    }
};
