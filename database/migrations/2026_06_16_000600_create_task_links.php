<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Связанные задачи (двунаправленная связь). Аддитивно/обратимо. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('related_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'related_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
    }
};
