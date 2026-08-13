<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление person — завершение переноса legacy-контактов Directual.
 *
 * Данные разошлись по карточкам: контакты, ДР, город, телеграм, пол,
 * резидентство и пометки о происхождении лежат в client и consultant;
 * хвост архива (105 записей) роздан карточкам, включая мягко удалённые;
 * 12 записей без карточки заведены клиентами. Осталось только то, что
 * переносу не подлежит: техношум Directual (sessionID, HTTP-заголовки,
 * сырые вебхуки GetCourse), тесты и обрывки ФИО.
 *
 * ⚠ Соответствие «карточка → архивная запись» сохранено в person_legacy_map
 * ПРЕДЫДУЩЕЙ миграцией: после дропа колонок сопоставить их иначе нечем.
 * ⚠ Полный дамп прода снят до начала работ:
 *   /root/backups/newds_before_person_repoint_20260813.dump
 *
 * Три мёртвые таблицы Directual с колонкой person — temp_password_creation,
 * personLastNameChange, getCourseRegistrationWebHookData — намеренно
 * оставлены: они самостоятельный мусор, к person отношения не имеют и код к
 * ним не обращается. Их судьба — отдельное решение.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Колонок может не быть на чистой установке — legacy-схема приходила
        // дампом, а не миграциями.
        if (Schema::hasColumn('client', 'person')) {
            Schema::table('client', fn (Blueprint $table) => $table->dropColumn('person'));
        }

        if (Schema::hasColumn('consultant', 'person')) {
            Schema::table('consultant', fn (Blueprint $table) => $table->dropColumn('person'));
        }

        DB::statement('DROP TABLE IF EXISTS person');
    }

    public function down(): void
    {
        // Таблицу восстанавливают из дампа — воссоздать её содержимое миграцией
        // невозможно. Здесь возвращаем только колонки-указатели, чтобы схема
        // сошлась; значения берутся из person_legacy_map.
        Schema::table('client', function (Blueprint $table) {
            $table->unsignedBigInteger('person')->nullable();
        });

        Schema::table('consultant', function (Blueprint $table) {
            $table->unsignedBigInteger('person')->nullable();
        });

        DB::statement('UPDATE client cl SET person = m.person_id
            FROM person_legacy_map m WHERE m.client_id = cl.id');
        DB::statement('UPDATE consultant c SET person = m.person_id
            FROM person_legacy_map m WHERE m.consultant_id = c.id');
    }
};
