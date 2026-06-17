<?php

namespace App\Http\Controllers\Api;

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
                DB::raw('SUM(COALESCE(t."netRevenueRUB", 0))  as revenue'),
                DB::raw('SUM(COALESCE(t."personalVolume", 0)) as points'),
                DB::raw('COUNT(DISTINCT co.consultant)         as fc_count'),
                DB::raw('COUNT(DISTINCT co.client)             as client_count'),
            ])
            ->groupBy('p.id', 'p.name', 'pg.id', 'pg.name', DB::raw('pg."providerName"'));

        // Фильтр по поставщику
        if (! empty($params['suppliers'])) {
            $q->whereIn(DB::raw('COALESCE(pg."providerName", \'—\')'), $params['suppliers']);
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
            $totalsQ->whereIn(DB::raw('COALESCE(pg."providerName", \'—\')'), $params['suppliers']);
        }
        if (! empty($params['products'])) {
            $totalsQ->whereIn('co.product', $params['products']);
        }

        $totalsRow = $totalsQ->selectRaw('
            SUM(COALESCE(t."amountRUB", 0))      as volume,
            COUNT(DISTINCT co.id)                as contract_count,
            SUM(COALESCE(t."netRevenueRUB", 0))  as revenue,
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
                DB::raw('SUM(COALESCE(t."netRevenueRUB", 0))  as revenue'),
                DB::raw('SUM(COALESCE(t."personalVolume", 0)) as points'),
                DB::raw('COUNT(DISTINCT co.consultant)         as fc_count'),
                DB::raw('COUNT(DISTINCT co.client)             as client_count'),
            ])
            ->groupBy('p.id', 'pg.id', 't.dateMonth')
            ->orderBy('p.id')->orderBy('pg.id')->orderBy('t.dateMonth');

        if (! empty($params['suppliers'])) {
            $q->whereIn(DB::raw('COALESCE(pg."providerName", \'—\')'), $params['suppliers']);
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

        $months = $this->monthRange($from, $to);

        $q = DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('consultant as cons', 'cons.id', '=', 'co.consultant')
            ->join(DB::raw('"WebUser" as wu'), DB::raw('wu.id'), '=', DB::raw('cons."webUser"'))
            ->join('product as p', 'p.id', '=', 'co.product')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereBetween('t.dateMonth', [$from, $to])
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->select([
                'co.consultant                                           as fc_id',
                DB::raw('wu."lastName" || \' \' || wu."firstName"       as fc_name'),
                'p.id                                                   as product_id',
                'p.name                                                 as product_name',
                'pg.id                                                  as program_id',
                'pg.name                                                as program_name',
                't.dateMonth',
                DB::raw('SUM(COALESCE(t."amountRUB",     0))           as volume'),
                DB::raw('COUNT(DISTINCT co.id)                          as cnt'),
                DB::raw('SUM(COALESCE(t."netRevenueRUB", 0))           as revenue'),
                DB::raw('SUM(COALESCE(t."personalVolume",0))           as points'),
                DB::raw('COUNT(DISTINCT co.client)                      as client_count'),
            ])
            ->groupBy(
                'co.consultant',
                DB::raw('wu."lastName"'), DB::raw('wu."firstName"'),
                'p.id', 'p.name', 'pg.id', 'pg.name', 't.dateMonth'
            )
            ->orderBy(DB::raw('wu."lastName"'))
            ->orderBy(DB::raw('wu."firstName"'))
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
            'period'      => ['from' => $from, 'to' => $to, 'months' => $months],
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
        ]);

        $from   = $params['from'];
        $to     = $params['to'];
        $months = $this->monthRange($from, $to);

        // Вычисляем границы периода по openDate (исключительная правая граница)
        [$ty, $tm] = explode('-', $to);
        $tm = (int) $tm + 1;
        if ($tm > 12) { $tm = 1; $ty = (int) $ty + 1; }
        $toExclusive = sprintf('%04d-%02d-01', (int) $ty, $tm);

        // Базовый builder: только активированные контракты по дате активации
        $base = fn () => DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->where('co.status', 1)
            ->whereRaw('co."openDate" IS NOT NULL')
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereRaw('co."openDate"::date >= ?', [$from . '-01'])
            ->whereRaw('co."openDate"::date < ?',  [$toExclusive])
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw('COALESCE(pg."providerName", \'—\')'), $params['suppliers'])
            )
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products'])
            );

        // Период для группировки: YYYY-MM из даты активации
        $periodExpr = DB::raw('TO_CHAR(DATE_TRUNC(\'month\', co."openDate"::date), \'YYYY-MM\') as period_month');
        $periodRaw  = DB::raw('DATE_TRUNC(\'month\', co."openDate"::date)');

        // Подзапрос: revenue/points из транзакций месяца активации контракта
        // (только первичный платёж, без ежемесячных взносов рассрочки)
        $txSub = DB::table('transaction as t')
            ->whereRaw('t."deletedAt" IS NULL')
            ->select([
                't.contract',
                DB::raw('SUM(COALESCE(t."netRevenueRUB", 0))  as revenue_rub'),
                DB::raw('SUM(COALESCE(t."personalVolume", 0)) as points_sum'),
            ])
            ->groupBy('t.contract');

        // Основная агрегация: продукт × программа × месяц активации
        // Конвертация в RUB только через management_currency_rate (новый справочник)
        // Если курс не заполнен — ammount берётся как есть (fallback=1)
        $rows = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->leftJoinSub($txSub, 'tx', 'tx.contract', '=', 'co.id')
            ->leftJoin('management_currency_rate as mcr_co', function ($j) {
                $j->on('mcr_co.currency', '=', 'co.currency')
                  ->whereRaw('mcr_co.date = DATE_TRUNC(\'month\', co."openDate"::date)::date');
            })
            ->select([
                'p.id   as product_id',
                'p.name as product_name',
                'pg.id   as program_id',
                'pg.name as program_name',
                $periodExpr,
                DB::raw('SUM(COALESCE(co.ammount, 0)
                    * COALESCE(mcr_co.rate, 1))                            as volume'),
                DB::raw('COUNT(DISTINCT co.id)                              as cnt'),
                DB::raw('SUM(COALESCE(tx.revenue_rub, 0))                  as revenue'),
                DB::raw('SUM(COALESCE(tx.points_sum, 0))                   as points'),
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

        // Производные поля: avgCheck и fcCount на уровне продукта/гранда
        foreach ($productMap as $pid => &$prod) {
            $prod['avgCheck'] = $prod['count'] > 0 ? round($prod['volume'] / $prod['count'], 2) : 0;
            foreach ($prod['monthly'] as $mo => &$mv) {
                $mv['avgCheck'] = $mv['count'] > 0 ? round($mv['volume'] / $mv['count'], 2) : 0;
                $mv['fcCount']  = $fcMonthlyIdx[$pid][$mo] ?? 0;
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
            $gv['avgCheck'] = $gv['count'] > 0 ? round($gv['volume'] / $gv['count'], 2) : 0;
            $gv['fcCount']  = (int) ($grandFcMonthly[$mo] ?? 0);
        }
        unset($gv);

        $result = [];
        foreach ($productMap as $pid => $prod) {
            $prod['fcCount']  = (int) ($fcCounts[$pid]->fc_count ?? 0);
            $prod['programs'] = array_values($prod['programs']);
            $result[]         = $prod;
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

        return response()->json([
            'period'      => ['from' => $from, 'to' => $to, 'months' => $months],
            'rows'        => $result,
            'grandTotals' => $grand,
            'suppliers'   => $allSuppliers->values(),
            'products'    => $allProducts->values(),
        ]);
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
            'fcFrom'      => 'nullable|date_format:Y-m',
            'fcTo'        => 'nullable|date_format:Y-m',
        ]);

        $from   = $params['from'];
        $to     = $params['to'];
        $months = $this->monthRange($from, $to);

        $toExclusive = $this->monthExclusiveStart($to);

        // Доп. фильтр по прогнозу активации (правая граница исключительная).
        $fcFrom = $params['fcFrom'] ?? null;
        $fcTo   = $params['fcTo']   ?? null;
        $hasFc  = $fcFrom && $fcTo;
        $fcToExclusive = $hasFc ? $this->monthExclusiveStart($fcTo) : null;

        $EXCLUDED = [1, 6, 8, 10]; // Активирован, Закрыто нереализ., Закрыто, Лапсирован

        // Базовый builder: контракты «в работе» по дате создания.
        $base = fn () => DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereNotIn('co.status', $EXCLUDED)
            ->whereRaw('co."deletedAt" IS NULL')
            ->whereRaw('co."createDate" IS NOT NULL')
            ->whereRaw('co."createDate"::date >= ?', [$from . '-01'])
            ->whereRaw('co."createDate"::date < ?',  [$toExclusive])
            ->when($hasFc, fn ($q) =>
                $q->whereRaw('co.activation_forecast >= ?', [$fcFrom . '-01'])
                  ->whereRaw('co.activation_forecast <  ?', [$fcToExclusive])
            )
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw('COALESCE(pg."providerName", \'—\')'), $params['suppliers'])
            )
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products'])
            );

        // Период группировки: YYYY-MM из даты создания.
        $periodExpr = DB::raw('TO_CHAR(DATE_TRUNC(\'month\', co."createDate"::date), \'YYYY-MM\') as period_month');
        $periodRaw  = DB::raw('DATE_TRUNC(\'month\', co."createDate"::date)');

        $txSub = DB::table('transaction as t')
            ->whereRaw('t."deletedAt" IS NULL')
            ->select([
                't.contract',
                DB::raw('SUM(COALESCE(t."netRevenueRUB", 0))  as revenue_rub'),
                DB::raw('SUM(COALESCE(t."personalVolume", 0)) as points_sum'),
            ])
            ->groupBy('t.contract');

        $rows = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->leftJoinSub($txSub, 'tx', 'tx.contract', '=', 'co.id')
            ->leftJoin('management_currency_rate as mcr_co', function ($j) {
                $j->on('mcr_co.currency', '=', 'co.currency')
                  ->whereRaw('mcr_co.date = DATE_TRUNC(\'month\', co."createDate"::date)::date');
            })
            ->select([
                'p.id   as product_id',
                'p.name as product_name',
                'pg.id   as program_id',
                'pg.name as program_name',
                $periodExpr,
                DB::raw('SUM(COALESCE(co.ammount, 0) * COALESCE(mcr_co.rate, 1)) as volume'),
                DB::raw('COUNT(DISTINCT co.id)             as cnt'),
                DB::raw('SUM(COALESCE(tx.revenue_rub, 0))  as revenue'),
                DB::raw('SUM(COALESCE(tx.points_sum, 0))   as points'),
                DB::raw('COUNT(DISTINCT co.client)          as client_count'),
                DB::raw('COUNT(DISTINCT co.consultant)      as fc_count'),
            ])
            ->groupBy('p.id', 'p.name', 'pg.id', 'pg.name', $periodRaw)
            ->orderBy('p.name')->orderBy('pg.name')
            ->orderByRaw('DATE_TRUNC(\'month\', co."createDate"::date)')
            ->get();

        return response()->json($this->assembleMatrix($rows, $base, $periodExpr, $periodRaw, $from, $to, $months));
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
                $q->whereIn(DB::raw('COALESCE(pg."providerName", \'—\')'), $params['suppliers'])
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
            $months = $this->monthRange($from, $to);
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
        $months = $this->monthRange($from, $to);

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
                $q->whereIn(DB::raw('COALESCE(pg."providerName", \'—\')'), $params['suppliers'])
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
                DB::raw('COUNT(DISTINCT co.id)                as cnt'),
                DB::raw('SUM(COALESCE(t."netRevenueRUB", 0))  as revenue'),
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

        // Производные поля: avgCheck и fcCount на уровне продукта/гранда
        foreach ($productMap as $pid => &$prod) {
            $prod['avgCheck'] = $prod['count'] > 0 ? round($prod['volume'] / $prod['count'], 2) : 0;
            foreach ($prod['monthly'] as $mo => &$mv) {
                $mv['avgCheck'] = $mv['count'] > 0 ? round($mv['volume'] / $mv['count'], 2) : 0;
                $mv['fcCount']  = $fcMonthlyIdx[$pid][$mo] ?? 0;
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
            $gv['avgCheck'] = $gv['count'] > 0 ? round($gv['volume'] / $gv['count'], 2) : 0;
            $gv['fcCount']  = (int) ($grandFcMonthly[$mo] ?? 0);
        }
        unset($gv);

        $result = [];
        foreach ($productMap as $pid => $prod) {
            $prod['fcCount']  = (int) ($fcCounts[$pid]->fc_count ?? 0);
            $prod['programs'] = array_values($prod['programs']);
            $result[]         = $prod;
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

        return response()->json([
            'period'      => ['from' => $from, 'to' => $to, 'months' => $months],
            'rows'        => $result,
            'grandTotals' => $grand,
            'suppliers'   => $allSuppliers->values(),
            'products'    => $allProducts->values(),
        ]);
    }

    /** Начало месяца, следующего за $ym (исключительная правая граница), 'YYYY-MM-01'. */
    private function monthExclusiveStart(string $ym): string
    {
        [$y, $m] = explode('-', $ym);
        $m = (int) $m + 1;
        if ($m > 12) { $m = 1; $y = (int) $y + 1; }

        return sprintf('%04d-%02d-01', (int) $y, $m);
    }

    /**
     * Общая сборка матрицы продукт×программа×месяц из строк агрегации
     * (одинаковый формат строк у quarterlyMatrix и inWorkMatrix).
     * $rows ожидает колонки: product_id/product_name/program_id/program_name/
     * period_month/volume/cnt/revenue/points/client_count/fc_count.
     */
    private function assembleMatrix($rows, callable $base, $periodExpr, $periodRaw, string $from, string $to, array $months): array
    {
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

        // FC distinct по (продукт × месяц)
        $fcMonthlyIdx = [];
        foreach ($base()->select(['co.product as product_id', $periodExpr, DB::raw('COUNT(DISTINCT co.consultant) as fc_count')])
            ->groupBy('co.product', $periodRaw)->get() as $r) {
            $fcMonthlyIdx[$r->product_id][$r->period_month] = (int) $r->fc_count;
        }
        $grandFcMonthly = $base()
            ->select([$periodExpr, DB::raw('COUNT(DISTINCT co.consultant) as fc_count')])
            ->groupBy($periodRaw)->get()->pluck('fc_count', 'period_month');
        $fcCounts = $base()
            ->select('co.product as product_id', DB::raw('COUNT(DISTINCT co.consultant) as fc_count'))
            ->groupBy('co.product')->get()->keyBy('product_id');
        $grand['fcCount'] = (int) $base()->distinct()->count('co.consultant');

        foreach ($productMap as $pid => &$prod) {
            $prod['avgCheck'] = $prod['count'] > 0 ? round($prod['volume'] / $prod['count'], 2) : 0;
            foreach ($prod['monthly'] as $mo => &$mv) {
                $mv['avgCheck'] = $mv['count'] > 0 ? round($mv['volume'] / $mv['count'], 2) : 0;
                $mv['fcCount']  = $fcMonthlyIdx[$pid][$mo] ?? 0;
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
            $gv['avgCheck'] = $gv['count'] > 0 ? round($gv['volume'] / $gv['count'], 2) : 0;
            $gv['fcCount']  = (int) ($grandFcMonthly[$mo] ?? 0);
        }
        unset($gv);

        $result = [];
        foreach ($productMap as $pid => $prod) {
            $prod['fcCount']  = (int) ($fcCounts[$pid]->fc_count ?? 0);
            $prod['programs'] = array_values($prod['programs']);
            $result[]         = $prod;
        }

        $allSuppliers = $base()
            ->whereNotNull('pg.providerName')->distinct()->orderBy('pg.providerName')->pluck('pg.providerName');
        $allProducts = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->select('p.id', 'p.name')->distinct()->orderBy('p.name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]);

        return [
            'period'      => ['from' => $from, 'to' => $to, 'months' => $months],
            'rows'        => $result,
            'grandTotals' => $grand,
            'suppliers'   => $allSuppliers->values(),
            'products'    => $allProducts->values(),
        ];
    }

    private function monthRange(string $from, string $to): array
    {
        $months = [];
        $cur    = $from;
        while ($cur <= $to) {
            $months[] = $cur;
            [$y, $m]  = explode('-', $cur);
            $m = (int) $m + 1;
            if ($m > 12) { $m = 1; $y = (int) $y + 1; }
            $cur = sprintf('%04d-%02d', (int) $y, $m);
        }
        return $months;
    }
}
