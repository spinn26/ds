<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Справочники формы контракта (/admin/contracts/form-data).
 *
 * Вынесено из AdminDataController (метод занимал 159 строк). Код перенесён
 * дословно: состав списков, порядок и типы полей прежние.
 *
 * Собирает данные из двух поколений схемы сразу — legacy product/program
 * (сейчас это представления над каталогом) и products_catalog/programs_catalog.
 * Отсюда особенности, которые легко потерять при правках:
 *   - «поставщик» = COALESCE(vendorName, providerName), а все написания
 *     Insmart схлопываются в одну строку и поднимаются наверх списка;
 *   - products_catalog.provider_name в поставщики не добавляется намеренно:
 *     там лежит конечный страховщик, а фильтр работает по каналу;
 *   - записи, которых нет в legacy, отдаются с ОТРИЦАТЕЛЬНЫМ id — так фронт
 *     отличает их от legacy-записей.
 */
class ContractFormDataService
{
    /**
     * Whitelist кодов «Сетап» для дропдауна в формах контрактов.
     *
     * В legacy-таблице `setup` ~сотни записей, но платформа DS работает
     * только с этим коротким списком (запрос заказчика 2026-05-06).
     * Остальные сетапы в БД не трогаем — они нужны для legacy-отчётности,
     * но в UI заведения контрактов не показываются.
     *
     * Если список нужно расширить — добавьте код сюда. Имя ФК подтянется
     * автоматически через JOIN setup.consultant → consultant.personName.
     */
    public const ALLOWED_SETUP_CODES = [
        '565395', // Ламакин Александр Валерьевич
        '576095', // Тужилкин Дмитрий Владимирович
        '576255', // Рахманов Ленар Минибаевич
        '576328', // Бикбулатов Артур Альбертович
        '576484', // Шиндлер Виктория Анатольевна
        '576504', // Перкулимова Милана Алексеевна / Перкулимов Алексей
        '576522', // Смоленская Екатерина Николаевна
        '576839', // Зарипов Сирин Раифович
        '576861', // Магдиева Алина Рафисовна
        '577126', // Лунин Павел Валерьевич
        '577467', // Чачина Анна Сергеевна
    ];

    /** @return array<string, mixed> */
    public function build(): array
    {
        $suppliers = $this->buildSuppliers();

        $programs = $this->buildPrograms();

        $products = $this->buildProducts();

        return [
            'statuses' => DB::table('contractStatus')->orderBy('id')->get(['id', 'name']),
            'currencies' => DB::table('currency')->where('selectable', true)->orderBy('id')
                ->get()->map(fn ($c) => ['id' => $c->id, 'symbol' => $c->symbol, 'name' => $c->nameRu]),
            'countries' => DB::table('country')->orderBy('countryNameRu')
                ->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->countryNameRu]),
            'riskProfiles' => DB::table('riskProfile')->orderBy('id')
                ->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'setups' => DB::table('setup as s')
                ->leftJoin('consultant as c', 'c.id', '=', 's.consultant')
                ->whereIn('s.setup', self::ALLOWED_SETUP_CODES)
                ->orderBy('s.setup')
                ->select('s.id', 's.setup', 'c.personName')
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => trim($s->setup . ' ' . ($s->personName ?? '')),
                ]),
            'suppliers' => $suppliers,
            'programs'  => $programs,
            'products'  => $products,
        ];
    }

    /**
     * Список продуктов витрины: legacy плюс те, что есть только в каталоге.
     *
     * ⚠ Записи без legacy отдаются с ОТРИЦАТЕЛЬНЫМ id — так фронт их
     * отличает. Сюда же попадают legacy-продукты, помеченные неактивными,
     * если на них ссылается активная запись каталога: иначе такой продукт
     * выпадал бы из списка целиком.
     *
     * @return mixed
     */
    private function buildProducts()
    {
        // ── Продукты ───────────────────────────────────────────────────────
        // Источник 1: все active legacy products. Для продуктов, у которых
        // есть запись в products_catalog, подставляем имя из каталога
        // (каталог хранит «правильное» отображаемое имя, legacy может быть
        // устаревшим). catalogId пробрасывается на фронт для загрузки
        // программ при создании контракта.
        $catalogMap = DB::table('products_catalog')
            ->whereNotNull('legacy_product_id')
            ->where('active', true)
            ->get(['id as catalog_id', 'legacy_product_id', 'name as catalog_name'])
            ->keyBy('legacy_product_id');

        // Дополнительно включаем legacy-продукты, которые сами помечены
        // active=false, но на них ссылается АКТИВНАЯ запись каталога
        // (рассинхрон active между legacy и catalog). Иначе такой продукт
        // выпадает из списка целиком: из источника 1 — по active=false,
        // из источника 2 — потому что legacy_product_id у него заполнен.
        // id остаётся реальным legacy product.id → FK contract.product валиден.
        $catalogLegacyIds = $catalogMap->keys()->all();

        $legacyProducts = DB::table('product')
            ->where(function ($q) use ($catalogLegacyIds) {
                $q->where('active', true);
                if (! empty($catalogLegacyIds)) {
                    $q->orWhereIn('id', $catalogLegacyIds);
                }
            })
            ->orderBy('name')
            ->select('id', 'name',
                DB::raw('COALESCE(has_property, false) AS "hasProperty"'),
                DB::raw('COALESCE(has_term, false) AS "hasTerm"'),
                DB::raw('COALESCE(has_year_kv, false) AS "hasYearKv"'),
            )
            ->get()
            ->map(function ($p) use ($catalogMap) {
                $cat = $catalogMap->get($p->id);
                return [
                    'id'          => $p->id,
                    'name'        => $cat ? $cat->catalog_name : $p->name,
                    'catalogId'   => $cat ? (int) $cat->catalog_id : null,
                    'hasProperty' => $p->hasProperty,
                    'hasTerm'     => $p->hasTerm,
                    'hasYearKv'   => $p->hasYearKv,
                ];
            });

        // Источник 2: catalog-only продукты (без legacy_product_id — 1 шт).
        $catalogOnlyProducts = DB::table('products_catalog')
            ->whereNull('legacy_product_id')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id as catalog_id', 'name'])
            ->map(fn ($p) => [
                'id'          => -(int)$p->catalog_id,   // отрицательный → нет в legacy
                'name'        => $p->name,
                'catalogId'   => (int) $p->catalog_id,
                'hasProperty' => false,
                'hasTerm'     => false,
                'hasYearKv'   => false,
            ]);

        $products = $legacyProducts->concat($catalogOnlyProducts)->sortBy('name')->values();


        return $products;
    }

    /**
     * Список программ: legacy плюс каталожные без связи с legacy.
     *
     * @return mixed
     */
    private function buildPrograms()
    {
        // ── Программы ──────────────────────────────────────────────────────
        // Источник 1: legacy program (все исторические программы).
        $legacyPrograms = DB::table('program')
            ->whereNull('dateDeleted')
            ->orderBy('name')
            ->get(['id', 'name', 'product as productId', 'providerName']);

        // Источник 2: programs_catalog без legacy_program_id (19 программ,
        // которые есть только в новом каталоге и не попали в sync).
        // productId = products_catalog.legacy_product_id (чтобы фильтр
        // «по продукту» в ContractManager работал по тому же FK-пространству).
        $catalogOnlyPrograms = DB::table('programs_catalog as pg')
            ->join('products_catalog as pc', 'pc.id', '=', 'pg.product_id')
            ->whereNull('pg.legacy_program_id')
            ->where('pg.active', true)
            ->orderBy('pg.name')
            ->select(
                DB::raw('-(pg.id) as id'),       // отрицательный id → фронт видит его как «нет в legacy»
                'pg.name',
                'pc.legacy_product_id as productId',
                'pg.vendor as providerName'
            )
            ->get();

        $programs = $legacyPrograms->concat($catalogOnlyPrograms)->sortBy('name')->values();


        return $programs;
    }

    /**
     * Поставщики для фильтра: канал дистрибуции важнее страховщика, а все
     * написания Insmart схлопываются в одну строку и поднимаются наверх.
     *
     * @return mixed
     */
    private function buildSuppliers()
    {
        // ── Поставщики ─────────────────────────────────────────────────────
        // Источник 1a: legacy program.providerName (провайдер/страховщик).
        // Источник 1b: legacy program.vendorName (канал дистрибуции: RG.HT, Inssmart).
        // Для отображения в списке используем COALESCE(vendorName, providerName):
        // vendorName = «кто продаёт» (поставщик-канал), providerName = «кто оказывает услугу».
        $supRows = DB::table('program as pr')
            ->leftJoin('product as p', 'p.id', '=', 'pr.product')
            ->whereNull('pr.dateDeleted')
            ->where(function ($q) {
                $q->whereNotNull('pr.providerName')->orWhereNotNull('pr.vendorName');
            })
            ->distinct()
            ->get(['pr.providerName', 'pr.vendorName', 'p.name as productName']);
        $supSet = [];
        $hasInsmart = false;
        foreach ($supRows as $r) {
            // Канал дистрибуции (vendorName) имеет приоритет как «поставщик»
            $effectiveName = ($r->vendorName !== null && $r->vendorName !== '')
                ? $r->vendorName
                : $r->providerName;
            if (\App\Support\SupplierResolver::isInsmartProduct($r->productName)
                || preg_match('/ins+mart/i', (string) $effectiveName)) {
                $hasInsmart = true;
            } elseif ($effectiveName !== null && $effectiveName !== '') {
                $supSet[$effectiveName] = true;
            }
        }
        // Источник 2: programs_catalog.vendor (новый каталог).
        //
        // ⚠ Раньше здесь стоял `->each(fn ($v) => $supSet[$v] = true)`, и блок
        // не делал НИЧЕГО: замыкание захватывает массив по значению, поэтому
        // записи ложились в его копию и терялись. Вендоры, заведённые только в
        // новом каталоге, в фильтр поставщиков не попадали вовсе. Обычный
        // foreach эту ловушку убирает.
        $catalogVendors = DB::table('programs_catalog')
            ->whereNotNull('vendor')
            ->where('vendor', '!=', '')
            ->distinct()
            ->pluck('vendor');
        foreach ($catalogVendors as $v) {
            // Insmart и здесь схлопывается в одну строку — иначе он появился бы
            // в списке дважды, отдельной записью каталога.
            if (preg_match('/ins+mart/i', (string) $v)) {
                $hasInsmart = true;
                continue;
            }
            $supSet[$v] = true;
        }

        // products_catalog.provider_name в список НЕ добавляем: у части продуктов
        // там лежит конечный страховщик («Ренессанс»), а поставщик — канал («ГГА»).
        // В каноническом резолве (SupplierResolver::sqlProviderExpr) каталог —
        // последний фолбэк, поэтому такие значения в выдаче не появляются, и
        // предлагать их в фильтре нельзя: выбор вернул бы пустой список.

        $suppliers = array_keys($supSet);
        sort($suppliers, SORT_NATURAL | SORT_FLAG_CASE);
        if ($hasInsmart) array_unshift($suppliers, 'Insmart');


        return $suppliers;
    }
}
