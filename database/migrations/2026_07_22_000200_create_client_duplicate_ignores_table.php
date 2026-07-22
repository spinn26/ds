<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Решения «это не дубли» на странице дублей клиентов.
 *
 * Ключ группы — отсортированные id клиентов через дефис (например «621-2556»),
 * поэтому однофамильцы, помеченные оператором, больше не показываются. Если в
 * группе появится ТРЕТИЙ клиент, ключ станет другим и группа всплывёт заново —
 * это намеренно: новый однофамилец должен быть пересмотрен.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_duplicate_ignores', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 255)->unique();
            $table->text('client_ids');           // тот же список, для отчётности
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_duplicate_ignores');
    }
};
