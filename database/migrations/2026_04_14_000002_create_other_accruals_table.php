<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('other_accruals')) {
            Schema::create('other_accruals', function (Blueprint $table) {
                $table->id();
                $table->integer('consultant');
                $table->string('type')->default('bonus'); // bonus, penalty, compensation
                $table->decimal('amount', 15, 2)->default(0);
                $table->decimal('points', 15, 2)->default(0);
                $table->text('comment')->nullable();
                $table->integer('created_by')->nullable(); // WebUser ID
                $table->timestamp('accrual_date')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('other_accruals');
    }
};
