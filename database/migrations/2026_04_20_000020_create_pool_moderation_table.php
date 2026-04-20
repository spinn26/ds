<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-month operator override of pool participation.
 *
 * The «Участвует» toggle on the pool screen (spec ✅Пул.md §1.2).
 * Default is «участвует = true»; operator un-checks failing partners
 * before running the calc. Keyed by (year, month, consultant) so the
 * same partner can be excluded for one month and included for the next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pool_moderation', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year');
            $table->tinyInteger('month'); // 1..12
            $table->integer('consultant'); // legacy consultant.id
            $table->boolean('participates')->default(true);
            $table->text('reason')->nullable();
            $table->integer('toggled_by')->nullable(); // WebUser.id
            $table->timestamp('toggled_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month', 'consultant']);
            $table->index(['year', 'month', 'participates']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pool_moderation');
    }
};
