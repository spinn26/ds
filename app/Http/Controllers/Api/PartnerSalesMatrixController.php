<?php

namespace App\Http\Controllers\Api;

use App\Services\SalesMatrixSupport;
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
 * Состояния (свод с продуктовым отчётом, реш. Лены 2026-07-28):
 *   fact     — по дате создания транзакции (transaction.dateMonth)
 *   forecast — «Активировано»: активированные контракты (статус 1) по openDate,
 *              по которым ещё НЕТ транзакции
 *   inwork   — «В работе»: контракты по createDate, статус NOT IN (1,6,8,9,10)
 *   total    — сумма трёх разрезов
 *
 * Метрики (реш. Лены 2026-07-31): Выручка = доход ДС без НДС
 *   («Факт» — transaction.commissionsAmountRUB как есть; «В работе»/«Активировано»
 *   — прогноз Сумма×%ДС/105×100). Баллы = Выручка÷100. Баллы ЛП (личные) =
 *   personalVolume из транзакций, Продукт→ФК, на команде — прочерк.
 *
 * Структура ФК = верхний предок по consultant.inviter (как на странице
 * «Структура»). Имя структуры = personName корня.
 */
class PartnerSalesMatrixController extends Controller
{
        public function __construct(
        private readonly SalesMatrixSupport $matrixSupport,
    ) {}

/**
     * GET /admin/reports/partner-matrix/fact
     * Params: from, to (Y-m), suppliers[], products[], structures[] (root consultant ids), fcs[] (consultant ids)
     */
    public function factMatrix(Request $request): JsonResponse
    {
        return response()->json($this->buildResponse($this->validateParams($request), 'fact'));
    }

    /** GET /admin/reports/partner-matrix/inwork — «В работе»: контракты по createDate. */
    public function inWorkMatrix(Request $request): JsonResponse
    {
        return response()->json($this->buildResponse($this->validateParams($request), 'inwork'));
    }

    /** GET /admin/reports/partner-matrix/forecast — «Активировано»: активированные контракты (статус 1) по openDate, без транзакции. */
    public function forecastMatrix(Request $request): JsonResponse
    {
        return response()->json($this->buildResponse($this->validateParams($request), 'forecast'));
    }

    /** GET /admin/reports/partner-matrix/total — «Итого»: сумма трёх разрезов. */
    public function totalMatrix(Request $request): JsonResponse
    {
        return response()->json($this->buildResponse($this->validateParams($request), 'total'));
    }

    /**
     * Сборка ответа отчёта: дерево + «знаменатели» для долей КМ/КЛ.
     *
     * Доли «от команды»/«от компании» в подписи под «Выручкой» должны считаться
     * от ПОЛНОЙ команды/компании, а не от отфильтрованной выдачи — иначе при
     * фильтре по 1 ФК/структуре доля выходила 100%. Поэтому, когда применён
     * фильтр по ФК или структуре, отдельным проходом (без этих фильтров, но с
     * тем же периодом/продуктом/поставщиком) считаем истинные выручки компании
     * и каждой команды. Фронт делит на них (см. revenueSub).
     */
    private function buildResponse(array $params, string $mode): array
    {
        $months = $this->matrixSupport->monthRange($params['from'], $params['to']);
        $result = $this->assemblePartnerTree($this->rowsForMode($params, $mode), $months, $params, $mode === 'total');
        $result['denominators'] = $this->denominators($params, $mode, $months);

        return $result;
    }

    /** Плоские строки для режима (fact / inwork / forecast / total). */
    private function rowsForMode(array $params, string $mode)
    {
        if ($mode === 'fact') {
            return collect($this->factRows($params));
        }
        if ($mode === 'inwork' || $mode === 'forecast') {
            $rows = $this->contractRows($params, $mode);
            $this->injectContractForecast($rows, $params, $mode);

            return $rows;
        }
        // total = сумма трёх разрезов (fact + inwork + forecast).
        $inwork = $this->contractRows($params, 'inwork');
        $this->injectContractForecast($inwork, $params, 'inwork');
        $forecast = $this->contractRows($params, 'forecast');
        $this->injectContractForecast($forecast, $params, 'forecast');

        return collect($this->factRows($params))->concat($inwork)->concat($forecast);
    }

    /**
     * Истинные выручки компании и команд (без фильтра по ФК/структуре) для
     * знаменателей долей. Возвращает null, когда фильтр по ФК/структуре не
     * применён — там grand/структуры дерева и так = компания/команды.
     *
     * @return array{company: float, teams: array<string, float>}|null
     */
    private function denominators(array $params, string $mode, array $months): ?array
    {
        if (empty($params['fcs']) && empty($params['structures'])) {
            return null;
        }
        $base = $params;
        unset($base['fcs'], $base['structures']);
        $tree = $this->assemblePartnerTree($this->rowsForMode($base, $mode), $months, $base);

        $teams = [];
        foreach ($tree['structures'] as $s) {
            $teams[(string) $s['structureId']] = $s['revenue'];
        }

        return [
            'company' => (float) ($tree['grand']['revenue'] ?? 0),
            'teams' => $teams,
        ];
    }


    /** Плоские строки состояния «Факт» (по транзакциям). */
    private function factRows(array $params)
    {
        [$from, $to] = [$params['from'], $params['to']];

        // «Баллы» берём из самой транзакции (t.personalVolume) — как в
        // продуктовом отчёте. Раньше тянули из commission(chainOrder=1), но для
        // свежих транзакций commission-строк может не быть → недосчёт баллов.
        // «Баллы ЛП (комиссия)» = Баллы × %уровня — считается в assemblePartnerTree.
        return DB::table('transaction as t')
            ->join('contract as co', 'co.id', '=', 't.contract')
            ->join('consultant as cons', 'cons.id', '=', 'co.consultant')
            ->join('product as p', 'p.id', '=', 'co.product')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereBetween('t.dateMonth', [$from, $to])
            ->whereNotNull('co.openDate')
            ->whereNull('co.deletedAt')
            ->whereNull('t.deletedAt')
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers']))
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products']))
            ->when(! empty($params['structures']), fn ($q) =>
                $q->whereIn('co.consultant', $this->subtreeIds($params['structures'])))
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
                // Выручка «Факт» = доход ДС без НДС из транзакции (commissionsAmountRUB),
                // берём как есть — «чистый факт» (реш. Лены 2026-07-31). Раньше тут был
                // netRevenueRUB (сумма без НДС клиента) — это НЕ доход ДС, завышало в ~14×.
                DB::raw('SUM(COALESCE(t."commissionsAmountRUB", 0)) as revenue'),
                // Баллы ЛП (личные) = personalVolume из транзакций (= доход ДС ÷ 100).
                DB::raw('SUM(COALESCE(t."personalVolume", 0)) as bally'),
                DB::raw('SUM(COALESCE(t."personalVolume", 0)) as bally_lp'),
                DB::raw('COUNT(DISTINCT co.client)            as client_count'),
                DB::raw("string_agg(DISTINCT co.client::text, ',') as client_ids"),
                DB::raw("'fact'                               as state"),
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
        $toExclusive = $this->matrixSupport->monthExclusiveStart($to);

        if ($mode === 'inwork') {
            // «В работе»: по дате создания, ещё не активированные. Исключаем
            // те же статусы, что продуктовый отчёт: Активирован(1), Закрыто
            // нереализ.(6), Закрыто(8), Возврат(9), Лапсирован(10).
            $dateCol  = 'createDate';
            $statusFn = fn ($q) => $q->whereNotIn('co.status', [1, 6, 8, 9, 10]);
        } else { // forecast = «Активировано»
            // Свод с продуктовым отчётом (реш. Лены 2026-07-28): активированные
            // контракты (статус 1) по дате активации openDate, по которым ещё
            // НЕТ транзакции (иначе это «Факт»). Раньше тут был пайплайн 2/3 по
            // activation_forecast — расходилось с продуктовым в 5-7 раз.
            $dateCol  = 'openDate';
            $statusFn = fn ($q) => $q->where('co.status', 1)
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('transaction as tx')
                    ->whereColumn('tx.contract', 'co.id')->whereNull('tx.deletedAt'));
        }

        $periodExpr = DB::raw("TO_CHAR(DATE_TRUNC('month', co.\"$dateCol\"::date), 'YYYY-MM') as period_month");
        $periodTrunc = DB::raw("DATE_TRUNC('month', co.\"$dateCol\"::date)");
        $volumeExpr = DB::raw('SUM(COALESCE(co.ammount, 0) * ' . $this->matrixSupport->rateExpr($dateCol) . ') as volume');

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
                $q->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers']))
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products']))
            ->when(! empty($params['structures']), fn ($q) =>
                $q->whereIn('co.consultant', $this->subtreeIds($params['structures'])))
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
                DB::raw("string_agg(DISTINCT co.client::text, ',') as client_ids"),
                DB::raw("'{$mode}'                        as state"),
            ])
            ->groupBy('co.consultant', 'cons.personName', 'p.id', 'p.name', $periodTrunc)
            ->get();
    }

    /**
     * Прогнозные выручка и баллы для «В работе»/«Активировано» — транзакций
     * ещё нет, поэтому считаем из контракта (та же формула, что в продуктовой
     * матрице injectInWorkPoints): выручка ДС = amountNoVat × %ДС / 100,
     * баллы = выручка / 100. %ДС: program.dsPercent → тарифная сетка
     * (resolveLegacyDsCommission) → дефолт. Проставляем revenue/bally прямо в
     * строки (по fc_id + product_id + period_month).
     */
    private function injectContractForecast($rows, array $params, string $mode): void
    {
        if ($rows->isEmpty()) return;

        $dateCol  = $mode === 'inwork' ? 'createDate' : 'openDate';
        $statusFn = $mode === 'inwork'
            ? fn ($q) => $q->whereNotIn('co.status', [1, 6, 8, 9, 10])
            : fn ($q) => $q->where('co.status', 1)
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('transaction as tx')
                    ->whereColumn('tx.contract', 'co.id')->whereNull('tx.deletedAt'));
        [$from, $to] = [$params['from'], $params['to']];
        $toExclusive = $this->matrixSupport->monthExclusiveStart($to);

        $vatPercent = \App\Support\VatRate::percentOrDefault();
        $defaultDs = (float) \App\Models\SystemSetting::value('commission.default_ds_percent', 100);

        $contracts = DB::table('contract as co')
            ->join('program as pg', 'pg.id', '=', 'co.program')
            ->whereNull('co.deletedAt')
            ->whereRaw("co.\"$dateCol\" IS NOT NULL")
            ->whereRaw("co.\"$dateCol\"::date >= ?", [$from . '-01'])
            ->whereRaw("co.\"$dateCol\"::date < ?", [$toExclusive])
            ->where($statusFn)
            ->when(! empty($params['suppliers']), fn ($q) =>
                $q->whereIn(DB::raw($this->matrixSupport->resolvedSupplierSql()), $params['suppliers']))
            ->when(! empty($params['products']), fn ($q) =>
                $q->whereIn('co.product', $params['products']))
            ->when(! empty($params['structures']), fn ($q) =>
                $q->whereIn('co.consultant', $this->subtreeIds($params['structures'])))
            ->when(! empty($params['fcs']), fn ($q) =>
                $q->whereIn('co.consultant', $params['fcs']))
            ->select([
                'co.consultant as fc_id',
                'co.product as product_id',
                'co.program as program_id',
                DB::raw("TO_CHAR(DATE_TRUNC('month', co.\"$dateCol\"::date), 'YYYY-MM') as period_month"),
                DB::raw("co.\"$dateCol\"::date as cdate"),
                'co.ammount',
                'co.term',
                'pg.dsPercent as program_ds',
                DB::raw($this->matrixSupport->rateExpr($dateCol) . ' as rate'),
            ])
            ->get();

        $dsCache = [];
        $agg = []; // [fcId][productId][month] => ['revenue'=>, 'points'=>]
        foreach ($contracts as $r) {
            $amountRub   = (float) $r->ammount * (float) $r->rate;
            $amountNoVat = $vatPercent > 0 ? $amountRub / (1 + $vatPercent / 100) : $amountRub;

            $ds = $r->program_ds !== null ? (float) $r->program_ds : 0.0;
            if ($ds <= 0) {
                $key = $r->program_id . '|' . $r->term . '|' . $r->cdate;
                if (! array_key_exists($key, $dsCache)) {
                    $dsCache[$key] = \App\Services\CommissionCalculator::resolveLegacyDsCommission(
                        (int) $r->program_id, $r->term, null, (string) $r->cdate
                    );
                }
                $ds = (float) ($dsCache[$key] ?? 0);
            }
            if ($ds <= 0) $ds = $defaultDs;

            $rev = $amountNoVat * $ds / 100;
            $fcId = (int) $r->fc_id; $pid = (int) $r->product_id; $mo = $r->period_month;
            $agg[$fcId][$pid][$mo]['revenue'] = ($agg[$fcId][$pid][$mo]['revenue'] ?? 0) + $rev;
            $agg[$fcId][$pid][$mo]['points']  = ($agg[$fcId][$pid][$mo]['points'] ?? 0) + $rev / 100;
        }

        foreach ($rows as $r) {
            $f = $agg[(int) $r->fc_id][(int) $r->product_id][$r->period_month] ?? null;
            if ($f) {
                $r->revenue  = round($f['revenue'], 2);
                $r->bally    = round($f['points'], 2);
                $r->bally_lp = round($f['points'], 2); // прогнозные личные баллы
            }
        }
    }



    /**
     * GET /admin/reports/partner-matrix/lookups
     * structures — топ-консультанты (корни). fcs — ФК; если переданы
     * structures[], отдаём только потомков выбранных корней (каскад).
     */
    public function lookups(Request $request): JsonResponse
    {
        // «Структура» = любой ФК, у которого есть хотя бы один нижестоящий (не
        // только корни сети). Так можно выбрать структуру из середины дерева.
        $structures = DB::table('consultant as c')
            ->whereNull('c.dateDeleted')
            ->where('c.activity', '!=', 3) // не терминированные
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('consultant as ch')
                ->whereColumn('ch.inviter', 'c.id')->whereNull('ch.dateDeleted'))
            ->orderBy('c.personName')
            ->get(['c.id', 'c.personName as name']);

        $structureIds = array_filter(array_map('intval', (array) $request->input('structures', [])));

        $fcQuery = DB::table('consultant')->whereNull('dateDeleted');
        if (! empty($structureIds)) {
            $fcQuery->whereIn('id', $this->tree()->subtreeIds($structureIds));
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


    /**
     * Все ФК поддерева выбранных структур: сами структуры + все нижестоящие
     * (рекурсивно вниз по inviter). Пусто → пустой список.
     *
     * @param  array<int>  $structureIds
     * @return array<int>
     */
    private function subtreeIds(array $structureIds): array
    {
        return $this->tree()->subtreeIds($structureIds);
    }

    /** Единственная реализация обхода дерева по consultant.inviter. */
    private function tree(): \App\Services\ConsultantTreeService
    {
        return app(\App\Services\ConsultantTreeService::class);
    }

    /**
     * Карта fcId => ['rootId', 'rootName'] — под какую СТРУКТУРУ отнести ФК.
     *  - структуры выбраны: ближайший выбранный предок-или-сам (глава структуры
     *    входит в неё уровнем 0). ФК вне выбранных структур в карте отсутствует
     *    (значит, отфильтрован);
     *  - не выбраны: корень сети (structureRootMap) — прежнее поведение.
     *
     * @param  array<int>  $fcIds
     * @param  array<int>|null  $selected
     * @return array<int, array{rootId:int, rootName:?string}>
     */
    private function structureAssignMap(array $fcIds, ?array $selected): array
    {
        if (empty($fcIds)) return [];
        if (empty($selected)) {
            return $this->structureRootMap($fcIds);
        }
        $ids = implode(',', array_map('intval', array_unique($fcIds)));
        $sel = implode(',', array_map('intval', array_unique($selected)));

        $rows = DB::select("
            WITH RECURSIVE chain AS (
                SELECT id AS node, id AS cur, inviter, 0 AS depth
                FROM consultant WHERE id IN ($ids)
                UNION ALL
                SELECT ch.node, c.id, c.inviter, ch.depth + 1
                FROM chain ch JOIN consultant c ON c.id = ch.inviter
                WHERE ch.inviter IS NOT NULL AND ch.inviter <> 0 AND ch.depth < 50
            )
            SELECT DISTINCT ON (ch.node) ch.node AS consultant_id, ch.cur AS root_id,
                   c.\"personName\" AS root_name
            FROM chain ch JOIN consultant c ON c.id = ch.cur
            WHERE ch.cur IN ($sel)
            ORDER BY ch.node, ch.depth ASC
        ");

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->consultant_id] = [
                'rootId' => (int) $r->root_id,
                'rootName' => $r->root_name,
            ];
        }
        return $map;
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
    /** Порядок и подписи состояний для разбивки «Итого» (byState). */
    private const STATE_LABELS = ['inwork' => 'В работе', 'forecast' => 'Активировано', 'fact' => 'Факт'];
    private const STATE_ORDER  = ['inwork' => 0, 'forecast' => 1, 'fact' => 2];

    /**
     * @param bool $byState В режиме «Итого» 3-й уровень (под ФК) — не продукты,
     *   а разбивка по состояниям «В работе / Активировано / Факт».
     */
    private function assemblePartnerTree($rows, array $months, array $params, bool $byState = false): array
    {
        $fcIds = collect($rows)->pluck('fc_id')->unique()->map(fn ($x) => (int) $x)->all();

        // Структуры выбраны → группируем ФК под ближайшую выбранную структуру
        // (глава входит уровнем 0). Не выбраны → под корень сети.
        $selected = ! empty($params['structures']) ? array_map('intval', $params['structures']) : null;
        $rootMap = $this->structureAssignMap($fcIds, $selected);

        $structures = []; // rootId => node
        $grand = $this->emptyAgg();

        foreach ($rows as $r) {
            $fcId = (int) $r->fc_id;
            // Структуры выбраны, а ФК не входит ни в одну → отбрасываем.
            // Без выбора structureAssignMap всегда возвращает запись (корень/сам).
            $root = $rootMap[$fcId] ?? ($selected !== null ? null : ['rootId' => $fcId, 'rootName' => $r->fc_name]);
            if ($root === null) continue;
            $rid = $root['rootId'];

            $pid = (int) $r->product_id;
            $mo  = $r->period_month;
            $vals = [
                'volume'      => round((float) $r->volume, 2),
                'count'       => (int) $r->cnt,
                'revenue'     => round((float) $r->revenue, 2),
                'bally'       => round((float) $r->bally, 2),
                'ballyLP'     => round((float) $r->bally_lp, 2),
            ];
            // id клиентов ячейки — для distinct-подсчёта (см. emptyAgg/finalizeNode).
            $cids = ($r->client_ids ?? '') !== '' ? explode(',', (string) $r->client_ids) : [];

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

            // 3-й уровень: продукты (обычные разрезы) или состояния (Итого).
            // Ключ держим целочисленным (состояния → 0/1/2), чтобы не плодить
            // строковые оффсеты на shaped-array; подпись/порядок — через 'state'.
            if ($byState) {
                $st = $r->state ?? 'fact';
                $ckey = self::STATE_ORDER[$st] ?? 9;
                $childInit = ['productId' => $ckey, 'productName' => self::STATE_LABELS[$st] ?? $st, 'state' => $st];
            } else {
                $ckey = $pid;
                $childInit = ['productId' => $pid, 'productName' => $r->product_name];
            }
            if (! isset($F['products'][$ckey])) {
                $F['products'][$ckey] = array_merge($this->emptyAgg(), $childInit);
            }
            $P = &$F['products'][$ckey];

            // Накопление на 3 уровнях + grand + помесячно. ballyLP (личные баллы,
            // = personalVolume) копим наравне с остальным; на уровне «команды»
            // фронт показывает прочерк (личная квалификация не суммируется).
            foreach (['volume', 'count', 'revenue', 'bally', 'ballyLP'] as $k) {
                $P[$k] += $vals[$k];
                $F[$k] += $vals[$k];
                $S[$k] += $vals[$k];
                $grand[$k] += $vals[$k];
                $P['monthly'][$mo][$k] = ($P['monthly'][$mo][$k] ?? 0) + $vals[$k];
                $F['monthly'][$mo][$k] = ($F['monthly'][$mo][$k] ?? 0) + $vals[$k];
                $S['monthly'][$mo][$k] = ($S['monthly'][$mo][$k] ?? 0) + $vals[$k];
                $grand['monthly'][$mo][$k] = ($grand['monthly'][$mo][$k] ?? 0) + $vals[$k];
            }
            // Клиенты — в distinct-множества на всех уровнях (за период + по месяцу).
            foreach ($cids as $cid) {
                $P['clientSet'][$cid] = true; $P['monthlyClients'][$mo][$cid] = true;
                $F['clientSet'][$cid] = true; $F['monthlyClients'][$mo][$cid] = true;
                $S['clientSet'][$cid] = true; $S['monthlyClients'][$mo][$cid] = true;
                $grand['clientSet'][$cid] = true; $grand['monthlyClients'][$mo][$cid] = true;
            }
            // ФК по месяцам — distinct id ФК (для колонки «ФК» в помесячном виде).
            $P['monthlyFcs'][$mo][$fcId] = true;
            $F['monthlyFcs'][$mo][$fcId] = true;
            $S['monthlyFcs'][$mo][$fcId] = true;
            $grand['monthlyFcs'][$mo][$fcId] = true;
            unset($S, $F, $P);
        }

        // Финализация: avgCheck, fcCount (distinct), список продуктов/ФК.
        $structOut = [];
        foreach ($structures as $rid => $S) {
            $fcCountStruct = count($S['fcSet']);
            $fcsOut = [];
            foreach ($S['fcs'] as $F) {
                $prodsOut = array_values(array_map(fn ($P) => $this->finalizeNode($P, 1), $F['products']));
                if ($byState) {
                    // Стабильный порядок состояний вместо сортировки по метрике.
                    usort($prodsOut, fn ($a, $b) =>
                        (self::STATE_ORDER[$a['state'] ?? ''] ?? 9) <=> (self::STATE_ORDER[$b['state'] ?? ''] ?? 9));
                }
                $fcsOut[] = array_merge($this->finalizeNode($F, 1), ['products' => $prodsOut]);
            }
            // сортировка ФК по выручке убыв.
            usort($fcsOut, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
            $structOut[] = array_merge($this->finalizeNode($S, $fcCountStruct), ['fcs' => $fcsOut]);
        }
        usort($structOut, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        // Показываем только месяцы с данными: у grand.monthly запись есть лишь
        // для месяцев, где были продажи. Так год-вид не тащит пустые будущие
        // месяцы (авг–дек) колонками из нулей. Если данных нет вовсе — оставляем
        // исходный список (фронт всё равно покажет «Нет данных»).
        $activeMonths = array_values(array_filter($months, fn ($mo) => isset($grand['monthly'][$mo])));

        return [
            'months' => $activeMonths ?: $months,
            'structures' => $structOut,
            'grand' => $this->finalizeNode($grand, $this->grandFcCount($structures)),
        ];
    }

    private function emptyAgg(): array
    {
        return [
            'volume' => 0, 'count' => 0, 'revenue' => 0,
            'bally' => 0, 'ballyLP' => 0, 'clientCount' => 0, 'monthly' => [],
            // Клиенты считаем через distinct-множества (id клиентов), а не
            // суммой по ячейкам — иначе клиент, купивший в нескольких
            // продуктах/месяцах/ФК, задваивается. clientSet — за весь период,
            // monthlyClients — по месяцам. Разворачиваются в число в finalizeNode.
            'clientSet' => [], 'monthlyClients' => [],
            // ФК по месяцам — distinct id ФК. Нужно для колонки «ФК» в
            // ПОМЕСЯЧНОМ виде (год/квартал/диапазон): раньше monthly.fcCount не
            // заполнялся → ФК показывался 0. Итоговый fcCount берётся из fcSet.
            'monthlyFcs' => [],
        ];
    }

    /** Доп. поля узла: средний чек + кол-во ФК (явно) + distinct-клиенты. */
    private function finalizeNode(array $node, int $fcCount): array
    {
        $node['avgCheck'] = $node['count'] > 0 ? round($node['volume'] / $node['count'], 2) : 0;
        $node['fcCount'] = $fcCount;
        $node['clientCount'] = count($node['clientSet'] ?? []);
        // «Баллы» = Выручка ÷ 100 на всех уровнях (реш. Лены 2026-07-31). В
        // «Факт» совпадает с суммой personalVolume (доход ДС ÷ 100); держим
        // единую формулу, чтобы Баллы всегда были согласованы с Выручкой.
        $node['bally'] = round(($node['revenue'] ?? 0) / 100, 2);
        foreach ($node['monthly'] as $mo => $m) {
            $node['monthly'][$mo]['avgCheck'] = ($m['count'] ?? 0) > 0
                ? round($m['volume'] / $m['count'], 2) : 0;
            $node['monthly'][$mo]['bally'] = round(($m['revenue'] ?? 0) / 100, 2);
            $node['monthly'][$mo]['clientCount'] = count($node['monthlyClients'][$mo] ?? []);
            $node['monthly'][$mo]['fcCount'] = count($node['monthlyFcs'][$mo] ?? []);
        }
        unset($node['fcSet'], $node['clientSet'], $node['monthlyClients'], $node['monthlyFcs']);
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
