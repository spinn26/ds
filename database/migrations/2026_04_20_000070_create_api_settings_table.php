<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Хранилище ключей внешних интеграций (Google Sheets, Telegram bot, прочие).
 *
 * Значения шифруются на уровне модели (Laravel `encrypted` cast), в БД
 * лежит нечитаемый cipher-блоб. При чтении расшифровывается APP_KEY-ом.
 *
 * ВАЖНО: при ротации APP_KEY старые записи потеряют значения. Пустая
 * ротация без миграции ключей = потеря.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();     // 'google.sheets.api_key', ...
            $t->string('group', 40)->default('general');  // 'google' | 'telegram' | ...
            $t->text('value')->nullable();       // зашифровано
            $t->string('label', 200);
            $t->text('hint')->nullable();
            $t->boolean('secret')->default(true); // маскировать в UI
            $t->foreignId('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_settings');
    }
};
