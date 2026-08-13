<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Заморозка person: снимаем последнюю жёсткую связь с ней.
 *
 * Данные перенесены в карточки клиента и партнёра, приложение таблицу не
 * читает и не пишет. Остаётся внешний ключ client.person → person(id): он
 * держит схему за legacy-таблицу и мешает её архивировать.
 *
 * Колонку client.person НЕ удаляем — она остаётся следом происхождения записи
 * и единственным способом сверить архив, если по историческим данным вылезет
 * расхождение. Сама таблица тоже остаётся: место она занимает копеечное, а
 * снести её всегда успеем.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE client DROP CONSTRAINT IF EXISTS client_person_fkey');
    }

    public function down(): void
    {
        // NOT VALID — как было: часть указателей ведёт на отсутствующие записи
        // после переномерации person при консолидации Directual, и проверка
        // существующих строк не прошла бы.
        DB::statement('ALTER TABLE client ADD CONSTRAINT client_person_fkey
            FOREIGN KEY (person) REFERENCES person(id) NOT VALID');
    }
};
