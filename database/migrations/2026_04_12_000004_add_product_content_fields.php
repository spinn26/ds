<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = ['imageUrl', 'educationUrl', 'instructionUrl', 'openProductUrl'];

        foreach ($columns as $col) {
            if (! Schema::hasColumn('product', $col)) {
                Schema::table('product', function (Blueprint $table) use ($col) {
                    $table->text($col)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('product', function (Blueprint $table) {
            foreach (['imageUrl', 'educationUrl', 'instructionUrl', 'openProductUrl'] as $col) {
                if (Schema::hasColumn('product', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
