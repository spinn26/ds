<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insmart_type_map', function (Blueprint $table) {
            $table->id();
            $table->integer('insmart_type');
            $table->string('insmart_company', 100);
            $table->integer('product_id');
            $table->integer('program_id')->nullable();
            $table->unique(['insmart_type', 'insmart_company']);
        });

        // Backfill from existing webhook history.
        // body JSON has 'type' (int) and 'company' (alias string).
        DB::statement('
            INSERT INTO insmart_type_map (insmart_type, insmart_company, product_id, program_id)
            SELECT DISTINCT
                (body->>\'type\')::int,
                body->>\'company\',
                product,
                program
            FROM "getInsmartOrderWebHookData"
            WHERE product  IS NOT NULL
              AND program  IS NOT NULL
              AND body     IS NOT NULL
              AND body->>\'type\'    IS NOT NULL
              AND body->>\'company\' IS NOT NULL
            ON CONFLICT (insmart_type, insmart_company) DO NOTHING
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('insmart_type_map');
    }
};
