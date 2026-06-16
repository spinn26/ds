<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Сегменты пользователей = именованные сохранённые фильтры партнёров.
 * criteria — JSON параметров фильтра (search/activity/status/...), которые
 * подставляются в фильтр-бар на странице «Партнёры».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_segments')) {
            Schema::create('user_segments', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->jsonb('criteria');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_segments');
    }
};
