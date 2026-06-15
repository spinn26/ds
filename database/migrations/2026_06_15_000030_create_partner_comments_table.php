<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Комментарии к партнёру — оставляются в всплывающей карточке в разделе Структура.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('consultant_id');
            $table->unsignedInteger('author_id');
            $table->text('body');
            $table->timestamps();

            $table->index('consultant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_comments');
    }
};
