<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Контент-страницы (правила, FAQ, оферта и т.п.): редактируются админом,
 * показываются пользователю по slug на /page/{slug}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_pages')) {
            Schema::create('content_pages', function (Blueprint $t) {
                $t->id();
                $t->string('slug', 120)->unique();
                $t->string('title');
                $t->text('body')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
