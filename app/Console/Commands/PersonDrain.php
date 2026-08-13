<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Последний слив данных из person: всё, что ещё нигде не продублировано.
 *
 * После переноса контактов и профильных полей в карточки в person осталось
 * ровно три вещи, которые больше нигде не лежат:
 *   1. getCourseUserId — id пользователя в GetCourse; у 538 партнёров он есть
 *      только здесь, в WebUser (канон обучения) пусто;
 *   2. comment — пометки о происхождении записи («создан бэкофисом»,
 *      «Регистрация GetCourse», «загружен по файлу от 15.07»);
 *   3. boughtProRost — признак покупки курса, тоже канон в WebUser.
 *
 * Остальное — техношум Directual (urlData/headers с sessionID и заголовками
 * HTTP, сырые вебхуки GetCourse, пустые password/dateLastActivity) и копии
 * ФИО. Переносить его некуда и незачем.
 *
 * ⚠ Берём только из ВЕРНОЙ person — где ФИО совпадает с именем карточки.
 * Чужая привязка притащила бы данные постороннего.
 * ⚠ Заполняем только пустые поля: карточка — источник истины, её значения
 * не перетираем.
 */
class PersonDrain extends Command
{
    protected $signature = 'person:drain {--dry-run : показать план без изменений}';

    protected $description = 'Забрать из person остаток данных (GetCourse, пометки) в карточки и WebUser';

    /** ФИО связанной person совпадает с именем карточки. */
    private const FIO_MATCH = <<<'SQL'
        btrim(lower(p."lastName" || ' ' || p."firstName" || ' ' || coalesce(p.patronymic,'')))
          = btrim(lower(%s."personName"))
        SQL;

    /**
     * Комментарий без служебных хвостов Directual: «перенос на <uuid>» и
     * «Заказ из Insmart <uuid>» — машинные пометки переносов, оператору они
     * в карточке не нужны.
     */
    private const CLEAN_COMMENT = <<<'SQL'
        nullif(btrim(regexp_replace(p.comment,
            '[;,]?\s*(перенос(\s+всех\s+данных)?\s+на|Заказ из Insmart)\s*[0-9a-fA-F-]*', '', 'gi'),
            ' ;,'), '')
        SQL;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $fioClient = sprintf(self::FIO_MATCH, 'cl');
        $fioPartner = sprintf(self::FIO_MATCH, 'c');
        $clean = self::CLEAN_COMMENT;

        // 1) GetCourse → WebUser (канон обучения).
        $gcWhere = <<<SQL
            FROM consultant c
            JOIN person p ON p.id = c.person AND {$fioPartner}
            WHERE c."dateDeleted" IS NULL AND c."webUser" IS NOT NULL
              AND nullif(btrim(coalesce(p."getCourseUserId",'')),'') IS NOT NULL
            SQL;
        $gc = (int) (DB::selectOne("SELECT count(*) AS cnt {$gcWhere}
              AND EXISTS (SELECT 1 FROM \"WebUser\" w WHERE w.id = c.\"webUser\"
                          AND nullif(btrim(coalesce(w.\"getCourseUserId\",'')),'') IS NULL)")->cnt ?? 0);

        // 2) Пометки происхождения → комментарий карточки (только в пустой).
        $cmtClient = (int) (DB::selectOne("SELECT count(*) AS cnt
            FROM client cl JOIN person p ON p.id = cl.person AND {$fioClient}
            WHERE cl.\"dateDeleted\" IS NULL
              AND nullif(btrim(coalesce(cl.comment,'')),'') IS NULL
              AND {$clean} IS NOT NULL")->cnt ?? 0);

        $cmtPartner = (int) (DB::selectOne("SELECT count(*) AS cnt
            FROM consultant c JOIN person p ON p.id = c.person AND {$fioPartner}
            WHERE c.\"dateDeleted\" IS NULL
              AND nullif(btrim(coalesce(c.comment,'')),'') IS NULL
              AND {$clean} IS NOT NULL")->cnt ?? 0);

        $this->info("К переносу: GetCourse-id {$gc}, пометки клиентам {$cmtClient}, пометки партнёрам {$cmtPartner}.");

        if ($dry) {
            $this->line('Сухой прогон, изменений нет.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($fioClient, $fioPartner, $clean) {
            $gcDone = DB::update("UPDATE \"WebUser\" w
                SET \"getCourseUserId\" = src.gc
                FROM (SELECT c.\"webUser\" AS uid, min(p.\"getCourseUserId\") AS gc
                      FROM consultant c
                      JOIN person p ON p.id = c.person AND {$fioPartner}
                      WHERE c.\"dateDeleted\" IS NULL AND c.\"webUser\" IS NOT NULL
                        AND nullif(btrim(coalesce(p.\"getCourseUserId\",'')),'') IS NOT NULL
                      GROUP BY 1) src
                WHERE w.id = src.uid
                  AND nullif(btrim(coalesce(w.\"getCourseUserId\",'')),'') IS NULL");

            $clDone = DB::update("UPDATE client cl
                SET comment = {$clean}, \"dateChanged\" = now()
                FROM person p
                WHERE p.id = cl.person AND {$fioClient}
                  AND cl.\"dateDeleted\" IS NULL
                  AND nullif(btrim(coalesce(cl.comment,'')),'') IS NULL
                  AND {$clean} IS NOT NULL");

            $cDone = DB::update("UPDATE consultant c
                SET comment = {$clean}
                FROM person p
                WHERE p.id = c.person AND {$fioPartner}
                  AND c.\"dateDeleted\" IS NULL
                  AND nullif(btrim(coalesce(c.comment,'')),'') IS NULL
                  AND {$clean} IS NOT NULL");

            $this->info("Перенесено: GetCourse-id {$gcDone}, пометки клиентам {$clDone}, пометки партнёрам {$cDone}.");
        });

        return self::SUCCESS;
    }
}
