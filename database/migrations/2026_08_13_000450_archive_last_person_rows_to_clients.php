<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Последние записи архива person, у которых карточки нет вовсе, — в клиентов.
 *
 * Хвост архива роздан карточкам командой person:adopt-archive, но 51 запись
 * не нашла даже удалённой карточки. Больше половины — мусор импорта («123
 * 123», «n8n», фамилия «-») и тесты («Тест Тестович», test@test.ru).
 * Переносим только похожее на живого человека: есть фамилия и имя, есть
 * контакт, запись не удалена, роль не служебная.
 *
 * Карточка заводится БЕЗ наставника — привязать её не к кому, человека в
 * платформе нет; в комментарии остаётся след происхождения.
 *
 * Идемпотентно: повторный запуск не найдёт кандидатов, потому что карточка с
 * таким ФИО уже есть. Делается миграцией, а не командой, чтобы гарантированно
 * отработать ДО удаления таблицы.
 *
 * ⚠ Дубли по ФИО схлопываем (DISTINCT ON): одна и та же Ченцова лежит в
 * архиве дважды.
 * ⚠ Отсекаем тестовые записи, где имя равно фамилии («Аарон Аарон»), и
 * сотрудников — они не клиенты.
 */
return new class extends Migration
{
    private const JUNK = '(тест|test|^ррр|^n8n|^123|^fdg|^dev |пользователь|^-$|^клиент$)';

    public function up(): void
    {
        if (! DB::selectOne("SELECT to_regclass('public.person') AS t")->t) {
            return; // таблицы уже нет — миграция отработала раньше
        }

        $junk = self::JUNK;

        // ⚠ Сиквенс client отстаёт: часть строк исторически вставлялась с явным
        // id (LegacyId), и вставка без выравнивания упирается в duplicate
        // client_pkey — тот же класс аварии, что уронил импорт транзакций
        // 10.08.2026.
        DB::statement("SELECT setval(pg_get_serial_sequence('client','id'),
            GREATEST((SELECT max(id) FROM client), 1))");

        DB::statement(<<<SQL
            INSERT INTO client ("personName", email, phone, "birthDate", city, "nicTG", comment, "dateCreated")
            SELECT DISTINCT ON (btrim(lower(fio)))
                   fio, email, phone, "birthDate", city, "nicTG",
                   'Из архива Directual (person ' || id || ')', now()
            FROM (
                SELECT p.id, p.email, p.phone, p."birthDate", p.city, p."nicTG",
                       btrim(coalesce(p."lastName",'') || ' ' || coalesce(p."firstName",'') || ' '
                            || coalesce(p.patronymic,'')) AS fio
                FROM person p
                WHERE p."dateDeleted" IS NULL
                  AND length(btrim(coalesce(p."lastName",''))) >= 3
                  AND length(btrim(coalesce(p."firstName",''))) >= 2
                  AND (nullif(btrim(coalesce(p.email,'')),'') IS NOT NULL
                    OR length(regexp_replace(coalesce(p.phone,''), '\\D', '', 'g')) >= 10)
                  AND btrim(lower(coalesce(p."lastName",''))) !~ '{$junk}'
                  AND btrim(lower(coalesce(p."firstName",''))) !~ '{$junk}'
                  AND coalesce(p.role,'') !~ '(backoffice|admin|head|finance|support|education|invest)'
                  AND btrim(lower(coalesce(p."lastName",''))) <> btrim(lower(coalesce(p."firstName",'')))
                  AND NOT EXISTS (SELECT 1 FROM client cl
                                  WHERE btrim(lower(cl."personName")) = btrim(lower(coalesce(p."lastName",'') || ' '
                                      || coalesce(p."firstName",'') || ' ' || coalesce(p.patronymic,''))))
                  AND NOT EXISTS (SELECT 1 FROM consultant c
                                  WHERE btrim(lower(c."personName")) = btrim(lower(coalesce(p."lastName",'') || ' '
                                      || coalesce(p."firstName",'') || ' ' || coalesce(p.patronymic,''))))
            ) k
            ORDER BY btrim(lower(fio)), id
            SQL);
    }

    public function down(): void
    {
        // Заведённые карточки помечены происхождением — по нему и убираем.
        DB::table('client')->where('comment', 'like', 'Из архива Directual (person %')->delete();
    }
};
