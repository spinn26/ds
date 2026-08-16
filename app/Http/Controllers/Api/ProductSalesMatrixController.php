<?php

namespace App\Http\Controllers\Api;

use App\Services\SalesMatrixAssembler;
use App\Services\SalesMatrixSupport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Матрица динамики продаж по продуктам.
 *
 * MVP (admin-only): агрегирует транзакции за выбранный год по цепочке
 * transaction → contract → product / program. Показывает 7 метрик:
 * Объём, Кол-во, Средний чек, Выручка, Баллы, Кол-во ФК, Кол-во клиентов.
 *
 * Временна́я ось (месяцы) строится в отдельном эндпоинте /monthly.
 * Базовый эндпоинт отдаёт итоги за год целиком.
 */
class ProductSalesMatrixController extends Controller
{
        public function __construct(
        private readonly SalesMatrixSupport $matrixSupport,
        private readonly SalesMatrixAssembler $assembler,
    ) {}

/**
     * GET /admin/reports/sales-matrix
     *
     * Params:
     *   year         int  (required)
     *   suppliers[]  str  (optional, array of providerName values)
     *   products[]   int  (optional, array of product.id values)
     */
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'year'        => 'required|integer|min:2020|max:2099',
            'suppliers'   => 'nullable|array',
            'suppliers.*' => 'string|max:200',
            'products'    => 'nullable|array',
            'products.*'  => 'integer',
        ]);

        $year = (int) $params['year'];

        // --- Агрегация по продукту + программе ---
        $q = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('product as p', 'p.id', '=', 'co.product')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->where('t.dateYear', (string) $year)
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->select([
                'p.id   as product_id',
                'p.name as product_name',
                'pg.id   as program_id',
                'pg.name as program_name',
                DB::raw('COALESCE(pg."providerName", \'—\') as supplier'),
                DB::raw('SUM(COALESCE(t."amountRUB", 0))      as volume'),
                DB::raw('COUNT(DISTINCT co.id)                as contract_count'),
                DB::raw('SUM(COALESCE(t."commissionsAmountRUB", 0))  as revenue'),
                DB::raw('SUM(COALESCE(t."personalVolume", 0)) as points'),
                DB::raw('COUNT(DISTINCT co.consultant)         as fc_count'),
                DB::raw('COUNT(DISTINCT co.client)             as client_count'),
            ])
            ->groupBy('p.id', 'p.name', 'pg.id', 'pg.name', DB::raw('pg."providerName"'));

        // Фильтр по поставщику
        if (! empty($params['suppliers'])) {
            $q->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers']);
        }
        // Фильтр по продукту
        if (! empty($params['products'])) {
            $q->whereIn('p.id', $params['products']);
        }

        $rows = $q->orderBy('p.name')->orderBy(DB::raw('pg."providerName"'))->orderBy('pg.name')->get();

        // --- Сборка структуры product → [programs] ---
        $productMap = [];
        foreach ($rows as $r) {
            $pid = $r->product_id;
            if (! isset($productMap[$pid])) {
                $productMap[$pid] = [
                    'productId'   => $pid,
                    'productName' => $r->product_name,
                    'suppliers'   => [],
                    'programs'    => [],
                    'volume'      => 0,
                    'count'       => 0,
                    'revenue'     => 0,
                    'points'      => 0,
                    'fcSet'       => [],
                    'clientSet'   => [],
                ];
            }
            if ($r->supplier !== '—' && ! in_array($r->supplier, $productMap[$pid]['suppliers'])) {
                $productMap[$pid]['suppliers'][] = $r->supplier;
            }

            $volume   = (float) $r->volume;
            $count    = (int)   $r->contract_count;
            $revenue  = (float) $r->revenue;
            $points   = (float) $r->points;
            $fcCount  = (int)   $r->fc_count;
            $clCount  = (int)   $r->client_count;

            $productMap[$pid]['programs'][] = [
                'programId'   => $r->program_id,
                'programName' => $r->program_name,
                'supplier'    => $r->supplier,
                'volume'      => $volume,
                'count'       => $count,
                'avgCheck'    => $count > 0 ? round($volume / $count, 2) : 0,
                'revenue'     => $revenue,
                'points'      => $points,
                'fcCount'     => $fcCount,
                'clientCount' => $clCount,
            ];

            // Агрегат на уровне продукта (ФК/клиенты нельзя просто складывать —
            // один ФК мог продавать несколько программ одного продукта; точный
            // DISTINCT вычислен отдельным запросом ниже).
            $productMap[$pid]['volume']   += $volume;
            $productMap[$pid]['count']    += $count;
            $productMap[$pid]['revenue']  += $revenue;
            $productMap[$pid]['points']   += $points;
        }

        // --- Точный DISTINCT fc/client на уровне продукта ---
        $distinctQ = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->where('t.dateYear', (string) $year)
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->select([
                'co.product as product_id',
                DB::raw('COUNT(DISTINCT co.consultant) as fc_count'),
                DB::raw('COUNT(DISTINCT co.client)     as client_count'),
            ])
            ->groupBy('co.product');

        if (! empty($params['products'])) {
            $distinctQ->whereIn('co.product', $params['products']);
        }

        $distinctCounts = $distinctQ->get()->keyBy('product_id');

        // Grand totals (запрос без группировки по продукту)
        $totalsQ = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->where('t.dateYear', (string) $year)
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt');

        if (! empty($params['suppliers'])) {
            $totalsQ->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers']);
        }
        if (! empty($params['products'])) {
            $totalsQ->whereIn('co.product', $params['products']);
        }

        $totalsRow = $totalsQ->selectRaw('
            SUM(COALESCE(t."amountRUB", 0))      as volume,
            COUNT(DISTINCT co.id)                as contract_count,
            SUM(COALESCE(t."commissionsAmountRUB", 0))  as revenue,
            SUM(COALESCE(t."personalVolume", 0)) as points,
            COUNT(DISTINCT co.consultant)         as fc_count,
            COUNT(DISTINCT co.client)             as client_count
        ')->first();

        $grandVolume = (float) ($totalsRow->volume ?? 0);
        $grandCount  = (int)   ($totalsRow->contract_count ?? 0);
        $grandTotals = [
            'volume'      => $grandVolume,
            'count'       => $grandCount,
            'avgCheck'    => $grandCount > 0 ? round($grandVolume / $grandCount, 2) : 0,
            'revenue'     => (float) ($totalsRow->revenue ?? 0),
            'points'      => (float) ($totalsRow->points ?? 0),
            'fcCount'     => (int)   ($totalsRow->fc_count ?? 0),
            'clientCount' => (int)   ($totalsRow->client_count ?? 0),
        ];

        // --- Финальная сборка ---
        $resultRows = [];
        foreach ($productMap as $pid => $prod) {
            $dc  = $distinctCounts[$pid] ?? null;
            $vol = $prod['volume'];
            $cnt = $prod['count'];
            $resultRows[] = [
                'productId'   => $prod['productId'],
                'productName' => $prod['productName'],
                'suppliers'   => $prod['suppliers'],
                'volume'      => round($vol, 2),
                'count'       => $cnt,
                'avgCheck'    => $cnt > 0 ? round($vol / $cnt, 2) : 0,
                'revenue'     => round($prod['revenue'], 2),
                'points'      => round($prod['points'], 2),
                'fcCount'     => $dc ? (int) $dc->fc_count : 0,
                'clientCount' => $dc ? (int) $dc->client_count : 0,
                'programs'    => $prod['programs'],
            ];
        }

        // Справочники для фильтров (из полного набора за год, без user-фильтров)
        $allSuppliers = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->where('t.dateYear', (string) $year)
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->whereNotNull('pg.providerName')
            ->distinct()
            ->orderBy('pg.providerName')
            ->pluck('pg.providerName');

        $allProducts = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('product as p', 'p.id', '=', 'co.product')
            ->where('t.dateYear', (string) $year)
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->select('p.id', 'p.name')
            ->distinct()
            ->orderBy('p.name')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]);

        return response()->json([
            'year'        => $year,
            'rows'        => $resultRows,
            'grandTotals' => $grandTotals,
            'suppliers'   => $allSuppliers->values(),
            'products'    => $allProducts->values(),
        ]);
    }



    /**
     * GET /admin/reports/sales-matrix/lookups
     *
     * Полные справочники поставщиков и продуктов для фильтров — по ВСЕМ
     * контрактам (любой период/состояние), а не только по транзакциям
     * текущего года. Раньше опции строились из ответа отчёта (transaction +
     * dateYear), поэтому продукты/поставщики без транзакций в выбранном
     * периоде (например «В работе»/«Активировано») в фильтре не появлялись.
     */
    public function lookups(): JsonResponse
    {
        // Резолвнутый поставщик (Insmart + реальные providerName), тот же, что
        // применяется в фильтре. Плейсхолдер «—» (нет поставщика) не показываем.
        $suppliers = DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereNull('co.deletedAt')
            ->selectRaw($this->matrixSupport->resolvedSupplierSql() . ' as supplier')
            ->distinct()
            ->orderBy('supplier')
            ->pluck('supplier')
            ->filter(fn ($s) => $s !== null && $s !== '—')
            ->values();

        $products = DB::table('contract as co')
            ->join('product as p', 'p.id', '=', 'co.product')
            ->whereNull('co.deletedAt')
            ->select('p.id', 'p.name')
            ->distinct()
            ->orderBy('p.name')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])
            ->values();

        return response()->json([
            'suppliers' => $suppliers,
            'products'  => $products,
        ]);
    }

    /**
     * GET /admin/reports/sales-matrix/monthly
     *
     * То же, что index, но с разбивкой по месяцам внутри каждой программы.
     * Params: year, suppliers[], products[]
     */
    public function monthly(Request $request): JsonResponse
    {
        $params = $request->validate([
            'year'        => 'required|integer|min:2020|max:2099',
            'suppliers'   => 'nullable|array',
            'suppliers.*' => 'string|max:200',
            'products'    => 'nullable|array',
            'products.*'  => 'integer',
        ]);

        $year = (int) $params['year'];

        $q = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('product as p', 'p.id', '=', 'co.product')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->where('t.dateYear', (string) $year)
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->select([
                'p.id   as product_id',
                'pg.id  as program_id',
                't.dateMonth',
                DB::raw('SUM(COALESCE(t."amountRUB", 0))      as volume'),
                DB::raw('COUNT(DISTINCT co.id)                as contract_count'),
                DB::raw('SUM(COALESCE(t."commissionsAmountRUB", 0))  as revenue'),
                DB::raw('SUM(COALESCE(t."personalVolume", 0)) as points'),
                DB::raw('COUNT(DISTINCT co.consultant)         as fc_count'),
                DB::raw('COUNT(DISTINCT co.client)             as client_count'),
            ])
            ->groupBy('p.id', 'pg.id', 't.dateMonth')
            ->orderBy('p.id')->orderBy('pg.id')->orderBy('t.dateMonth');

        if (! empty($params['suppliers'])) {
            $q->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers']);
        }
        if (! empty($params['products'])) {
            $q->whereIn('p.id', $params['products']);
        }

        $rows = $q->get();

        // months present in data
        $months = $rows->pluck('dateMonth')->unique()->sort()->values();

        // Indexed: productId → programId → month → metrics
        $data = [];
        foreach ($rows as $r) {
            $v = (float) $r->volume;
            $c = (int)   $r->contract_count;
            $data[$r->product_id][$r->program_id][$r->dateMonth] = [
                'volume'      => round($v, 2),
                'count'       => $c,
                'avgCheck'    => $c > 0 ? round($v / $c, 2) : 0,
                'revenue'     => round((float) $r->revenue, 2),
                'points'      => round((float) $r->points, 2),
                'fcCount'     => (int) $r->fc_count,
                'clientCount' => (int) $r->client_count,
            ];
        }

        return response()->json([
            'year'   => $year,
            'months' => $months,
            'data'   => $data,
        ]);
    }

    /**
     * GET /admin/reports/sales-matrix/fc
     *
     * Матрица продаж в разрезе ФК → Продукт → Программа,
     * с разбивкой по месяцам внутри заданного периода (квартал / произвольный диапазон).
     *
     * Params:
     *   from       Y-m  (required) — начало периода, напр. "2026-01"
     *   to         Y-m  (required) — конец  периода, напр. "2026-03"
     *   products[] int  (optional) — фильтр по product.id
     */
    public function fcMatrix(Request $request): JsonResponse
    {
        $params = $request->validate([
            'from'        => 'required|date_format:Y-m',
            'to'          => 'required|date_format:Y-m',
            'products'    => 'nullable|array',
            'products.*'  => 'integer',
        ]);

        $from = $params['from'];
        $to   = $params['to'];

        $months = $this->matrixSupport->monthRange($from, $to);

        $q = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('consultant as cons', 'cons.id', '=', 'co.consultant')
            // LEFT, а не INNER: у сотен импортированных ФК логина нет вовсе, и
            // внутреннее соединение выбрасывало их продажи из отчёта целиком,
            // хотя в «Факте» они присутствуют.
            ->leftJoin(DB::raw('"WebUser" as wu'), DB::raw('wu.id'), '=', DB::raw('cons."webUser"'))
            ->join('product as p', 'p.id', '=', 'co.product')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereBetween('t.dateMonth', [$from, $to])
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->select([
                'co.consultant                                           as fc_id',
                // Имя из логина, а без логина — из карточки партнёра. Склейка
                // через || даёт NULL, если пуста любая часть, поэтому пустой
                // результат тоже уступает карточке.
                DB::raw('COALESCE(NULLIF(TRIM(COALESCE(wu."lastName", \'\') || \' \' || COALESCE(wu."firstName", \'\')), \'\'), cons."personName") as fc_name'),
                'p.id                                                   as product_id',
                'p.name                                                 as product_name',
                'pg.id                                                  as program_id',
                'pg.name                                                as program_name',
                't.dateMonth',
                DB::raw('SUM(COALESCE(t."amountRUB",     0))           as volume'),
                DB::raw('COUNT(DISTINCT co.id)                          as cnt'),
                DB::raw('SUM(COALESCE(t."commissionsAmountRUB", 0))           as revenue'),
                DB::raw('SUM(COALESCE(t."personalVolume",0))           as points'),
                DB::raw('COUNT(DISTINCT co.client)                      as client_count'),
            ])
            ->groupBy(
                'co.consultant',
                // personName участвует в имени наравне с частями логина —
                // значит, обязан быть и в группировке.
                DB::raw('wu."lastName"'), DB::raw('wu."firstName"'), DB::raw('cons."personName"'),
                'p.id', 'p.name', 'pg.id', 'pg.name', 't.dateMonth'
            )
            // Сортируем по тому же выражению, что и показываем: у ФК без
            // логина части имени пусты, и сортировка по ним ставила бы их
            // всех в одну кучу.
            ->orderBy(DB::raw('COALESCE(NULLIF(TRIM(COALESCE(wu."lastName", \'\') || \' \' || COALESCE(wu."firstName", \'\')), \'\'), cons."personName")'))
            ->orderBy('p.name')
            ->orderBy('pg.name')
            ->orderBy('t.dateMonth');

        if (! empty($params['products'])) {
            $q->whereIn('p.id', $params['products']);
        }

        $rows = $q->get();

        // Build tree: fc → product → program → month → metrics
        $fcMap = [];
        foreach ($rows as $r) {
            $fcId = $r->fc_id;
            $pid  = $r->product_id;
            $pgid = $r->program_id;
            $mo   = $r->dateMonth;

            $v  = round((float) $r->volume,  2);
            $c  = (int)         $r->cnt;
            $rv = round((float) $r->revenue, 2);
            $pt = round((float) $r->points,  2);
            $cl = (int)         $r->client_count;

            if (! isset($fcMap[$fcId])) {
                $fcMap[$fcId] = [
                    'fcId' => $fcId, 'fcName' => $r->fc_name,
                    'v' => 0, 'c' => 0, 'rv' => 0, 'pt' => 0, 'cl' => 0,
                    'monthly' => [], 'products' => [],
                ];
            }
            if (! isset($fcMap[$fcId]['products'][$pid])) {
                $fcMap[$fcId]['products'][$pid] = [
                    'productId' => $pid, 'productName' => $r->product_name,
                    'v' => 0, 'c' => 0, 'rv' => 0, 'pt' => 0, 'cl' => 0,
                    'monthly' => [], 'programs' => [],
                ];
            }
            if (! isset($fcMap[$fcId]['products'][$pid]['programs'][$pgid])) {
                $fcMap[$fcId]['products'][$pid]['programs'][$pgid] = [
                    'programId' => $pgid, 'programName' => $r->program_name,
                    'v' => 0, 'c' => 0, 'rv' => 0, 'pt' => 0, 'cl' => 0,
                    'monthly' => [],
                ];
            }

            $vals = ['volume' => $v, 'count' => $c, 'revenue' => $rv, 'points' => $pt, 'clientCount' => $cl];

            // Program
            $pg = &$fcMap[$fcId]['products'][$pid]['programs'][$pgid];
            $pg['monthly'][$mo] = $vals;
            $pg['v'] += $v; $pg['c'] += $c; $pg['rv'] += $rv; $pg['pt'] += $pt; $pg['cl'] += $cl;

            // Product
            $pr = &$fcMap[$fcId]['products'][$pid];
            foreach ($vals as $k => $val) {
                $pr['monthly'][$mo][$k] = ($pr['monthly'][$mo][$k] ?? 0) + $val;
            }
            $pr['v'] += $v; $pr['c'] += $c; $pr['rv'] += $rv; $pr['pt'] += $pt; $pr['cl'] += $cl;

            // FC
            $fc = &$fcMap[$fcId];
            foreach ($vals as $k => $val) {
                $fc['monthly'][$mo][$k] = ($fc['monthly'][$mo][$k] ?? 0) + $val;
            }
            $fc['v'] += $v; $fc['c'] += $c; $fc['rv'] += $rv; $fc['pt'] += $pt; $fc['cl'] += $cl;
        }
        unset($fc, $pr, $pg);

        // Flatten tree & accumulate grand totals
        $result = [];
        $grand  = ['volume' => 0, 'count' => 0, 'revenue' => 0, 'points' => 0, 'clientCount' => 0, 'monthly' => []];

        foreach ($fcMap as $fc) {
            $products = [];
            foreach ($fc['products'] as $prod) {
                $programs = [];
                foreach ($prod['programs'] as $pg) {
                    $programs[] = [
                        'programId'   => $pg['programId'],
                        'programName' => $pg['programName'],
                        'volume'      => $pg['v'],  'count'       => $pg['c'],
                        'revenue'     => $pg['rv'], 'points'      => $pg['pt'],
                        'clientCount' => $pg['cl'],
                        'monthly'     => $pg['monthly'],
                    ];
                }
                $products[] = [
                    'productId'   => $prod['productId'],
                    'productName' => $prod['productName'],
                    'volume'      => $prod['v'],  'count'       => $prod['c'],
                    'revenue'     => $prod['rv'], 'points'      => $prod['pt'],
                    'clientCount' => $prod['cl'],
                    'monthly'     => $prod['monthly'],
                    'programs'    => array_values($programs),
                ];
            }
            $result[] = [
                'fcId'        => $fc['fcId'],
                'fcName'      => $fc['fcName'],
                'volume'      => $fc['v'],  'count'       => $fc['c'],
                'revenue'     => $fc['rv'], 'points'      => $fc['pt'],
                'clientCount' => $fc['cl'],
                'monthly'     => $fc['monthly'],
                'products'    => array_values($products),
            ];

            $grand['volume']      += $fc['v'];
            $grand['count']       += $fc['c'];
            $grand['revenue']     += $fc['rv'];
            $grand['points']      += $fc['pt'];
            $grand['clientCount'] += $fc['cl'];
            foreach ($fc['monthly'] as $mo => $vals) {
                foreach ($vals as $k => $val) {
                    $grand['monthly'][$mo][$k] = ($grand['monthly'][$mo][$k] ?? 0) + $val;
                }
            }
        }

        // Products available in this period (for filter)
        $allProducts = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('product as p', 'p.id', '=', 'co.product')
            ->whereBetween('t.dateMonth', [$from, $to])
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->select('p.id', 'p.name')
            ->distinct()
            ->orderBy('p.name')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]);

        return response()->json([
            'period'      => ['from' => $from, 'to' => $to, 'months' => $this->assembler->nonEmptyMonths($months, $grand)],
            'rows'        => $result,
            'grandTotals' => $grand,
            'products'    => $allProducts->values(),
        ]);
    }

    /**
     * GET /admin/reports/sales-matrix/quarterly
     *
     * Матрица продаж в разрезе Продукт → Программа с разбивкой по месяцам
     * за произвольный период (квартал / диапазон).
     *
     * Params: from (Y-m), to (Y-m), products[] (int, optional)
     */
    public function quarterlyMatrix(Request $request): JsonResponse
    {
        $params = $request->validate([
            'from'        => 'required|date_format:Y-m',
            'to'          => 'required|date_format:Y-m',
            'products'    => 'nullable|array',
            'products.*'  => 'integer',
            'suppliers'   => 'nullable|array',
            'suppliers.*' => 'string|max:200',
            // Доп. фильтр по дате ПРОГНОЗА НАЧИСЛЕНИЙ (accrual_forecast).
            'fcFrom'      => 'nullable|date',
            'fcTo'        => 'nullable|date',
        ]);

        $from   = $params['from'];
        $to     = $params['to'];
        $fcFrom = $params['fcFrom'] ?? null;
        $fcTo   = $params['fcTo']   ?? null;
        $months = $this->matrixSupport->monthRange($from, $to);

        // Вычисляем границы периода по openDate (исключительная правая граница)
        [$ty, $tm] = explode('-', $to);
        $tm = (int) $tm + 1;
        if ($tm > 12) { $tm = 1; $ty = (int) $ty + 1; }
        $toExclusive = sprintf('%04d-%02d-01', (int) $ty, $tm);

        // Базовый builder: только активированные контракты по дате активации.
        // Контракты, по которым УЖЕ ЕСТЬ транзакция (напр. InSmart), сюда не
        // попадают — они учитываются в «Факт» (правило Лены 22.06.2026).
        $base = fn () => DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->where('co.status', 1)
            ->whereRaw('co."openDate" IS NOT NULL')
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('transaction as tx')
                ->whereColumn('tx.contract', 'co.id')->whereNull('tx.deletedAt'))
            ->whereRaw('co."openDate"::date >= ?', [$from . '-01'])
            ->whereRaw('co."openDate"::date < ?',  [$toExclusive])
            ->when($fcFrom, fn ($q) => $q->whereDate('co.accrual_forecast', '>=', $fcFrom))
            ->when($fcTo, fn ($q) => $q->whereDate('co.accrual_forecast', '<=', $fcTo))
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers'])
            )
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products'])
            );

        // Период для группировки: YYYY-MM из даты активации
        $periodExpr = DB::raw('TO_CHAR(DATE_TRUNC(\'month\', co."openDate"::date), \'YYYY-MM\') as period_month');
        $periodRaw  = DB::raw('DATE_TRUNC(\'month\', co."openDate"::date)');

        // «Активировано» — по этим контрактам нет транзакций в платформе,
        // поэтому revenue/points НЕ из транзакций: считаем прогноз из контракта
        // (injectInWorkPoints по openDate ниже). Здесь revenue/points = 0.
        // Конвертация в RUB — по management_currency_rate (rateExpr).
        $rows = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->select([
                'p.id   as product_id',
                'p.name as product_name',
                'pg.id   as program_id',
                'pg.name as program_name',
                $periodExpr,
                DB::raw('SUM(COALESCE(co.ammount, 0) * '.$this->matrixSupport->rateExpr('openDate').') as volume'),
                DB::raw('COUNT(DISTINCT co.id)                              as cnt'),
                DB::raw('0                                                  as revenue'),
                DB::raw('0                                                  as points'),
                DB::raw('COUNT(DISTINCT co.client)                          as client_count'),
                DB::raw('COUNT(DISTINCT co.consultant)                      as fc_count'),
            ])
            ->groupBy('p.id', 'p.name', 'pg.id', 'pg.name', $periodRaw)
            ->orderBy('p.name')
            ->orderBy('pg.name')
            ->orderByRaw('DATE_TRUNC(\'month\', co."openDate"::date)')
            ->get();

        $productMap = [];
        $grand      = ['volume' => 0, 'count' => 0, 'revenue' => 0,
                       'points' => 0, 'clientCount' => 0, 'monthly' => []];

        foreach ($rows as $r) {
            $pid  = $r->product_id;
            $pgid = $r->program_id;
            $mo   = $r->period_month;

            $v  = round((float) $r->volume,  2);
            $c  = (int)         $r->cnt;
            $rv = round((float) $r->revenue, 2);
            $pt = round((float) $r->points,  4);
            $cl = (int)         $r->client_count;
            $fc = (int)         $r->fc_count;
            $vals = ['volume' => $v, 'count' => $c, 'revenue' => $rv,
                     'points' => $pt, 'clientCount' => $cl];

            if (! isset($productMap[$pid])) {
                $productMap[$pid] = [
                    'productId' => $pid, 'productName' => $r->product_name,
                    'volume' => 0, 'count' => 0, 'revenue' => 0, 'points' => 0, 'clientCount' => 0,
                    'monthly' => [], 'programs' => [],
                ];
            }
            if (! isset($productMap[$pid]['programs'][$pgid])) {
                $productMap[$pid]['programs'][$pgid] = [
                    'programId' => $pgid, 'programName' => $r->program_name,
                    'volume' => 0, 'count' => 0, 'revenue' => 0, 'points' => 0, 'clientCount' => 0,
                    'monthly' => [],
                ];
            }

            $productMap[$pid]['programs'][$pgid]['monthly'][$mo] = array_merge($vals, [
                'fcCount'  => $fc,
                'avgCheck' => $c > 0 ? round($v / $c, 2) : 0,
            ]);
            foreach ($vals as $k => $val) {
                $productMap[$pid]['programs'][$pgid][$k] += $val;
            }

            foreach ($vals as $k => $val) {
                $productMap[$pid]['monthly'][$mo][$k]  = ($productMap[$pid]['monthly'][$mo][$k] ?? 0) + $val;
                $productMap[$pid][$k]                 += $val;
                $grand['monthly'][$mo][$k]             = ($grand['monthly'][$mo][$k] ?? 0) + $val;
                $grand[$k]                            += $val;
            }
        }

        // FC distinct по (продукт × месяц) — нельзя суммировать программные значения
        $fcMonthlyRows = $base()
            ->select([
                'co.product as product_id',
                $periodExpr,
                DB::raw('COUNT(DISTINCT co.consultant) as fc_count'),
            ])
            ->groupBy('co.product', $periodRaw)
            ->get();

        $fcMonthlyIdx = [];
        foreach ($fcMonthlyRows as $r) {
            $fcMonthlyIdx[$r->product_id][$r->period_month] = (int) $r->fc_count;
        }

        // Grand monthly fcCount
        $grandFcMonthly = $base()
            ->select([$periodExpr, DB::raw('COUNT(DISTINCT co.consultant) as fc_count')])
            ->groupBy($periodRaw)
            ->get()
            ->pluck('fc_count', 'period_month');

        // FC distinct по продукту итого
        $fcCounts = $base()
            ->select('co.product as product_id', DB::raw('COUNT(DISTINCT co.consultant) as fc_count'))
            ->groupBy('co.product')
            ->get()
            ->keyBy('product_id');

        $grand['fcCount'] = (int) $base()->distinct()->count('co.consultant');

        // Клиенты — distinct (как ФК): один клиент может купить несколько
        // продуктов, поэтому суммировать client_count по ячейкам нельзя —
        // итоги задвоятся. Считаем уникальных клиентов отдельно.
        $clMonthlyIdx = [];
        foreach ($base()->select(['co.product as product_id', $periodExpr, DB::raw('COUNT(DISTINCT co.client) as cl_count')])
            ->groupBy('co.product', $periodRaw)->get() as $r) {
            $clMonthlyIdx[$r->product_id][$r->period_month] = (int) $r->cl_count;
        }
        $grandClMonthly = $base()
            ->select([$periodExpr, DB::raw('COUNT(DISTINCT co.client) as cl_count')])
            ->groupBy($periodRaw)->get()->pluck('cl_count', 'period_month');
        $clCounts = $base()
            ->select('co.product as product_id', DB::raw('COUNT(DISTINCT co.client) as cl_count'))
            ->groupBy('co.product')->get()->keyBy('product_id');
        $grand['clientCount'] = (int) $base()->distinct()->count('co.client');

        // Производные поля: avgCheck и fcCount на уровне продукта/гранда
        foreach ($productMap as $pid => &$prod) {
            $prod['avgCheck'] = $prod['count'] > 0 ? round($prod['volume'] / $prod['count'], 2) : 0;
            foreach ($prod['monthly'] as $mo => &$mv) {
                $mv['avgCheck']    = $mv['count'] > 0 ? round($mv['volume'] / $mv['count'], 2) : 0;
                $mv['fcCount']     = $fcMonthlyIdx[$pid][$mo] ?? 0;
                $mv['clientCount'] = $clMonthlyIdx[$pid][$mo] ?? 0;
            }
            unset($mv);
            foreach ($prod['programs'] as &$prog) {
                $prog['avgCheck'] = $prog['count'] > 0 ? round($prog['volume'] / $prog['count'], 2) : 0;
            }
            unset($prog);
        }
        unset($prod);

        $grand['avgCheck'] = $grand['count'] > 0 ? round($grand['volume'] / $grand['count'], 2) : 0;
        foreach ($grand['monthly'] as $mo => &$gv) {
            $gv['avgCheck']    = $gv['count'] > 0 ? round($gv['volume'] / $gv['count'], 2) : 0;
            $gv['fcCount']     = (int) ($grandFcMonthly[$mo] ?? 0);
            $gv['clientCount'] = (int) ($grandClMonthly[$mo] ?? 0);
        }
        unset($gv);

        $result = [];
        foreach ($productMap as $pid => $prod) {
            $prod['fcCount']     = (int) ($fcCounts[$pid]->fc_count ?? 0);
            $prod['clientCount'] = (int) ($clCounts[$pid]->cl_count ?? 0);
            $prod['programs']    = array_values($prod['programs']);
            $result[]            = $prod;
        }

        $allSuppliers = $base()
            ->whereNotNull('pg.providerName')
            ->distinct()
            ->orderBy('pg.providerName')
            ->pluck('pg.providerName');

        $allProducts = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->select('p.id', 'p.name')
            ->distinct()
            ->orderBy('p.name')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]);

        $payload = [
            'period'      => ['from' => $from, 'to' => $to, 'months' => $this->assembler->nonEmptyMonths($months, $grand)],
            'rows'        => $result,
            'grandTotals' => $grand,
            'suppliers'   => $allSuppliers->values(),
            'products'    => $allProducts->values(),
        ];

        // Выручка/баллы ячеек — прогноз из контракта (транзакций нет), период
        // — месяц активации (openDate).
        $this->assembler->injectInWorkPoints($base, $payload, 'openDate');

        // Тултипы «Активировано» (объём/кол-во/выручка/баллы) — прогноз по месяцу
        // ПРОГНОЗА НАЧИСЛЕНИЙ (accrual_forecast). Один breakdown в cell.forecast,
        // фронт показывает его на всех 4 метриках.
        $this->assembler->injectForecastBreakdown($base, $payload, 'openDate', 'accrual_forecast', 'forecast');

        return response()->json($payload);
    }

    /**
     * GET /admin/reports/sales-matrix/inwork
     *
     * «В работе»: все НЕзавершённые/неактивированные контракты, сгруппированные
     * по МЕСЯЦУ СОЗДАНИЯ (createDate). Исключаются статусы 1 (Активирован),
     * 6 (Закрыто нереализовано), 8 (Закрыто), 10 (Лапсирован).
     * Визуально и по метрикам совпадает с «Активировано» (quarterlyMatrix),
     * плюс дополнительный фильтр по дате прогноза активации (fcFrom/fcTo, Y-m).
     */
    public function inWorkMatrix(Request $request): JsonResponse
    {
        $params = $request->validate([
            'from'        => 'required|date_format:Y-m',
            'to'          => 'required|date_format:Y-m',
            'products'    => 'nullable|array',
            'products.*'  => 'integer',
            'suppliers'   => 'nullable|array',
            'suppliers.*' => 'string|max:200',
            'fcFrom'      => 'nullable|date',
            'fcTo'        => 'nullable|date',
        ]);

        $from   = $params['from'];
        $to     = $params['to'];
        $months = $this->matrixSupport->monthRange($from, $to);

        $toExclusive = $this->matrixSupport->monthExclusiveStart($to);

        // Доп. фильтр по прогнозу активации (SmartRangeFilter — даты Y-m-d,
        // границы независимы и инклюзивны по дню).
        $fcFrom = $params['fcFrom'] ?? null;
        $fcTo   = $params['fcTo']   ?? null;

        $EXCLUDED = [1, 6, 8, 9, 10]; // Активирован, Закрыто нереализ., Закрыто, Возврат, Лапсирован

        // Базовый builder: контракты «в работе» по дате создания.
        $base = fn () => DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereNotIn('co.status', $EXCLUDED)
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereRaw('co."createDate" IS NOT NULL')
            ->whereRaw('co."createDate"::date >= ?', [$from . '-01'])
            ->whereRaw('co."createDate"::date < ?',  [$toExclusive])
            ->when($fcFrom, fn ($q) => $q->whereDate('co.activation_forecast', '>=', $fcFrom))
            ->when($fcTo, fn ($q) => $q->whereDate('co.activation_forecast', '<=', $fcTo))
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers'])
            )
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products'])
            );

        // Период группировки: YYYY-MM из даты создания.
        $periodExpr = DB::raw('TO_CHAR(DATE_TRUNC(\'month\', co."createDate"::date), \'YYYY-MM\') as period_month');
        $periodRaw  = DB::raw('DATE_TRUNC(\'month\', co."createDate"::date)');

        // «В работе» — деньги берём из суммы контракта (Объём), транзакции НЕ
        // используем. Конвертация в RUB — по management_currency_rate (rateExpr).
        $rows = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->select([
                'p.id   as product_id',
                'p.name as product_name',
                'pg.id   as program_id',
                'pg.name as program_name',
                $periodExpr,
                DB::raw('SUM(COALESCE(co.ammount, 0) * '.$this->matrixSupport->rateExpr('createDate').') as volume'),
                DB::raw('COUNT(DISTINCT co.id)             as cnt'),
                DB::raw('0                                 as revenue'),
                DB::raw('0                                 as points'),
                DB::raw('COUNT(DISTINCT co.client)          as client_count'),
                DB::raw('COUNT(DISTINCT co.consultant)      as fc_count'),
            ])
            ->groupBy('p.id', 'p.name', 'pg.id', 'pg.name', $periodRaw)
            ->orderBy('p.name')->orderBy('pg.name')
            ->orderByRaw('DATE_TRUNC(\'month\', co."createDate"::date)')
            ->get();

        $assembled = $this->assembler->assembleMatrix($rows, $base, $periodExpr, $periodRaw, $from, $to, $months);

        // Баллы (ЛП) — расчёт из контракта по методике программы
        // (CommissionCalculator::computePoints), а НЕ из транзакций.
        // amountNoVat = сумма×курс / (1+НДС); %ДС — по тарифной сетке на
        // дату создания. Это прогноз баллов: фактические начислятся при
        // активации, но для пайплайна «В работе» оцениваем из контракта.
        $this->assembler->injectInWorkPoints($base, $assembled);

        // Тултипы «В работе»:
        //  - объём/кол-во → по месяцу ПРОГНОЗА АКТИВАЦИИ (activation_forecast);
        //  - выручка/баллы → по месяцу ПРОГНОЗА НАЧИСЛЕНИЙ (accrual_forecast).
        $this->assembler->injectForecastBreakdown($base, $assembled, 'createDate', 'activation_forecast', 'forecast');
        $this->assembler->injectForecastBreakdown($base, $assembled, 'createDate', 'accrual_forecast', 'forecastAccrual');

        return response()->json($assembled);
    }



    /**
     * GET /admin/reports/sales-matrix/forecast
     *
     * Pipeline-контракты, сгруппированные по прогнозной дате активации
     * (activation_forecast). Контракты без даты попадают в бакет NULL_KEY
     * и показываются всегда (даже при заданном периоде).
     *
     * Params:
     *   suppliers[]  str  (optional)
     *   products[]   int  (optional)
     *   statuses[]   int  (optional, subset of [2,3]; default both)
     *   from, to     Y-m  (optional; фильтр по месяцу прогнозной активации)
     */
    public function forecastMatrix(Request $request): JsonResponse
    {
        $params = $request->validate([
            'suppliers'   => 'nullable|array',
            'suppliers.*' => 'string|max:200',
            'products'    => 'nullable|array',
            'products.*'  => 'integer',
            'statuses'    => 'nullable|array',
            'statuses.*'  => 'integer|in:2,3',
            'from'        => 'nullable|date_format:Y-m',
            'to'          => 'nullable|date_format:Y-m',
        ]);

        // 2 = Сбор документов, 3 = Комплайнс
        $NULL_KEY = '__no_date__';
        $statuses = ! empty($params['statuses'])
            ? array_values(array_unique($params['statuses']))
            : [2, 3];

        // Границы периода по activation_forecast (правая граница исключительная)
        $from = $params['from'] ?? null;
        $to   = $params['to']   ?? null;
        $hasPeriod   = $from && $to;
        $toExclusive = null;
        if ($hasPeriod) {
            [$ty, $tm] = explode('-', $to);
            $tm = (int) $tm + 1;
            if ($tm > 12) { $tm = 1; $ty = (int) $ty + 1; }
            $toExclusive = sprintf('%04d-%02d-01', (int) $ty, $tm);
        }

        $base = fn () => DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereIn('co.status', $statuses)
            ->whereRaw('co."deletedAt" IS NULL')
            // Период применяется только к датированным; без даты — показываем всегда
            ->when($hasPeriod, fn ($q) =>
                $q->where(function ($w) use ($from, $toExclusive) {
                    $w->whereNull('co.activation_forecast')
                      ->orWhere(function ($w2) use ($from, $toExclusive) {
                          $w2->whereRaw('co.activation_forecast >= ?', [$from . '-01'])
                             ->whereRaw('co.activation_forecast <  ?', [$toExclusive]);
                      });
                })
            )
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers'])
            )
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products'])
            );

        $periodExpr  = DB::raw("TO_CHAR(DATE_TRUNC('month', co.activation_forecast), 'YYYY-MM') as period_month");
        $periodTrunc = DB::raw("DATE_TRUNC('month', co.activation_forecast)");

        $rows = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->leftJoin('management_currency_rate as mcr', function ($j) {
                $j->on('mcr.currency', '=', 'co.currency')
                  ->whereRaw("mcr.date = DATE_TRUNC('month', COALESCE(co.activation_forecast, NOW()))::date");
            })
            ->select([
                'p.id   as product_id',
                'p.name as product_name',
                'pg.id   as program_id',
                'pg.name as program_name',
                $periodExpr,
                DB::raw("SUM(COALESCE(co.ammount, 0) * COALESCE(mcr.rate, 1)) as volume"),
                DB::raw('COUNT(DISTINCT co.id)         as cnt'),
                DB::raw('COUNT(DISTINCT co.client)     as client_count'),
                DB::raw('COUNT(DISTINCT co.consultant) as fc_count'),
            ])
            ->groupBy('p.id', 'p.name', 'pg.id', 'pg.name', $periodTrunc)
            ->orderBy('p.name')
            ->orderBy('pg.name')
            ->orderByRaw("DATE_TRUNC('month', co.activation_forecast) NULLS LAST")
            ->get();

        $months     = [];
        $productMap = [];
        $grand      = ['volume' => 0, 'count' => 0, 'clientCount' => 0, 'monthly' => []];

        foreach ($rows as $r) {
            $pid  = $r->product_id;
            $pgid = $r->program_id;
            $mo   = $r->period_month ?? $NULL_KEY;

            if ($mo !== $NULL_KEY && ! in_array($mo, $months, true)) {
                $months[] = $mo;
            }

            $v  = round((float) $r->volume, 2);
            $c  = (int) $r->cnt;
            $cl = (int) $r->client_count;
            $fc = (int) $r->fc_count;
            $vals = ['volume' => $v, 'count' => $c, 'clientCount' => $cl];

            if (! isset($productMap[$pid])) {
                $productMap[$pid] = [
                    'productId'   => $pid,
                    'productName' => $r->product_name,
                    'volume'      => 0, 'count' => 0, 'clientCount' => 0,
                    'monthly'     => [],
                    'programs'    => [],
                ];
            }
            if (! isset($productMap[$pid]['programs'][$pgid])) {
                $productMap[$pid]['programs'][$pgid] = [
                    'programId'   => $pgid,
                    'programName' => $r->program_name,
                    'volume'      => 0, 'count' => 0, 'clientCount' => 0,
                    'monthly'     => [],
                ];
            }

            // Программа: одна строка = одна программа×месяц
            $productMap[$pid]['programs'][$pgid]['monthly'][$mo] = array_merge($vals, [
                'fcCount'  => $fc,
                'avgCheck' => $c > 0 ? round($v / $c, 2) : 0,
            ]);
            foreach ($vals as $k => $val) {
                $productMap[$pid]['programs'][$pgid][$k] += $val;
            }

            // Продукт и гранд: аккумулируем по месяцам (несколько программ в одном месяце)
            foreach ($vals as $k => $val) {
                $productMap[$pid]['monthly'][$mo][$k]  = ($productMap[$pid]['monthly'][$mo][$k] ?? 0) + $val;
                $productMap[$pid][$k]                 += $val;
                $grand['monthly'][$mo][$k]             = ($grand['monthly'][$mo][$k] ?? 0) + $val;
                $grand[$k]                            += $val;
            }
        }

        // FC distinct по (продукт × месяц) — суммировать программные значения нельзя
        $fcMonthlyIdx = [];
        foreach ($base()->select(['co.product as product_id', $periodExpr, DB::raw('COUNT(DISTINCT co.consultant) as fc_count')])
                     ->groupBy('co.product', $periodTrunc)->get() as $r) {
            $fcMonthlyIdx[$r->product_id][$r->period_month ?? $NULL_KEY] = (int) $r->fc_count;
        }

        $grandFcMonthly = [];
        foreach ($base()->select([$periodExpr, DB::raw('COUNT(DISTINCT co.consultant) as fc_count')])
                     ->groupBy($periodTrunc)->get() as $r) {
            $grandFcMonthly[$r->period_month ?? $NULL_KEY] = (int) $r->fc_count;
        }

        $fcCounts = $base()
            ->select('co.product as product_id', DB::raw('COUNT(DISTINCT co.consultant) as fc_count'))
            ->groupBy('co.product')
            ->get()
            ->keyBy('product_id');

        $grand['fcCount'] = (int) $base()->distinct()->count('co.consultant');

        // Производные поля: avgCheck + fcCount на уровне продукта/гранда
        foreach ($productMap as $pid => &$prod) {
            $prod['avgCheck'] = $prod['count'] > 0 ? round($prod['volume'] / $prod['count'], 2) : 0;
            $prod['fcCount']  = (int) ($fcCounts[$pid]->fc_count ?? 0);
            foreach ($prod['monthly'] as $mo => &$mv) {
                $mv['avgCheck'] = ($mv['count'] ?? 0) > 0 ? round($mv['volume'] / $mv['count'], 2) : 0;
                $mv['fcCount']  = $fcMonthlyIdx[$pid][$mo] ?? 0;
            }
            unset($mv);
            foreach ($prod['programs'] as &$prog) {
                $prog['avgCheck'] = $prog['count'] > 0 ? round($prog['volume'] / $prog['count'], 2) : 0;
            }
            unset($prog);
            $prod['programs'] = array_values($prod['programs']);
        }
        unset($prod);

        $grand['avgCheck'] = $grand['count'] > 0 ? round($grand['volume'] / $grand['count'], 2) : 0;
        foreach ($grand['monthly'] as $mo => &$gv) {
            $gv['avgCheck'] = ($gv['count'] ?? 0) > 0 ? round($gv['volume'] / $gv['count'], 2) : 0;
            $gv['fcCount']  = $grandFcMonthly[$mo] ?? 0;
        }
        unset($gv);

        // Статистика без даты
        $noDateCount = $base()->whereNull('co.activation_forecast')->count();

        // Колонки месяцев: при заданном периоде показываем весь диапазон (включая пустые)
        if ($hasPeriod) {
            $months = $this->matrixSupport->monthRange($from, $to);
        } else {
            sort($months);
        }
        if ($noDateCount > 0) {
            $months[] = $NULL_KEY;
        }

        $allSuppliers = $base()
            ->whereNotNull('pg.providerName')
            ->distinct()
            ->orderBy('pg.providerName')
            ->pluck('pg.providerName');

        $allProducts = $base()
            ->join('product as p2', 'p2.id', '=', 'co.product')
            ->select('p2.id', 'p2.name')
            ->distinct()
            ->orderBy('p2.name')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]);

        return response()->json([
            'nullKey'     => $NULL_KEY,
            'noDateCount' => $noDateCount,
            'months'      => $months,
            'rows'        => array_values($productMap),
            'grandTotals' => $grand,
            'suppliers'   => $allSuppliers->values(),
            'products'    => $allProducts->values(),
        ]);
    }

    /**
     * GET /admin/reports/sales-matrix/fact
     *
     * Финансовый факт: все транзакции и пополнения за период, сгруппированные
     * по продукту → программе с разбивкой по месяцу транзакции (t.dateMonth).
     * В отличие от /period (активированные контракты по дате активации), здесь
     * учитываются все платежи, включая ежемесячные взносы рассрочки и пополнения.
     * Структура ответа совпадает с quarterlyMatrix — фронт рендерит той же таблицей.
     *
     * Params: from (Y-m), to (Y-m), products[] (int, optional), suppliers[] (str, optional)
     */
    public function factMatrix(Request $request): JsonResponse
    {
        $params = $request->validate([
            'from'        => 'required|date_format:Y-m',
            'to'          => 'required|date_format:Y-m',
            'products'    => 'nullable|array',
            'products.*'  => 'integer',
            'suppliers'   => 'nullable|array',
            'suppliers.*' => 'string|max:200',
        ]);

        $from   = $params['from'];
        $to     = $params['to'];
        $months = $this->matrixSupport->monthRange($from, $to);

        // Базовый builder: транзакции внутри периода по месяцу транзакции (dateMonth).
        // amountRUB/netRevenueRUB уже в рублях — конвертация валют не нужна.
        $base = fn () => DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereBetween('t.dateMonth', [$from, $to])
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers'])
            )
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products'])
            );

        $rows = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->select([
                'p.id   as product_id',
                'p.name as product_name',
                'pg.id   as program_id',
                'pg.name as program_name',
                't.dateMonth as period_month',
                DB::raw('SUM(COALESCE(t."amountRUB", 0))      as volume'),
                DB::raw('COUNT(DISTINCT t.id)                 as cnt'),
                DB::raw('SUM(COALESCE(t."commissionsAmountRUB", 0))  as revenue'),
                DB::raw('SUM(COALESCE(t."personalVolume", 0)) as points'),
                DB::raw('COUNT(DISTINCT co.client)            as client_count'),
                DB::raw('COUNT(DISTINCT co.consultant)        as fc_count'),
            ])
            ->groupBy('p.id', 'p.name', 'pg.id', 'pg.name', 't.dateMonth')
            ->orderBy('p.name')
            ->orderBy('pg.name')
            ->orderBy('t.dateMonth')
            ->get();

        $productMap = [];
        $grand      = ['volume' => 0, 'count' => 0, 'revenue' => 0,
                       'points' => 0, 'clientCount' => 0, 'monthly' => []];

        foreach ($rows as $r) {
            $pid  = $r->product_id;
            $pgid = $r->program_id;
            $mo   = $r->period_month;

            $v  = round((float) $r->volume,  2);
            $c  = (int)         $r->cnt;
            $rv = round((float) $r->revenue, 2);
            $pt = round((float) $r->points,  4);
            $cl = (int)         $r->client_count;
            $fc = (int)         $r->fc_count;
            $vals = ['volume' => $v, 'count' => $c, 'revenue' => $rv,
                     'points' => $pt, 'clientCount' => $cl];

            if (! isset($productMap[$pid])) {
                $productMap[$pid] = [
                    'productId' => $pid, 'productName' => $r->product_name,
                    'volume' => 0, 'count' => 0, 'revenue' => 0, 'points' => 0, 'clientCount' => 0,
                    'monthly' => [], 'programs' => [],
                ];
            }
            if (! isset($productMap[$pid]['programs'][$pgid])) {
                $productMap[$pid]['programs'][$pgid] = [
                    'programId' => $pgid, 'programName' => $r->program_name,
                    'volume' => 0, 'count' => 0, 'revenue' => 0, 'points' => 0, 'clientCount' => 0,
                    'monthly' => [],
                ];
            }

            $productMap[$pid]['programs'][$pgid]['monthly'][$mo] = array_merge($vals, [
                'fcCount'  => $fc,
                'avgCheck' => $c > 0 ? round($v / $c, 2) : 0,
            ]);
            foreach ($vals as $k => $val) {
                $productMap[$pid]['programs'][$pgid][$k] += $val;
            }

            foreach ($vals as $k => $val) {
                $productMap[$pid]['monthly'][$mo][$k]  = ($productMap[$pid]['monthly'][$mo][$k] ?? 0) + $val;
                $productMap[$pid][$k]                 += $val;
                $grand['monthly'][$mo][$k]             = ($grand['monthly'][$mo][$k] ?? 0) + $val;
                $grand[$k]                            += $val;
            }
        }

        // FC distinct по (продукт × месяц) — нельзя суммировать программные значения
        $fcMonthlyRows = $base()
            ->select([
                'co.product as product_id',
                't.dateMonth as period_month',
                DB::raw('COUNT(DISTINCT co.consultant) as fc_count'),
            ])
            ->groupBy('co.product', 't.dateMonth')
            ->get();

        $fcMonthlyIdx = [];
        foreach ($fcMonthlyRows as $r) {
            $fcMonthlyIdx[$r->product_id][$r->period_month] = (int) $r->fc_count;
        }

        // Grand monthly fcCount
        $grandFcMonthly = $base()
            ->select(['t.dateMonth as period_month', DB::raw('COUNT(DISTINCT co.consultant) as fc_count')])
            ->groupBy('t.dateMonth')
            ->get()
            ->pluck('fc_count', 'period_month');

        // FC distinct по продукту итого
        $fcCounts = $base()
            ->select('co.product as product_id', DB::raw('COUNT(DISTINCT co.consultant) as fc_count'))
            ->groupBy('co.product')
            ->get()
            ->keyBy('product_id');

        $grand['fcCount'] = (int) $base()->distinct()->count('co.consultant');

        // Клиенты — distinct (как ФК): один клиент может купить несколько
        // продуктов, поэтому суммировать client_count по ячейкам нельзя —
        // итоги задвоятся. В factMatrix период — t.dateMonth (не openDate).
        $clMonthlyIdx = [];
        foreach ($base()->select(['co.product as product_id', 't.dateMonth as period_month', DB::raw('COUNT(DISTINCT co.client) as cl_count')])
            ->groupBy('co.product', 't.dateMonth')->get() as $r) {
            $clMonthlyIdx[$r->product_id][$r->period_month] = (int) $r->cl_count;
        }
        $grandClMonthly = $base()
            ->select(['t.dateMonth as period_month', DB::raw('COUNT(DISTINCT co.client) as cl_count')])
            ->groupBy('t.dateMonth')->get()->pluck('cl_count', 'period_month');
        $clCounts = $base()
            ->select('co.product as product_id', DB::raw('COUNT(DISTINCT co.client) as cl_count'))
            ->groupBy('co.product')->get()->keyBy('product_id');
        $grand['clientCount'] = (int) $base()->distinct()->count('co.client');

        // Производные поля: avgCheck и fcCount на уровне продукта/гранда
        foreach ($productMap as $pid => &$prod) {
            $prod['avgCheck'] = $prod['count'] > 0 ? round($prod['volume'] / $prod['count'], 2) : 0;
            foreach ($prod['monthly'] as $mo => &$mv) {
                $mv['avgCheck']    = $mv['count'] > 0 ? round($mv['volume'] / $mv['count'], 2) : 0;
                $mv['fcCount']     = $fcMonthlyIdx[$pid][$mo] ?? 0;
                $mv['clientCount'] = $clMonthlyIdx[$pid][$mo] ?? 0;
            }
            unset($mv);
            foreach ($prod['programs'] as &$prog) {
                $prog['avgCheck'] = $prog['count'] > 0 ? round($prog['volume'] / $prog['count'], 2) : 0;
            }
            unset($prog);
        }
        unset($prod);

        $grand['avgCheck'] = $grand['count'] > 0 ? round($grand['volume'] / $grand['count'], 2) : 0;
        foreach ($grand['monthly'] as $mo => &$gv) {
            $gv['avgCheck']    = $gv['count'] > 0 ? round($gv['volume'] / $gv['count'], 2) : 0;
            $gv['fcCount']     = (int) ($grandFcMonthly[$mo] ?? 0);
            $gv['clientCount'] = (int) ($grandClMonthly[$mo] ?? 0);
        }
        unset($gv);

        $result = [];
        foreach ($productMap as $pid => $prod) {
            $prod['fcCount']     = (int) ($fcCounts[$pid]->fc_count ?? 0);
            $prod['clientCount'] = (int) ($clCounts[$pid]->cl_count ?? 0);
            $prod['programs']    = array_values($prod['programs']);
            $result[]            = $prod;
        }

        $allSuppliers = $base()
            ->whereNotNull('pg.providerName')
            ->distinct()
            ->orderBy('pg.providerName')
            ->pluck('pg.providerName');

        $allProducts = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->select('p.id', 'p.name')
            ->distinct()
            ->orderBy('p.name')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]);

        // Детализация для тултипа столбца «Итого» в режиме «Факт»: список
        // транзакций (№ контракта + id транзакции + сумма + месяц + клиент)
        // по каждому продукту и общий — Лена сверяет «Факт» по конкретике.
        $detailByProduct = [];
        $allDetail = [];
        foreach ($base()
            ->select([
                'co.product as pid',
                'co.number as contract_number',
                't.id as tx_id',
                DB::raw('COALESCE(t."amountRUB", 0) as amount'),
                't.dateMonth as month',
                'co.clientName as client_name',
            ])
            ->orderBy('co.product')->orderBy('t.dateMonth')->orderBy('t.id')
            ->get() as $d) {
            $row = [
                'contractNumber' => $d->contract_number,
                'txId'           => (int) $d->tx_id,
                'amount'         => round((float) $d->amount, 2),
                'month'          => $d->month,
                'clientName'     => $d->client_name,
            ];
            $detailByProduct[$d->pid][] = $row;
            $allDetail[] = $row;
        }
        foreach ($result as &$prodRow) {
            $prodRow['factDetail'] = $detailByProduct[$prodRow['productId']] ?? [];
        }
        unset($prodRow);
        $grand['factDetail'] = $allDetail;

        return response()->json([
            'period'      => ['from' => $from, 'to' => $to, 'months' => $this->assembler->nonEmptyMonths($months, $grand)],
            'rows'        => $result,
            'grandTotals' => $grand,
            'suppliers'   => $allSuppliers->values(),
            'products'    => $allProducts->values(),
        ]);
    }

    /**
     * GET /admin/reports/sales-matrix/total — режим «Итого».
     *
     * Строки = продукты (без программ). Каждая ячейка продукта = СУММА трёх
     * слоёв, каждый со своей группировкой по месяцу:
     *   - «Факт»          — транзакции по месяцу транзакции (t.dateMonth);
     *   - «Активировано»  — активированные контракты (status=1, без транзакций)
     *                       по месяцу активации (openDate);
     *   - «В работе»      — неактивированные контракты по месяцу создания.
     * Раскрытие продукта показывает эти 3 слоя (вместо программ).
     */
    public function totalMatrix(Request $request): JsonResponse
    {
        $params = $request->validate([
            'from'        => 'required|date_format:Y-m',
            'to'          => 'required|date_format:Y-m',
            'products'    => 'nullable|array',
            'products.*'  => 'integer',
            'suppliers'   => 'nullable|array',
            'suppliers.*' => 'string|max:200',
        ]);
        $from = $params['from'];
        $to   = $params['to'];
        $months = $this->matrixSupport->monthRange($from, $to);
        $toExclusive = $this->matrixSupport->monthExclusiveStart($to);
        $sup = $params['suppliers'] ?? [];
        $prod = $params['products'] ?? [];

        $applyFilters = function ($q) use ($sup, $prod) {
            return $q
                ->when(! empty($sup), fn ($qq) => $qq->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $sup))
                ->when(! empty($prod), fn ($qq) => $qq->whereIn('co.product', $prod));
        };

        // Слой «В работе» — неактивированные по дате создания.
        $inworkBase = fn () => $applyFilters(DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereNotIn('co.status', [1, 6, 8, 9, 10])
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereRaw('co."createDate"::date >= ?', [$from.'-01'])
            ->whereRaw('co."createDate"::date < ?', [$toExclusive]));

        // Слой «Активировано» — status=1, без транзакций, по дате активации.
        $actBase = fn () => $applyFilters(DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->where('co.status', 1)
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('transaction as tx')
                ->whereColumn('tx.contract', 'co.id')->whereNull('tx.deletedAt'))
            ->whereRaw('co."openDate"::date >= ?', [$from.'-01'])
            ->whereRaw('co."openDate"::date < ?', [$toExclusive]));

        // Слой «Факт» — транзакции по месяцу транзакции. Фильтры контракта
        // (deletedAt/openDate) — как в отдельном режиме «Факт» (factMatrix) и
        // партнёрском отчёте; без них «Итого» захватывал транзакции удалённых/
        // без openDate контрактов и слегка расходился с отдельными режимами.
        $factBase = fn () => $applyFilters(DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereRaw('t."deletedAt" IS NULL')
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereRaw('co."openDate" IS NOT NULL')
            ->where('t.dateMonth', '>=', $from)
            ->where('t.dateMonth', '<=', $to));

        $inwork = $this->assembler->totalLayerForecast($inworkBase, 'createDate');
        $activated = $this->assembler->totalLayerForecast($actBase, 'openDate');
        $fact = $this->assembler->totalLayerFact($factBase);

        // Имена продуктов (объединение всех слоёв).
        $pids = collect(array_keys($inwork + $activated + $fact));
        $names = $pids->isNotEmpty()
            ? DB::table('product')->whereIn('id', $pids)->pluck('name', 'id')
            : collect();

        $keys = ['volume', 'count', 'revenue', 'points', 'clientCount', 'fcCount'];
        $zero = array_fill_keys($keys, 0);
        $layers = [
            ['key' => 'fact', 'name' => '🟢 Факт', 'map' => $fact],
            ['key' => 'activated', 'name' => '🔵 Активировано', 'map' => $activated],
            ['key' => 'inwork', 'name' => '🟡 В работе', 'map' => $inwork],
        ];

        $result = [];
        $grand = array_merge($zero, ['monthly' => []]);

        foreach ($pids->sort()->values() as $pid) {
            $prodRow = array_merge($zero, [
                'productId' => $pid, 'productName' => $names[$pid] ?? ('#'.$pid),
                'monthly' => [], 'programs' => [],
            ]);
            foreach ($layers as $layer) {
                $lm = $layer['map'][$pid]['m'] ?? [];
                $layerRow = array_merge($zero, [
                    'programId' => $layer['key'], 'programName' => $layer['name'], 'monthly' => [],
                ]);
                foreach ($months as $mo) {
                    $cell = array_merge($zero, $lm[$mo] ?? []);
                    $cell['avgCheck'] = $cell['count'] > 0 ? round($cell['volume'] / $cell['count'], 2) : 0;
                    $layerRow['monthly'][$mo] = $cell;
                    foreach ($keys as $k) {
                        $layerRow[$k] += $cell[$k];
                        $prodRow['monthly'][$mo][$k] = ($prodRow['monthly'][$mo][$k] ?? 0) + $cell[$k];
                        $prodRow[$k] += $cell[$k];
                        $grand['monthly'][$mo][$k] = ($grand['monthly'][$mo][$k] ?? 0) + $cell[$k];
                        $grand[$k] += $cell[$k];
                    }
                }
                $layerRow['avgCheck'] = $layerRow['count'] > 0 ? round($layerRow['volume'] / $layerRow['count'], 2) : 0;
                $prodRow['programs'][] = $layerRow;
            }
            foreach ($months as $mo) {
                $mc = $prodRow['monthly'][$mo] ?? $zero;
                $prodRow['monthly'][$mo]['avgCheck'] = ($mc['count'] ?? 0) > 0 ? round($mc['volume'] / $mc['count'], 2) : 0;
            }
            $prodRow['avgCheck'] = $prodRow['count'] > 0 ? round($prodRow['volume'] / $prodRow['count'], 2) : 0;
            $result[] = $prodRow;
        }
        foreach ($months as $mo) {
            $gc = $grand['monthly'][$mo] ?? $zero;
            $grand['monthly'][$mo]['avgCheck'] = ($gc['count'] ?? 0) > 0 ? round($gc['volume'] / $gc['count'], 2) : 0;
        }
        $grand['avgCheck'] = $grand['count'] > 0 ? round($grand['volume'] / $grand['count'], 2) : 0;

        // Перезаписываем fcCount/clientCount УНИКАЛЬНЫМИ (union по слоям/месяцам).
        // В основном цикле они суммировались (как объём/кол-во) — для ФК/клиентов
        // это задвоение. Месячные ячейки СЛОЯ оставляем (там distinct в пределах
        // слой×продукт×месяц); перезаписываем итоги слоя, продукта, месяца, гранда
        // и продукт×месяц (union по слоям).
        $dc  = $this->assembler->totalDistinctCounts($inworkBase, $actBase, $factBase);
        $cnt = fn ($set) => is_array($set) ? count($set) : 0;
        foreach ($result as &$prodRow) {
            $pid = $prodRow['productId'];
            $prodRow['fcCount']     = $cnt($dc['p'][$pid]['fc'] ?? null);
            $prodRow['clientCount'] = $cnt($dc['p'][$pid]['cl'] ?? null);
            foreach ($prodRow['monthly'] as $m => &$mc) {
                $mc['fcCount']     = $cnt($dc['pm'][$pid][$m]['fc'] ?? null);
                $mc['clientCount'] = $cnt($dc['pm'][$pid][$m]['cl'] ?? null);
            }
            unset($mc);
            foreach ($prodRow['programs'] as &$layerRow) {
                $lk = $layerRow['programId']; // fact | activated | inwork
                $layerRow['fcCount']     = $cnt($dc['pl'][$pid][$lk]['fc'] ?? null);
                $layerRow['clientCount'] = $cnt($dc['pl'][$pid][$lk]['cl'] ?? null);
            }
            unset($layerRow);
        }
        unset($prodRow);
        $grand['fcCount']     = $cnt($dc['g']['fc'] ?? null);
        $grand['clientCount'] = $cnt($dc['g']['cl'] ?? null);
        foreach ($grand['monthly'] as $m => &$gm) {
            $gm['fcCount']     = $cnt($dc['mo'][$m]['fc'] ?? null);
            $gm['clientCount'] = $cnt($dc['mo'][$m]['cl'] ?? null);
        }
        unset($gm);

        // Списки для фильтров (по всем контрактам периода — создание/активация).
        $filtBase = fn () => $applyFilters(DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereRaw('COALESCE(co."openDate", co."createDate")::date >= ?', [$from.'-01'])
            ->whereRaw('COALESCE(co."openDate", co."createDate")::date < ?', [$toExclusive]));
        $allSuppliers = $filtBase()->whereNotNull('pg.providerName')->distinct()->orderBy('pg.providerName')->pluck('pg.providerName');
        $allProducts = $filtBase()->join('product as p', 'p.id', '=', 'co.product')
            ->select('p.id', 'p.name')->distinct()->orderBy('p.name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]);

        return response()->json([
            'period'      => ['from' => $from, 'to' => $to, 'months' => $this->assembler->nonEmptyMonths($months, $grand)],
            'rows'        => $result,
            'grandTotals' => $grand,
            'suppliers'   => $allSuppliers->values(),
            'products'    => $allProducts->values(),
        ]);
    }








    /**
     * Drill-down: список контрактов конкретной ячейки матрицы (продукт × месяц ×
     * режим), чтобы из клика по «Объёму» открыть контракты. Фильтры каждого
     * режима повторяют базовые builder-ы соответствующих методов матрицы:
     *   - inwork   → неактивированные по дате создания (createDate);
     *   - forecast → «Активировано»: status=1, без транзакций, по openDate;
     *   - fact     → контракты, у которых есть транзакция в месяце (t.dateMonth);
     *   - total    → объединение трёх слоёв (distinct по contract.id).
     */
    public function cellContracts(Request $request): JsonResponse
    {
        $params = $request->validate([
            'mode'        => 'required|in:inwork,forecast,fact,total',
            'month'       => 'required|date_format:Y-m',
            'product'     => 'required|integer',
            'program'     => 'nullable|integer',
            'suppliers'   => 'nullable|array',
            'suppliers.*' => 'string|max:200',
        ]);

        $mode      = $params['mode'];
        $month     = $params['month'];
        $productId = (int) $params['product'];
        $programId = $params['program'] ?? null;
        $suppliers = $params['suppliers'] ?? [];
        $monthStart     = $month.'-01';
        $monthExclusive = $this->matrixSupport->monthExclusiveStart($month);

        // Общие фильтры ячейки: пригвождаем продукт (+опц. программу) и поставщика.
        $common = function ($q) use ($suppliers, $productId, $programId) {
            return $q
                ->where('co.product', $productId)
                ->when($programId, fn ($qq) => $qq->where('co.program', $programId))
                ->when(! empty($suppliers), fn ($qq) =>
                    $qq->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $suppliers));
        };

        $inworkQ = fn () => $common(DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereNotIn('co.status', [1, 6, 8, 9, 10])
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereRaw('co."createDate"::date >= ?', [$monthStart])
            ->whereRaw('co."createDate"::date < ?',  [$monthExclusive]));

        $actQ = fn () => $common(DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->where('co.status', 1)
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('transaction as tx')
                ->whereColumn('tx.contract', 'co.id')->whereNull('tx.deletedAt'))
            ->whereRaw('co."openDate"::date >= ?', [$monthStart])
            ->whereRaw('co."openDate"::date < ?',  [$monthExclusive]));

        $factQ = fn () => $common(DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereNull('t.deletedAt')
            ->whereNull('co.deletedAt')
            ->whereNotNull('co.openDate')
            ->where('t.dateMonth', $month));

        $queries = match ($mode) {
            'inwork'   => [$inworkQ],
            'forecast' => [$actQ],
            'fact'     => [$factQ],
            'total'    => [$inworkQ, $actQ, $factQ],
            // Недостижимо: mode валидирован in:inwork,forecast,fact,total выше.
            // Ветка нужна PHPStan — validate() возвращает mixed.
            default    => abort(422, 'Некорректный режим'),
        };

        $byId = [];
        foreach ($queries as $q) {
            $rows = $q()
                ->leftJoin('currency as cur', 'cur.id', '=', 'co.currency')
                ->leftJoin('consultant as cons', 'cons.id', '=', 'co.consultant')
                ->leftJoin('contractStatus as st', 'st.id', '=', 'co.status')
                ->distinct()
                ->select([
                    'co.id', 'co.number', 'co.clientName',
                    DB::raw('cons."personName" as "consultantName"'),
                    DB::raw('COALESCE(co.ammount, 0) as amount'),
                    'cur.symbol as currency',
                    'st.name as status',
                ])
                ->orderBy('co.number')
                ->limit(2000)
                ->get();
            foreach ($rows as $r) {
                $byId[$r->id] = [
                    'id'         => (int) $r->id,
                    'number'     => $r->number,
                    'clientName' => $r->clientName,
                    'consultant' => $r->consultantName,
                    'amount'     => round((float) $r->amount, 2),
                    'currency'   => $r->currency,
                    'status'     => $r->status,
                ];
            }
        }

        $contracts = array_values($byId);
        usort($contracts, fn ($a, $b) => strnatcasecmp((string) $a['number'], (string) $b['number']));

        return response()->json(['contracts' => $contracts, 'count' => count($contracts)]);
    }
}
