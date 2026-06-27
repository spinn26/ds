<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Матрица продаж в разрезе ПАРТНЁРОВ (ФК).
 *
 * Та же логика дат/состояний, что в ProductSalesMatrixController, но строки
 * группируются по иерархии: Структура (корень-предок ФК) → ФК → Продукт.
 *
 * Состояния (как в продуктовом отчёте):
 *   fact     — по дате создания транзакции (transaction.dateMonth)
 *   forecast — «Активировано»: контракты в статусах 2/3 по activation_forecast
 *   inwork   — «В работе»: контракты по createDate (суммы контракта)
 *   total    — сумма трёх разрезов (на стороне фронта/отдельным методом)
 *
 * Метрики: Объём, Кол-во, Ср.чек, Выручка, Баллы (commission.groupBonus),
 * Баллы ЛП (commission.personalVolume, chainOrder=1), Кол-во ФК, Клиенты.
 *
 * Структура ФК = верхний предок по consultant.inviter (как на странице
 * «Структура»). Имя структуры = personName корня.
 */
class PartnerSalesMatrixController extends Controller
{
    /**
     * GET /admin/reports/partner-matrix/fact
     * Params: from, to (Y-m), suppliers[], products[], structures[] (root consultant ids), fcs[] (consultant ids)
     */
    public function factMatrix(Request $request): JsonResponse
    {
        $params = $this->validateParams($request);
        $months = $this->monthRange($params['from'], $params['to']);
        return response()->json($this->assemblePartnerTree($this->factRows($params), $months, $params));
    }

    /** GET /admin/reports/partner-matrix/inwork — «В работе»: контракты по createDate. */
    public function inWorkMatrix(Request $request): JsonResponse
    {
        $params = $this->validateParams($request);
        $months = $this->monthRange($params['from'], $params['to']);
        return response()->json($this->assemblePartnerTree($this->contractRows($params, 'inwork'), $months, $params));
    }

    /** GET /admin/reports/partner-matrix/forecast — «Активировано»: контракты статус 2/3 по activation_forecast. */
    public function forecastMatrix(Request $request): JsonResponse
    {
        $params = $this->validateParams($request);
        $months = $this->monthRange($params['from'], $params['to']);
        return response()->json($this->assemblePartnerTree($this->contractRows($params, 'forecast'), $months, $params));
    }

    /** GET /admin/reports/partner-matrix/total — «Итого»: сумма трёх разрезов. */
    public function totalMatrix(Request $request): JsonResponse
    {
        $params = $this->validateParams($request);
        $months = $this->monthRange($params['from'], $params['to']);
        // Конкатенация плоских строк трёх состояний — assemblePartnerTree суммирует
        // их по (структура, ФК, продукт, месяц). ФК-distinct сохраняется (fcSet),
        // клиенты/кол-во суммируются между разрезами (как в продуктовом «Итого»).
        $rows = collect($this->factRows($params))
            ->concat($this->contractRows($params, 'inwork'))
            ->concat($this->contractRows($params, 'forecast'));
        return response()->json($this->assemblePartnerTree($rows, $months, $params));
    }

    /** Плоские строки состояния «Факт» (по транзакциям). */
    private function factRows(array $params)
    {
        [$from, $to] = [$params['from'], $params['to']];

        // Дедуп commission chainOrder=1 на транзакцию (последняя версия по id) —
        // источник «Баллы»(groupBonus) и «Баллы ЛП»(personalVolume) у продавшего ФК.
        $cmSub = '(SELECT DISTINCT ON (cm.transaction) cm.transaction, cm."groupBonus", cm."personalVolume"
                   FROM commission cm
                   WHERE cm."chainOrder" = 1 AND cm."deletedAt" IS NULL
                   ORDER BY cm.transaction, cm.id DESC) as cmx';

        return DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('consultant as cons', 'cons.id', '=', 'co.consultant')
            ->join('product as p', 'p.id', '=', 'co.product')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->leftJoin(DB::raw($cmSub), 'cmx.transaction', '=', 't.id')
            ->whereBetween('t.dateMonth', [$from, $to])
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw('COALESCE(pg."providerName", \'—\')'), $params['suppliers']))
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products']))
            ->when(! empty($params['fcs']), fn ($q) =>
                $q->whereIn('co.consultant', $params['fcs']))
            ->select([
                'co.consultant as fc_id',
                'cons.personName as fc_name',
                'p.id as product_id',
                'p.name as product_name',
                't.dateMonth as period_month',
                DB::raw('SUM(COALESCE(t."amountRUB", 0))      as volume'),
                DB::raw('COUNT(DISTINCT t.id)                 as cnt'),
                DB::raw('SUM(COALESCE(t."netRevenueRUB", 0))  as revenue'),
                DB::raw('SUM(COALESCE(cmx."groupBonus", 0))   as bally'),
                DB::raw('SUM(COALESCE(cmx."personalVolume", 0)) as bally_lp'),
                DB::raw('COUNT(DISTINCT co.client)            as client_count'),
            ])
            ->groupBy('co.consultant', 'cons.personName', 'p.id', 'p.name', 't.dateMonth')
            ->get();
    }

    /**
     * Плоские строки состояний «В работе» / «Активировано» (по контрактам).
     *  - inwork:   статус NOT IN (1,6,8,10), период по createDate, объём = Σ сумм контракта;
     *  - forecast: статус IN (2,3), период по activation_forecast.
     * Транзакций ещё нет → revenue/Баллы/ЛП = 0 (пайплайн до начисления).
     */
    private function contractRows(array $params, string $mode)
    {
        [$from, $to] = [$params['from'], $params['to']];
        $toExclusive = $this->monthExclusiveStart($to);

        if ($mode === 'inwork') {
            $dateCol  = 'createDate';
            $statusFn = fn ($q) => $q->whereNotIn('co.status', [1, 6, 8, 10]);
        } else { // forecast
            $dateCol  = 'activation_forecast';
            $statusFn = fn ($q) => $q->whereIn('co.status', [2, 3]);
        }

        $periodExpr = DB::raw("TO_CHAR(DATE_TRUNC('month', co.\"$dateCol\"::date), 'YYYY-MM') as period_month");
        $periodTrunc = DB::raw("DATE_TRUNC('month', co.\"$dateCol\"::date)");
        $volumeExpr = DB::raw('SUM(COALESCE(co.ammount, 0) * ' . $this->rateExpr($dateCol) . ') as volume');

        return DB::table('contract as co')
            ->join('consultant as cons', 'cons.id', '=', 'co.consultant')
            ->join('product as p', 'p.id', '=', 'co.product')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereNull('co.deletedAt')
            ->whereRaw("co.\"$dateCol\" IS NOT NULL")
            ->whereRaw("co.\"$dateCol\"::date >= ?", [$from . '-01'])
            ->whereRaw("co.\"$dateCol\"::date < ?", [$toExclusive])
            ->where($statusFn)
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw('COALESCE(pg."providerName", \'—\')'), $params['suppliers']))
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products']))
            ->when(! empty($params['fcs']), fn ($q) =>
                $q->whereIn('co.consultant', $params['fcs']))
            ->select([
                'co.consultant as fc_id',
                'cons.personName as fc_name',
                'p.id as product_id',
                'p.name as product_name',
                $periodExpr,
                $volumeExpr,
                DB::raw('COUNT(DISTINCT co.id)            as cnt'),
                DB::raw('0                                as revenue'),
                DB::raw('0                                as bally'),
                DB::raw('0                                as bally_lp'),
                DB::raw('COUNT(DISTINCT co.client)        as client_count'),
            ])
            ->groupBy('co.consultant', 'cons.personName', 'p.id', 'p.name', $periodTrunc)
            ->get();
    }

    /** Корреляционное курсовое выражение (как в продуктовом отчёте). */
    private function rateExpr(string $dateCol): string
    {
        $month = 'DATE_TRUNC(\'month\', co."' . $dateCol . '"::date)::date';
        return '(COALESCE('
            . '(SELECT m.rate FROM management_currency_rate m WHERE m.currency = co.currency AND m.date <= ' . $month . ' ORDER BY m.date DESC LIMIT 1),'
            . '(SELECT m.rate FROM management_currency_rate m WHERE m.currency = co.currency ORDER BY m.date ASC LIMIT 1),'
            . '1))';
    }

    /** Первое число месяца, следующего за $ym (Y-m). */
    private function monthExclusiveStart(string $ym): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        if (++$m > 12) { $m = 1; $y++; }
        return sprintf('%04d-%02d-01', $y, $m);
    }

    /**
     * GET /admin/reports/partner-matrix/lookups
     * structures — топ-консультанты (корни). fcs — ФК; если переданы
     * structures[], отдаём только потомков выбранных корней (каскад).
     */
    public function lookups(Request $request): JsonResponse
    {
        $structures = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->where(fn ($q) => $q->whereNull('inviter')->orWhere('inviter', 0))
            ->where('activity', '!=', 3) // не терминированные
            ->orderBy('personName')
            ->get(['id', 'personName as name']);

        $structureIds = array_filter(array_map('intval', (array) $request->input('structures', [])));

        $fcQuery = DB::table('consultant')->whereNull('dateDeleted');
        if (! empty($structureIds)) {
            $ids = implode(',', $structureIds);
            $desc = DB::select("
                WITH RECURSIVE tree AS (
                    SELECT id FROM consultant WHERE id IN ($ids) AND \"dateDeleted\" IS NULL
                    UNION ALL
                    SELECT c.id FROM consultant c JOIN tree ON c.inviter = tree.id WHERE c.\"dateDeleted\" IS NULL
                )
                SELECT id FROM tree
            ");
            $fcQuery->whereIn('id', array_map(fn ($r) => $r->id, $desc));
        }
        $fcs = $fcQuery->orderBy('personName')->limit(3000)->get(['id', 'personName as name']);

        // Продукты для фильтра — те, по которым есть контракты (distinct).
        $products = DB::table('contract as co')
            ->join('product as p', 'p.id', '=', 'co.product')
            ->whereNull('co.deletedAt')
            ->whereNotNull('co.openDate')
            ->select('p.id', 'p.name')
            ->distinct()
            ->orderBy('p.name')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]);

        return response()->json(['structures' => $structures, 'fcs' => $fcs, 'products' => $products]);
    }

    // ---- helpers ----

    private function validateParams(Request $request): array
    {
        $p = $request->validate([
            'from'         => 'required|date_format:Y-m',
            'to'           => 'required|date_format:Y-m',
            'suppliers'    => 'nullable|array',
            'suppliers.*'  => 'string|max:200',
            'products'     => 'nullable|array',
            'products.*'   => 'integer',
            'structures'   => 'nullable|array',
            'structures.*' => 'integer',
            'fcs'          => 'nullable|array',
            'fcs.*'        => 'integer',
        ]);
        return $p;
    }

    /** YYYY-MM список месяцев включительно. */
    private function monthRange(string $from, string $to): array
    {
        $out = [];
        [$fy, $fm] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));
        $y = $fy; $m = $fm;
        while ($y < $ty || ($y === $ty && $m <= $tm)) {
            $out[] = sprintf('%04d-%02d', $y, $m);
            if (++$m > 12) { $m = 1; $y++; }
        }
        return $out;
    }

    /**
     * Карта consultant_id => ['rootId'=>, 'rootName'=>] (корень структуры) для
     * заданных ФК — рекурсивным подъёмом по inviter.
     *
     * @param array<int> $fcIds
     * @return array<int, array{rootId:int, rootName:?string}>
     */
    private function structureRootMap(array $fcIds): array
    {
        if (empty($fcIds)) return [];
        $ids = implode(',', array_map('intval', array_unique($fcIds)));

        $rows = DB::select("
            WITH RECURSIVE chain AS (
                SELECT id AS node, id AS cur, inviter
                FROM consultant
                WHERE id IN ($ids)
                UNION ALL
                SELECT ch.node, c.id, c.inviter
                FROM chain ch
                JOIN consultant c ON c.id = ch.inviter
                WHERE ch.inviter IS NOT NULL AND ch.inviter <> 0
            )
            SELECT ch.node AS consultant_id, ch.cur AS root_id, c.\"personName\" AS root_name
            FROM chain ch
            JOIN consultant c ON c.id = ch.cur
            WHERE ch.inviter IS NULL OR ch.inviter = 0
        ");

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->consultant_id] = [
                'rootId' => (int) $r->root_id,
                'rootName' => $r->root_name,
            ];
        }
        // ФК-сироты (нет цепочки до корня) — сами себе структура.
        foreach ($fcIds as $id) {
            if (! isset($map[(int) $id])) {
                $map[(int) $id] = ['rootId' => (int) $id, 'rootName' => null];
            }
        }
        return $map;
    }

    /**
     * Собрать дерево Структура → ФК → Продукт из плоских строк
     * (fc_id, fc_name, product_id, product_name, period_month, volume, cnt,
     *  revenue, bally, bally_lp, client_count).
     */
    private function assemblePartnerTree($rows, array $months, array $params): array
    {
        $fcIds = collect($rows)->pluck('fc_id')->unique()->map(fn ($x) => (int) $x)->all();
        $rootMap = $this->structureRootMap($fcIds);

        // Фильтр по структуре — оставляем только ФК выбранных корней.
        $structFilter = ! empty($params['structures'])
            ? array_map('intval', $params['structures'])
            : null;

        $structures = []; // rootId => node
        $grand = $this->emptyAgg();

        foreach ($rows as $r) {
            $fcId = (int) $r->fc_id;
            $root = $rootMap[$fcId] ?? ['rootId' => $fcId, 'rootName' => $r->fc_name];
            $rid = $root['rootId'];
            if ($structFilter !== null && ! in_array($rid, $structFilter, true)) continue;

            $pid = (int) $r->product_id;
            $mo  = $r->period_month;
            $vals = [
                'volume'      => round((float) $r->volume, 2),
                'count'       => (int) $r->cnt,
                'revenue'     => round((float) $r->revenue, 2),
                'bally'       => round((float) $r->bally, 2),
                'ballyLP'     => round((float) $r->bally_lp, 2),
                'clientCount' => (int) $r->client_count,
            ];

            if (! isset($structures[$rid])) {
                $structures[$rid] = array_merge($this->emptyAgg(), [
                    'structureId' => $rid,
                    'structureName' => $root['rootName'] ?? ('ФК #' . $rid),
                    'fcs' => [],
                    'fcSet' => [],
                ]);
            }
            $S = &$structures[$rid];
            $S['fcSet'][$fcId] = true;

            if (! isset($S['fcs'][$fcId])) {
                $S['fcs'][$fcId] = array_merge($this->emptyAgg(), [
                    'fcId' => $fcId,
                    'fcName' => $r->fc_name,
                    'products' => [],
                ]);
            }
            $F = &$S['fcs'][$fcId];

            if (! isset($F['products'][$pid])) {
                $F['products'][$pid] = array_merge($this->emptyAgg(), [
                    'productId' => $pid,
                    'productName' => $r->product_name,
                ]);
            }
            $P = &$F['products'][$pid];

            // Накопление на 3 уровнях + grand + помесячно.
            foreach (['volume', 'count', 'revenue', 'bally', 'ballyLP', 'clientCount'] as $k) {
                $P[$k] += $vals[$k];
                $F[$k] += $vals[$k];
                $S[$k] += $vals[$k];
                $grand[$k] += $vals[$k];
                $P['monthly'][$mo][$k] = ($P['monthly'][$mo][$k] ?? 0) + $vals[$k];
                $F['monthly'][$mo][$k] = ($F['monthly'][$mo][$k] ?? 0) + $vals[$k];
                $S['monthly'][$mo][$k] = ($S['monthly'][$mo][$k] ?? 0) + $vals[$k];
                $grand['monthly'][$mo][$k] = ($grand['monthly'][$mo][$k] ?? 0) + $vals[$k];
            }
            unset($S, $F, $P);
        }

        // Финализация: avgCheck, fcCount (distinct), список продуктов/ФК.
        $structOut = [];
        foreach ($structures as $rid => $S) {
            $fcCountStruct = count($S['fcSet']);
            $fcsOut = [];
            foreach ($S['fcs'] as $F) {
                $prodsOut = array_values(array_map(fn ($P) => $this->finalizeNode($P, 1), $F['products']));
                $fcsOut[] = array_merge($this->finalizeNode($F, 1), ['products' => $prodsOut]);
            }
            // сортировка ФК по выручке убыв.
            usort($fcsOut, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
            $structOut[] = array_merge($this->finalizeNode($S, $fcCountStruct), ['fcs' => $fcsOut]);
        }
        usort($structOut, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'months' => $months,
            'structures' => $structOut,
            'grand' => $this->finalizeNode($grand, $this->grandFcCount($structures)),
        ];
    }

    private function emptyAgg(): array
    {
        return [
            'volume' => 0, 'count' => 0, 'revenue' => 0,
            'bally' => 0, 'ballyLP' => 0, 'clientCount' => 0, 'monthly' => [],
        ];
    }

    /** Доп. поля узла: средний чек + кол-во ФК (передаётся явно). */
    private function finalizeNode(array $node, int $fcCount): array
    {
        $node['avgCheck'] = $node['count'] > 0 ? round($node['volume'] / $node['count'], 2) : 0;
        $node['fcCount'] = $fcCount;
        foreach ($node['monthly'] as $mo => $m) {
            $node['monthly'][$mo]['avgCheck'] = ($m['count'] ?? 0) > 0
                ? round($m['volume'] / $m['count'], 2) : 0;
        }
        unset($node['fcSet']);
        return $node;
    }

    private function grandFcCount(array $structures): int
    {
        $set = [];
        foreach ($structures as $S) {
            foreach ($S['fcSet'] as $id => $_) $set[$id] = true;
        }
        return count($set);
    }
}
