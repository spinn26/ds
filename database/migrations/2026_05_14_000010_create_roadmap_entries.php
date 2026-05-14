<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Публичный роадмап продукта — простой changelog/timeline.
 * Публичная страница /roadmap читает только published=true,
 * управление через админку (роль admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_entries', function (Blueprint $t) {
            $t->id();
            $t->string('title', 200);
            $t->text('description')->nullable();
            // planned | in_progress | shipped
            $t->string('status', 20)->default('planned');
            // free-form bucket: "Платформа", "Мобильное приложение", "Интеграции" и т.п.
            $t->string('category', 60)->nullable();
            // MDI icon (mdi-rocket, mdi-flash, ...) — рендерится на публике как SVG-аналог.
            $t->string('icon', 60)->nullable();
            $t->timestamp('released_at')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('published')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['published', 'status']);
            $t->index('released_at');
            $t->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_entries');
    }
};
