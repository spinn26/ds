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
}
