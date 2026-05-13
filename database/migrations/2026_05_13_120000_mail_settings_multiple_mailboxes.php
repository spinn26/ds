<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расширяет mail_settings до множественных ящиков.
 *
 * Раньше: одна строка, MailSettingsService::current() её и брал.
 * Теперь: несколько ящиков (system / маркетинг / поддержка / etc),
 * один помечен is_default=true. Старый код, читающий current(),
 * продолжает работать — current() возвращает default-ящик.
 *
 * Существующая строка (если была) превращается в default с
 * name='Основной'.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            // Название ящика для админа — «Системный», «Маркетинг», и т.п.
            $table->string('name', 120)->nullable()->after('id');
            // Только один ящик может быть default. Контракт держится в
            // MailSettingsService::setDefault() (одной транзакцией сбрасывает
            // старый default и проставляет новый) — без partial unique
            // index, чтобы PostgreSQL/MySQL код был кросс-совместим.
            $table->boolean('is_default')->default(false)->after('from_name');
        });

        // Backfill: существующую строку (если есть) делаем default.
        $existing = DB::table('mail_settings')->first();
        if ($existing) {
            DB::table('mail_settings')->where('id', $existing->id)->update([
                'name' => $existing->name ?? 'Основной',
                'is_default' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->dropColumn(['name', 'is_default']);
        });
    }
};
