<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Переопределения строк интерфейса (i18n). Админ задаёт key→value по локали;
 * SPA на старте мёржит их в vue-i18n поверх бандла.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('translation_overrides')) {
            Schema::create('translation_overrides', function (Blueprint $t) {
                $t->id();
                $t->string('locale', 8)->default('ru');
                $t->string('key', 191);          // dot-path: например auth.login
                $t->text('value')->nullable();
                $t->timestamps();
                $t->unique(['locale', 'key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');
    }
};
