<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Кастомные поля пользователей (CMS-подобно): админ заводит произвольные
 * поля (тип, обязательность, опции для select), пользователь заполняет их
 * в профиле. Значения — per-user в custom_field_values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('custom_fields')) {
            Schema::create('custom_fields', function (Blueprint $t) {
                $t->id();
                $t->string('key', 64)->unique();           // slug-идентификатор
                $t->string('label');
                $t->string('type', 20)->default('text');   // text|textarea|number|date|select|checkbox
                $t->boolean('required')->default(false);
                $t->boolean('active')->default(true);
                $t->jsonb('options')->nullable();          // варианты для select
                $t->text('description')->nullable();
                $t->integer('sort_order')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('custom_field_values')) {
            Schema::create('custom_field_values', function (Blueprint $t) {
                $t->id();
                $t->foreignId('field_id')->constrained('custom_fields')->cascadeOnDelete();
                $t->unsignedBigInteger('user_id');         // WebUser.id
                $t->text('value')->nullable();
                $t->timestamps();
                $t->unique(['field_id', 'user_id']);
                $t->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
