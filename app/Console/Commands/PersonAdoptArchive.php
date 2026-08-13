<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разбор хвоста архива person — записей, до которых прежний перенос не дошёл.
 *
 * Перенос шёл по СВЯЗАННОЙ person и только по живым карточкам. Осталось 156
 * записей с данными, у которых живой карточки нет. Из них 105 принадлежат
 * МЯГКО УДАЛЁННЫМ карточкам (21 партнёр, 93 клиента): человек в базе есть,
 * просто скрыт, и его контакты нужно положить в его же карточку — если её
 * когда-нибудь вернут, данные будут на месте.
 *
 * ⚠ Совпадение ФИО должно быть однозначным с обеих сторон: одна запись в
 * архиве и одна карточка. Иначе тёзка получит чужой телефон — та самая
 * ошибка, из-за которой клиент видел чужие контакты (инцидент 2026-08-12).
 * ⚠ Заполняем только пустые поля; что уже есть в карточке — не трогаем.
 *
 * --orphans выводит записи, которым карточки нет вовсе (тесты, обрывки ФИО и,
 * возможно, живые люди) — их судьбу решает оператор.
 */
class PersonAdoptArchive extends Command
{
    protected $signature = 'person:adopt-archive
        {--dry-run : показать план без изменений}
        {--orphans : показать записи, которым карточки нет вовсе}';

    protected $description = 'Перенести контакты из хвоста person в карточки (включая удалённые)';

    /** Записи архива с данными, у которых нет ЖИВОЙ карточки с тем же ФИО. */
    private const TAIL_SQL = <<<'SQL'
        SELECT p.id,
               btrim(lower(coalesce(p."lastName",'') || ' ' || coalesce(p."firstName",'') || ' '
                    || coalesce(p.patronymic,''))) AS fio
        FROM person p
        WHERE (nullif(btrim(coalesce(p.email,'')),'') IS NOT NULL
            OR nullif(btrim(coalesce(p.phone,'')),'') IS NOT NULL
            OR p."birthDate" IS NOT NULL
            OR nullif(btrim(coalesce(p.city,'')),'') IS NOT NULL
            OR nullif(btrim(coalesce(p."nicTG",'')),'') IS NOT NULL)
          AND NOT EXISTS (SELECT 1 FROM client cl WHERE cl."dateDeleted" IS NULL
                           AND btrim(lower(cl."personName")) = btrim(lower(coalesce(p."lastName",'') || ' '
                               || coalesce(p."firstName",'') || ' ' || coalesce(p.patronymic,''))))
          AND NOT EXISTS (SELECT 1 FROM consultant c WHERE c."dateDeleted" IS NULL
                           AND btrim(lower(c."personName")) = btrim(lower(coalesce(p."lastName",'') || ' '
                               || coalesce(p."firstName",'') || ' ' || coalesce(p.patronymic,''))))
        SQL;

    public function handle(): int
    {
        if ($this->option('orphans')) {
            return $this->showOrphans();
        }

        $dry = (bool) $this->option('dry-run');
        $pairs = [
            'client' => $this->pairs('client'),
            'consultant' => $this->pairs('consultant'),
        ];

        $this->info('Однозначных пар: клиентов '.count($pairs['client'])
            .', партнёров '.count($pairs['consultant']).'.');

        if ($dry) {
            $this->line('Сухой прогон, изменений нет.');

            return self::SUCCESS;
        }

        $done = ['client' => 0, 'consultant' => 0];
        DB::transaction(function () use ($pairs, &$done) {
            foreach ($pairs as $table => $rows) {
                foreach ($rows as $r) {
                    $done[$table] += DB::update($this->updateSql($table), [$r->person_id, $r->card_id]);
                }
            }
        });

        $this->info("Дозаполнено карточек: клиентов {$done['client']}, партнёров {$done['consultant']}.");

        return self::SUCCESS;
    }

    /**
     * Пары «архивная запись → карточка» для таблицы: карточка без контактов
     * (в т.ч. удалённая), архивная запись с контактами, ФИО совпадает и
     * встречается ровно один раз с каждой стороны.
     */
    private function pairs(string $table): array
    {
        $tail = self::TAIL_SQL;
        $quoted = '"'.$table.'"';

        return DB::select(<<<SQL
            WITH tail AS ({$tail}),
            pary AS (
                SELECT t.id AS person_id, k.id AS card_id, t.fio
                FROM tail t
                JOIN {$quoted} k ON btrim(lower(k."personName")) = t.fio
                JOIN person p ON p.id = t.id
                WHERE nullif(btrim(coalesce(k.email,'')),'') IS NULL
                  AND nullif(btrim(coalesce(k.phone,'')),'') IS NULL
                  AND (nullif(btrim(coalesce(p.email,'')),'') IS NOT NULL
                    OR nullif(btrim(coalesce(p.phone,'')),'') IS NOT NULL)
            )
            SELECT person_id, card_id FROM pary p1
            WHERE (SELECT count(*) FROM pary p2 WHERE p2.fio = p1.fio) = 1
            SQL);
    }

    /** Переносим только пустые поля карточки; ДР архива — ISO-строка. */
    private function updateSql(string $table): string
    {
        $quoted = '"'.$table.'"';
        // У партнёра ДР и город — свои колонки того же вида, что у клиента.
        return <<<SQL
            UPDATE {$quoted} k
            SET email = coalesce(nullif(btrim(k.email), ''), nullif(btrim(p.email), '')),
                phone = coalesce(nullif(btrim(k.phone), ''), nullif(btrim(p.phone), '')),
                "birthDate" = coalesce(k."birthDate", left(p."birthDate", 10)),
                city = coalesce(nullif(btrim(k.city), ''), nullif(btrim(p.city), '')),
                "nicTG" = coalesce(nullif(btrim(k."nicTG"), ''), nullif(btrim(p."nicTG"), ''))
            FROM person p
            WHERE p.id = ? AND k.id = ?
            SQL;
    }

    /** Записи архива, которым карточки нет вовсе — ни живой, ни удалённой. */
    private function showOrphans(): int
    {
        $tail = self::TAIL_SQL;
        $rows = DB::select(<<<SQL
            WITH tail AS ({$tail})
            SELECT p.id, p."lastName", p."firstName", p.patronymic, p.role,
                   p.email, p.phone, p."dateDeleted"
            FROM tail t
            JOIN person p ON p.id = t.id
            WHERE NOT EXISTS (SELECT 1 FROM client cl WHERE btrim(lower(cl."personName")) = t.fio)
              AND NOT EXISTS (SELECT 1 FROM consultant c WHERE btrim(lower(c."personName")) = t.fio)
            ORDER BY p."lastName", p."firstName"
            SQL);

        $this->info('Записей без карточки: '.count($rows).'.');
        $this->table(
            ['id', 'Фамилия', 'Имя', 'Роль', 'Почта', 'Телефон', 'Удалена'],
            array_map(fn ($r) => [
                $r->id, $r->lastName, $r->firstName, $r->role,
                $r->email, $r->phone, $r->dateDeleted ? 'да' : '',
            ], $rows)
        );

        return self::SUCCESS;
    }
}
