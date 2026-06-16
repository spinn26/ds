<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кастомные пункты меню (Конструктор меню).
 *
 * Дополняют хардкод-навигацию, НЕ заменяют её: layouts мёржат активные
 * пункты поверх статического меню. Пустой набор = поведение как раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            // Область: admin (/admin/*), staff (/manage/*), partner (кабинет)
            $table->string('area', 16)->default('admin');
            // Заголовок верхнеуровневой группы, в которую подставить пункт
            // (null = самостоятельный пункт верхнего уровня).
            $table->string('group_title')->nullable();
            $table->string('title');
            $table->string('icon')->nullable();
            $table->string('to')->default('');
            $table->boolean('external')->default(false);
            // Роли-получатели (jsonb-массив); null = всем ролям области.
            $table->jsonb('roles')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['area', 'active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
