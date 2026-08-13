<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Слияние legacy-таблиц product/program в каталог — один источник данных.
 *
 * Ключевая идея: НЕ трогаем 18 756 контрактов и 4 451 строку тарифов, а
 * перенумеровываем сам каталог в id legacy. После этого contract.product = 61
 * как было, так и останется, только указывать будет на products_catalog.
 * Обратный путь (переписать ссылки в контрактах) означал бы риск для денег:
 * тариф ищется по паре продукт×программа, ошибка переноса всплыла бы месяцы
 * спустя заниженной комиссией.
 *
 * Порядок: catalog:fill-from-legacy → миграция catalog_absorb_legacy_fields →
 * эта команда.
 *
 * ⚠ Перенумерация идёт по явной карте «старый id → новый», построенной ДО
 * сдвига: после присвоения новых id прежние значения теряются, и дочерние
 * ссылки не с чем сопоставить — на этом упала первая версия.
 * ⚠ Сдвиг нужен, потому что диапазоны id каталога и legacy пересекаются.
 * ⚠ После удаления таблиц создаются представления product/program поверх
 * каталога: читающих мест 55 в 18 файлах, переводить их одним махом — верный
 * способ тихо сломать расчёт. Писать через представления нельзя, все места
 * записи переведены на каталог напрямую.
 */
class CatalogMergeLegacy extends Command
{
    protected $signature = 'catalog:merge-legacy {--apply : выполнить (без флага — только проверки)}';

    protected $description = 'Слить legacy product/program в каталог: перенумеровать каталог в id legacy';

    /** Временный сдвиг id, заведомо выше любых существующих. */
    private const OFFSET = 1000000;

    public function handle(): int
    {
        $problems = $this->preflight();
        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->error('✗ '.$p);
            }

            return self::FAILURE;
        }
        $this->info('✓ Проверки пройдены: карточки есть у всех используемых legacy-строк, расчётные поля перенесены.');

        if (! $this->option('apply')) {
            $this->line('Сухой прогон — изменений нет. Повтори с --apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            // Ключ дочерней таблицы держит id продуктов и не даёт их сдвинуть.
            DB::statement('ALTER TABLE programs_catalog DROP CONSTRAINT IF EXISTS programs_catalog_product_id_foreign');

            $this->renumber('products_catalog', 'legacy_product_id', 'product');
            $this->renumber('programs_catalog', 'legacy_program_id', 'program');
            $this->moveForeignKeys();
            $this->dropLegacy();
            $this->createCompatViews();

            DB::statement('ALTER TABLE programs_catalog
                ADD CONSTRAINT programs_catalog_product_id_foreign
                FOREIGN KEY (product_id) REFERENCES products_catalog(id) ON DELETE CASCADE');
        });

        $this->info('Готово: каталог перенумерован, legacy-таблицы заменены представлениями.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function preflight(): array
    {
        $out = [];

        $missP = (int) DB::selectOne(<<<'SQL'
            SELECT count(*) AS c FROM product p
            WHERE NOT EXISTS (SELECT 1 FROM products_catalog pc WHERE pc.legacy_product_id = p.id)
              AND (EXISTS (SELECT 1 FROM contract c WHERE c.product = p.id)
                OR EXISTS (SELECT 1 FROM "dsCommission" d WHERE d.product = p.id))
            SQL)->c;
        if ($missP > 0) {
            $out[] = "Продуктов без карточки: {$missP}. Сначала catalog:fill-from-legacy --apply.";
        }

        $missPr = (int) DB::selectOne(<<<'SQL'
            SELECT count(*) AS c FROM program pr
            WHERE NOT EXISTS (SELECT 1 FROM programs_catalog pg WHERE pg.legacy_program_id = pr.id)
              AND (EXISTS (SELECT 1 FROM contract c WHERE c.program = pr.id)
                OR EXISTS (SELECT 1 FROM "dsCommission" d WHERE d.program = pr.id))
            SQL)->c;
        if ($missPr > 0) {
            $out[] = "Программ без карточки: {$missPr}. Сначала catalog:fill-from-legacy --apply.";
        }

        foreach ([['products_catalog', 'legacy_product_id'], ['programs_catalog', 'legacy_program_id']] as [$table, $col]) {
            $dupes = (int) DB::selectOne("SELECT count(*) AS c FROM (
                SELECT {$col} FROM {$table} WHERE {$col} IS NOT NULL GROUP BY 1 HAVING count(*) > 1) t")->c;
            if ($dupes > 0) {
                $out[] = "{$table}: {$dupes} карточек делят одну legacy-строку — связка должна быть один к одному.";
            }
        }

        // Расчётные поля должны быть перенесены, иначе комиссии останутся без
        // входных данных — поймано на репетиции 13.08.2026.
        $hasFields = (int) DB::selectOne("SELECT count(*) AS c FROM information_schema.columns
            WHERE table_name = 'programs_catalog' AND column_name = 'points_method'")->c;
        if ($hasFields === 0) {
            $out[] = 'В programs_catalog нет расчётных полей — прогони миграцию catalog_absorb_legacy_fields.';
        }

        return $out;
    }

    /** Перенумеровать каталог в id legacy по карте «старый id → новый». */
    private function renumber(string $table, string $legacyCol, string $legacyTable): void
    {
        $child = $table === 'products_catalog' ? 'programs_catalog' : null;
        $rows = DB::table($table)->orderBy('id')->get(['id', $legacyCol]);

        // Хвост для карточек без legacy-пары — выше любого занятого id.
        $next = max(
            (int) (DB::table($legacyTable)->max('id') ?? 0),
            (int) ($rows->max($legacyCol) ?? 0)
        ) + 1;

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->id] = $r->{$legacyCol} !== null ? (int) $r->{$legacyCol} : $next++;
        }

        DB::statement("UPDATE {$table} SET id = id + ".self::OFFSET);
        if ($child) {
            DB::statement("UPDATE {$child} SET product_id = product_id + ".self::OFFSET);
        }

        foreach ($map as $old => $new) {
            DB::table($table)->where('id', $old + self::OFFSET)->update(['id' => $new]);
            if ($child) {
                DB::table($child)->where('product_id', $old + self::OFFSET)->update(['product_id' => $new]);
            }
        }

        DB::statement("SELECT setval(pg_get_serial_sequence('{$table}','id'),
            GREATEST((SELECT max(id) FROM {$table}), 1))");

        $this->line("  {$table}: перенумеровано строк ".count($map).'; хвост без legacy — с '.$next.'.');
    }

    /** Внешние ключи со старых таблиц — на каталог. Значения не меняем. */
    private function moveForeignKeys(): void
    {
        $fks = DB::select(<<<'SQL'
            SELECT con.conname, con.conrelid::regclass::text AS tablica,
                   a.attname AS kolonka, con.confrelid::regclass::text AS na
            FROM pg_constraint con
            JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = con.conkey[1]
            WHERE con.contype = 'f' AND con.confrelid IN ('product'::regclass, 'program'::regclass)
            SQL);

        $moved = 0;
        foreach ($fks as $fk) {
            $table = trim($fk->tablica, '"');
            DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT "'.$fk->conname.'"');

            // Ссылки самих legacy-таблиц и связку каталога не восстанавливаем.
            if (in_array($table, ['program', 'product', 'products_catalog', 'programs_catalog'], true)) {
                continue;
            }

            $target = $fk->na === 'product' ? 'products_catalog' : 'programs_catalog';
            DB::statement('ALTER TABLE "'.$table.'" ADD CONSTRAINT "'.$fk->conname.'"
                FOREIGN KEY ("'.$fk->kolonka.'") REFERENCES '.$target.'(id) NOT VALID');
            $moved++;
        }

        $this->line("  внешних ключей переведено на каталог: {$moved}");
    }

    private function dropLegacy(): void
    {
        DB::statement('DROP TABLE IF EXISTS "productTags" CASCADE');
        DB::statement('DROP TABLE IF EXISTS program CASCADE');
        DB::statement('DROP TABLE IF EXISTS product CASCADE');
        $this->line('  удалены таблицы: product, program, productTags');
    }

    /**
     * Представления со старыми именами колонок поверх каталога — чтобы 55
     * читающих мест продолжили работать, пока код переводится файл за файлом.
     */
    private function createCompatViews(): void
    {
        DB::statement('DROP VIEW IF EXISTS product');
        DB::statement('DROP VIEW IF EXISTS program');

        // ⚠ Набор колонок повторяет legacy-таблицы ЦЕЛИКОМ, включая те, которым
        // в каталоге соответствия нет (отдаём NULL). Неполный список ломает
        // чтение молча и не там, где смотришь: витрина партнёра падала на
        // program.currency, которого в первой версии представления не было.
        DB::statement(<<<'SQL'
            CREATE VIEW product AS
            SELECT id,
                   name,
                   type                  AS "typeName",
                   active,
                   visible_to_resident   AS "visibleToResident",
                   visible_to_calculator AS "visibleToCalculator",
                   form_link             AS "formLink",
                   open_product_url      AS "openProductUrl",
                   education_url         AS "educationUrl",
                   instruction_url       AS "instructionUrl",
                   image_url             AS "imageUrl",
                   hero_image,
                   description,
                   priority,
                   has_property, has_term, has_year_kv,
                   no_commission         AS "noComission",
                   CASE WHEN active THEN 'published' ELSE 'draft' END AS publish_status,
                   created_at            AS published_at,
                   NULL::int  AS published_by,
                   NULL::text AS "tagList",
                   NULL::int  AS "productTags",
                   NULL::int  AS "motivationGroup",
                   NULL::int  AS ambassador,
                   NULL::int  AS access,
                   NULL::int  AS "productType"
            FROM products_catalog
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW program AS
            SELECT id,
                   name,
                   product_id               AS product,
                   active,
                   provider_name            AS "providerName",
                   vendor                   AS "vendorName",
                   currency                 AS "currencyName",
                   category_name            AS "categoryName",
                   category,
                   ds_percent               AS "dsPercent",
                   points_method            AS "pointsMethod",
                   points_formula           AS "pointsFormula",
                   points_min               AS "pointsMin",
                   points_max               AS "pointsMax",
                   fixed_cost               AS "fixedCost",
                   kv_payout_year           AS "kvPayoutYear",
                   commission_calc_property AS "commissionCalcProperty",
                   term,
                   term_contract            AS "termContract",
                   form_link                AS "formLink",
                   visible_to_resident      AS "visibleToResident",
                   visible_to_calculator    AS "visibleToCalculator",
                   comment_snippets         AS "calcComment",
                   NULL::timestamp AS "dateDeleted",
                   NULL::int  AS currency,
                   NULL::int  AS vendor,
                   NULL::int  AS provider,
                   NULL::int  AS "dsCommission",
                   NULL::int  AS "productType",
                   NULL::text AS "productName",
                   NULL::text AS "productTypeName"
            FROM programs_catalog
            SQL);

        $this->line('  созданы представления product/program поверх каталога');
    }
}
