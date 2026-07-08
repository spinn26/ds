<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Генерация id для legacy-таблиц без серийного default.
 *
 * Многие Directual-таблицы (platformCommunication, vat, city, и т.п.)
 * имеют `id integer NOT NULL` без `DEFAULT nextval(...)` — INSERT без
 * явного id падает 23502. Помещаем `LegacyId::next('table')` внутрь
 * транзакции вместе с самим INSERT — advisory_xact_lock сериализует
 * генерацию между параллельными запросами.
 *
 * Использование:
 *   DB::transaction(function () {
 *       $id = LegacyId::next('platformCommunication');
 *       DB::table('platformCommunication')->insert(['id' => $id, ...]);
 *   });
 */
class LegacyId
{
    public static function next(string $table): int
    {
        $key = 'legacy-id:' . $table;
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
        $max = DB::table($table)->max('id');
        return ((int) $max) + 1;
    }

    /**
     * Выровнять серийный PK-сиквенс таблицы под MAX(id) — защита от
     * duplicate_pkey, когда insertGetId врезается в существующий id (лаг
     * сиквенса после restore/relayer). Идемпотентно и дёшево. Для таблиц БЕЗ
     * сиквенса — no-op. Вызывать перед insertGetId. $table/$column — только
     * доверенные КОНСТАНТЫ (не пользовательский ввод): идентификаторы нельзя
     * биндить, поэтому интерполируются в кавычках.
     */
    public static function syncSequence(string $table, string $column = 'id'): void
    {
        $seq = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS s', [$table, $column]);
        if (! $seq || empty($seq->s)) {
            return;
        }
        DB::statement(
            'SELECT setval(?, GREATEST((SELECT MAX("' . $column . '") FROM "' . $table . '"), 1))',
            [$seq->s]
        );
    }
}
