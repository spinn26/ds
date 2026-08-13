<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Карта соответствий «карточка → архивная запись person» перед удалением
 * таблицы и колонок.
 *
 * Данные из person перенесены в карточки, но связка id остаётся единственным
 * способом сопоставить карточку с архивной записью, если по историческим
 * данным вылезет расхождение: в дампе person есть, а вот кто из клиентов ей
 * соответствовал — после дропа колонок узнать будет неоткуда.
 *
 * Таблица маленькая (около 8,7 тыс. строк) и служебная: приложение её не
 * читает, она для ручной сверки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_legacy_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('person_id')->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('consultant_id')->nullable()->index();
            $table->string('person_fio')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::statement(<<<'SQL'
            INSERT INTO person_legacy_map (person_id, client_id, consultant_id, person_fio, created_at)
            SELECT p.id,
                   (SELECT min(cl.id) FROM client cl WHERE cl.person = p.id),
                   (SELECT min(c.id) FROM consultant c WHERE c.person = p.id),
                   nullif(btrim(coalesce(p."lastName",'') || ' ' || coalesce(p."firstName",'') || ' '
                        || coalesce(p.patronymic,'')), ''),
                   now()
            FROM person p
            WHERE EXISTS (SELECT 1 FROM client cl WHERE cl.person = p.id)
               OR EXISTS (SELECT 1 FROM consultant c WHERE c.person = p.id)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('person_legacy_map');
    }
};
