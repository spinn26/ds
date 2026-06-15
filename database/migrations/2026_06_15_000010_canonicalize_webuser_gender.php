<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Канонизация пола в WebUser.
 *
 * Легаси-импорт из Directual записал пол по-русски («Мужской»/«Женский»),
 * тогда как платформа использует «male»/«female». Из-за этого форма
 * редактирования партнёра падала на валидации in:male,female. Приложение
 * уже нормализует пол при сохранении (AdminDataController::normalizeGender),
 * эта миграция разово приводит к канону существующие ~188 строк.
 *
 * Обратимость: исходные значения затронутых строк сохраняются в backup-
 * таблицу, down() восстанавливает их и удаляет backup.
 */
return new class extends Migration
{
    private const BACKUP = 'webuser_gender_backup_20260615';

    public function up(): void
    {
        if (! Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function ($t) {
                $t->integer('webuser_id')->primary();
                $t->string('old_gender')->nullable();
            });
        }

        // Бэкап только тех строк, что будем менять (русские варианты).
        DB::statement(
            'INSERT INTO ' . self::BACKUP . ' (webuser_id, old_gender)
             SELECT id, gender FROM "WebUser"
             WHERE lower(trim(gender)) IN (\'мужской\', \'муж\', \'м\')
                OR lower(trim(gender)) IN (\'женский\', \'жен\', \'ж\')
             ON CONFLICT (webuser_id) DO NOTHING'
        );

        DB::table('WebUser')
            ->whereRaw("lower(trim(gender)) IN ('мужской', 'муж', 'м')")
            ->update(['gender' => 'male']);

        DB::table('WebUser')
            ->whereRaw("lower(trim(gender)) IN ('женский', 'жен', 'ж')")
            ->update(['gender' => 'female']);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::BACKUP)) {
            return;
        }

        // Восстанавливаем исходные значения по бэкапу.
        DB::statement(
            'UPDATE "WebUser" w
                SET gender = b.old_gender
               FROM ' . self::BACKUP . ' b
              WHERE b.webuser_id = w.id'
        );

        Schema::drop(self::BACKUP);
    }
};
