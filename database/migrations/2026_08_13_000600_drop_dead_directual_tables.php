<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Удаление мёртвых таблиц Directual, оставшихся от person.
 *
 * Обе держали колонку person и не читаются ни одной строкой кода:
 *   • temp_password_creation — временные записи сброса пароля Directual (2733);
 *   • personLastNameChange — лог смены фамилии (7).
 *
 * ⚠ Третья такая таблица, getCourseRegistrationWebHookData (сырые тела
 * вебхуков GetCourse, 5127 строк), ОСТАВЛЕНА: на неё ссылаются внешние ключи
 * из "WebUser" и getCourseLog. Ради 4 МБ рвать связи живой таблицы личности
 * не стоит — это отдельное решение с проверкой обеих ссылок.
 *
 * Данные сохранены в дампе, снятом до начала работ:
 * /root/backups/newds_before_person_repoint_20260813.dump
 *
 * Архивные схемы-снимки (directual_full, directual_stg, june_overlay,
 * fix_18160_20260806) НЕ трогаем: это осознанные копии для сверки истории,
 * они весят около 1,1 ГБ и удаляются отдельным решением.
 */
return new class extends Migration
{
    private const TABLES = [
        'temp_password_creation',
        'personLastNameChange',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement('DROP TABLE IF EXISTS "'.$table.'"');
        }
    }

    public function down(): void
    {
        // Восстанавливаются только из дампа — структура и содержимое пришли из
        // Directual, миграциями они никогда не создавались.
    }
};
