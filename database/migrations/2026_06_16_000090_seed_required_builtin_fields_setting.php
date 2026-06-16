<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Набор обязательных СТАНДАРТНЫХ полей пользователя (ФИО/email/телефон/…).
 * Хранится как служебная (category=internal, скрыта из общей страницы
 * «Настройки») json-настройка system_settings; управляется на странице
 * «Кастомные поля», проверяется при сохранении профиля.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'profile.required_builtin_fields'],
            [
                'value' => '[]', 'type' => 'json', 'category' => 'internal',
                'label' => 'Обязательные стандартные поля', 'sort_order' => 0,
                'updated_at' => now(), 'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('key', 'profile.required_builtin_fields')->delete();
        }
    }
};
