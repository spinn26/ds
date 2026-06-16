<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Системные объявления/баннеры. Админ публикует уведомление пользователям:
 * тип (info/warning/success/error), аудитория по ролям, период показа,
 * закрываемость. Показывается баннером в шапке SPA.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('announcements')) {
            Schema::create('announcements', function (Blueprint $t) {
                $t->id();
                $t->string('title');
                $t->text('body')->nullable();
                $t->string('type', 20)->default('info'); // info|warning|success|error
                $t->jsonb('roles')->nullable();          // null/пусто = всем
                $t->boolean('active')->default(true);
                $t->boolean('dismissible')->default(true);
                $t->timestamp('starts_at')->nullable();
                $t->timestamp('ends_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
