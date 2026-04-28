<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Буферная зона для импорта контрактов (per spec ✅Загрузка контрактов §2-§3).
 * Хранит распарсенные строки из Google Sheets ДО фиксации в `contract`.
 * Каждая строка проходит валидацию и помечается valid|invalid; кнопка
 * «Сохранить» во фронте активна только когда все строки valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_import_preview', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('session_id', 64)->index();
            $table->jsonb('row_data');               // парсенная строка из Sheets
            $table->jsonb('errors')->nullable();     // массив [{field, message}]
            $table->string('status', 20)->default('invalid'); // valid | invalid | edited
            $table->unsignedInteger('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_import_preview');
    }
};
