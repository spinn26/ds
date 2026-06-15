<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Исправляет запись Казахстанского тенге (id=38):
 * - symbol: "лв" → "₸" (был ошибочный болгарский символ)
 * - selectable: false → true (нужен для управленческого справочника)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('currency')->where('id', 38)->update([
            'symbol'     => '₸',
            'selectable' => true,
        ]);
    }

    public function down(): void
    {
        DB::table('currency')->where('id', 38)->update([
            'symbol'     => 'лв',
            'selectable' => false,
        ]);
    }
};
