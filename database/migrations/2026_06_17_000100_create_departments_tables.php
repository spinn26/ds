<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оргструктура компании (Bitrix «Структура компании»):
 *  - departments        — отделы с иерархией (parent_id), руководитель/заместитель;
 *  - department_members — сотрудники отдела (M:N, совместительство).
 * Ссылки на людей — WebUser.id (без FK, как в остальных служебных таблицах).
 * Аддитивно/обратимо.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedBigInteger('head_id')->nullable();      // руководитель
            $table->unsignedBigInteger('deputy_id')->nullable();    // заместитель
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('department_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamps();
            $table->unique(['department_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_members');
        Schema::dropIfExists('departments');
    }
};
