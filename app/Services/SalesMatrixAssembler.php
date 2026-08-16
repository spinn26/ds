<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Сборка матриц продаж: раскладка строк по месяцам и расчёт прогнозных слоёв.
 *
 * Вынесено из ProductSalesMatrixController — там эта машинерия занимала около
 * семисот строк и обслуживала все шесть матриц сразу. Код перенесён дословно:
 * состав ячеек, округления и порядок полей прежние.
 *
 * ⚠ Это путь ДЕНЕГ. Здесь живут формулы, которые нельзя двигать без сверки с
 * SalesMatrixTest: прогнозная выручка ДС = сумма без НДС × %ДС / 100, баллы =
 * выручка / 100, а слои «Итого» не должны задваивать контракт — оплаченный
 * учитывается «Фактом», а не «Активировано».
 */
class SalesMatrixAssembler
{
    public function __construct(
        private readonly SalesMatrixSupport $matrixSupport,
    ) {}

    /**
     * Оставить только месяцы с данными (у grand.monthly есть запись). Иначе
     * год/квартал/диапазон тянут пустые будущие месяцы колонками из нулей.
     *
     * @param array<string> $months
     * @param array<string, mixed> $grand
     * @return array<string>
     */
    public function nonEmptyMonths(array $months, array $grand): array
    {
        // grand.monthly может быть предзаполнен нулями для всех месяцев (цикл
        // avgCheck), поэтому проверяем ненулевые данные, а не только isset.
        $active = array_values(array_filter($months, function ($mo) use ($grand) {
            $m = $grand['monthly'][$mo] ?? null;

            return $m !== null && (($m['revenue'] ?? 0) != 0 || ($m['volume'] ?? 0) != 0 || ($m['count'] ?? 0) != 0);
        }));

        return $active ?: $months;
    }

    /**
     * «В работе»: пересчитать баллы (ЛП) и прогнозную выручку ДС каждой ячейки
     * прямо из контрактов — транзакций ещё нет. Это прогноз: фактические
     * значения начислятся при активации.
     *
     * - Баллы: CommissionCalculator::computePoints (методика программы).
     * - Выручка (прогноз дохода ДС): amountNoVat × %ДС / 100 (gross-комиссия ДС).
     *
     * %ДС резолвится тем же приоритетом, что и в боевом расчёте:
     * program.dsPercent → тарифная сетка dsCommission (program × term × дата;
     * год КВ у контракта отсутствует, поэтому null — каскад ослабляет сам) →
     * фолбэк commission.default_ds_percent.
     *
     * Курс: управленческий курс на месяц создания, при его отсутствии —
     * последний курс из currencyRate (валютные контракты иначе считались бы
     * по курсу 1), иначе 1.
     */
    public function injectInWorkPoints(callable $base, array &$assembled, string $periodCol = 'createDate'): void
    {
        $vatPercent = \App\Support\VatRate::percentOrDefault();
        $defaultDs = (float) \App\Models\SystemSetting::value('commission.default_ds_percent', 100);

        $contracts = $base()
            ->select([
                'co.product as product_id',
                'co.program as program_id',
                DB::raw('TO_CHAR(DATE_TRUNC(\'month\', co."'.$periodCol.'"::date), \'YYYY-MM\') as period_month'),
                DB::raw('co."'.$periodCol.'"::date as cdate'),
                'co.ammount',
                'co.term',
                'pg.dsPercent as program_ds',
                DB::raw($this->matrixSupport->rateExpr($periodCol).' as rate'),
            ])
            ->get();

        $dsCache = [];
        // [metric][pid][pgid][mo], [metric][pid][mo], [metric][pid], [metric][mo], [metric]
        $prog = $prodM = $prodT = $grandM = ['points' => [], 'revenue' => []];
        $grandT = ['points' => 0.0, 'revenue' => 0.0];

        foreach ($contracts as $r) {
            $amountRub   = (float) $r->ammount * (float) $r->rate;
            $amountNoVat = $vatPercent > 0 ? $amountRub / (1 + $vatPercent / 100) : $amountRub;

            $ds = $r->program_ds !== null ? (float) $r->program_ds : 0.0;
            if ($ds <= 0) {
                $key = $r->program_id.'|'.$r->term.'|'.$r->cdate;
                if (! array_key_exists($key, $dsCache)) {
                    $dsCache[$key] = \App\Services\CommissionCalculator::resolveLegacyDsCommission(
                        (int) $r->program_id, $r->term, null, (string) $r->cdate
                    );
                }
                $ds = (float) ($dsCache[$key] ?? 0);
            }
            if ($ds <= 0) {
                $ds = $defaultDs;
            }

            // Выручка ДС = amountNoVat × %ДС / 100; баллы = выручка / 100.
            $rev = $amountNoVat * $ds / 100;
            $vals = [
                'points'  => $rev / 100,
                'revenue' => $rev,
            ];

            $pid = $r->product_id; $pgid = $r->program_id; $mo = $r->period_month;
            foreach ($vals as $k => $v) {
                $prog[$k][$pid][$pgid][$mo] = ($prog[$k][$pid][$pgid][$mo] ?? 0) + $v;
                $prodM[$k][$pid][$mo]       = ($prodM[$k][$pid][$mo] ?? 0) + $v;
                $prodT[$k][$pid]            = ($prodT[$k][$pid] ?? 0) + $v;
                $grandM[$k][$mo]            = ($grandM[$k][$mo] ?? 0) + $v;
                $grandT[$k]               += $v;
            }
        }

        $keys = ['points', 'revenue'];
        foreach ($assembled['rows'] as &$prodRow) {
            $pid = $prodRow['productId'];
            foreach ($keys as $k) {
                $prodRow[$k] = round($prodT[$k][$pid] ?? 0, 2);
            }
            foreach ($prodRow['monthly'] as $mo => &$cell) {
                foreach ($keys as $k) {
                    $cell[$k] = round($prodM[$k][$pid][$mo] ?? 0, 2);
                }
            }
            unset($cell);
            foreach ($prodRow['programs'] as &$pg) {
                $pgid = $pg['programId'];
                $sum = ['points' => 0.0, 'revenue' => 0.0];
                foreach ($pg['monthly'] as $mo => &$cell) {
                    foreach ($keys as $k) {
                        $cell[$k] = round($prog[$k][$pid][$pgid][$mo] ?? 0, 2);
                        $sum[$k] += $cell[$k];
                    }
                }
                unset($cell);
                foreach ($keys as $k) {
                    $pg[$k] = round($sum[$k], 2);
                }
            }
            unset($pg);
        }
        unset($prodRow);

        foreach ($keys as $k) {
            $assembled['grandTotals'][$k] = round($grandT[$k], 2);
        }
        foreach ($assembled['grandTotals']['monthly'] as $mo => &$cell) {
            foreach ($keys as $k) {
                $cell[$k] = round($grandM[$k][$mo] ?? 0, 2);
            }
        }
        unset($cell);
    }

    /**
     * Прогноз начисления по ячейкам (тултип): для каждой ячейки
     * (продукт/программа × месяц периода) — разбивка по месяцу $bucketCol со
     * счётчиком, объёмом (₽), прогнозной выручкой ДС и баллами. Транзакции не
     * используются — всё считается из контракта (как injectInWorkPoints),
     * курс с фолбэком на currencyRate (валютные конвертируются в ₽).
     *
     * - «В работе»:      $periodCol = createDate, $bucketCol = activation_forecast
     * - «Активировано»:  $periodCol = openDate,   $bucketCol = openDate (дата активации)
     */
    public function injectForecastBreakdown(callable $base, array &$payload, string $periodCol, string $bucketCol, string $targetKey = 'forecast'): void
    {
        $vatPercent = \App\Support\VatRate::percentOrDefault();
        $defaultDs = (float) \App\Models\SystemSetting::value('commission.default_ds_percent', 100);

        $rows = $base()
            ->select([
                'co.product as product_id',
                'co.program as program_id',
                DB::raw('TO_CHAR(DATE_TRUNC(\'month\', co."'.$periodCol.'"::date), \'YYYY-MM\') as period_month'),
                DB::raw('TO_CHAR(DATE_TRUNC(\'month\', co."'.$bucketCol.'"::date), \'YYYY-MM\') as bucket_month'),
                'co.ammount',
                'co.term',
                'pg.dsPercent as program_ds',
                DB::raw('co."'.$periodCol.'"::date as cdate'),
                DB::raw($this->matrixSupport->rateExpr($periodCol).' as rate'),
            ])
            ->get();

        $dsCache = [];
        $prog = $prod = $grand = [];
        $add = function (&$map, $bm, $vol, $rev, $pts) {
            $map[$bm]['count']   = ($map[$bm]['count'] ?? 0) + 1;
            $map[$bm]['volume']  = ($map[$bm]['volume'] ?? 0) + $vol;
            $map[$bm]['revenue'] = ($map[$bm]['revenue'] ?? 0) + $rev;
            $map[$bm]['points']  = ($map[$bm]['points'] ?? 0) + $pts;
        };

        foreach ($rows as $r) {
            $amountRub   = (float) $r->ammount * (float) $r->rate;
            $amountNoVat = $vatPercent > 0 ? $amountRub / (1 + $vatPercent / 100) : $amountRub;

            $ds = $r->program_ds !== null ? (float) $r->program_ds : 0.0;
            if ($ds <= 0) {
                $key = $r->program_id.'|'.$r->term.'|'.$r->cdate;
                if (! array_key_exists($key, $dsCache)) {
                    $dsCache[$key] = \App\Services\CommissionCalculator::resolveLegacyDsCommission(
                        (int) $r->program_id, $r->term, null, (string) $r->cdate
                    );
                }
                $ds = (float) ($dsCache[$key] ?? 0);
            }
            if ($ds <= 0) {
                $ds = $defaultDs;
            }

            // Выручка ДС = amountNoVat × %ДС / 100; баллы = выручка / 100.
            $rev = $amountNoVat * $ds / 100;
            $pts = $rev / 100;

            $pid = $r->product_id; $pgid = $r->program_id; $cm = $r->period_month; $bm = $r->bucket_month ?? '—';
            $add($prog[$pid][$pgid][$cm], $bm, $amountRub, $rev, $pts);
            $add($prod[$pid][$cm], $bm, $amountRub, $rev, $pts);
            $add($grand[$cm], $bm, $amountRub, $rev, $pts);
        }

        $toList = function ($map) {
            $out = [];
            foreach (($map ?? []) as $bm => $v) {
                $out[] = [
                    'month'   => $bm === '—' ? null : $bm,
                    'count'   => (int) $v['count'],
                    'volume'  => round((float) $v['volume'], 2),
                    'revenue' => round((float) $v['revenue'], 2),
                    'points'  => round((float) $v['points'], 2),
                ];
            }
            usort($out, fn ($a, $b) => ($a['month'] ?? '9999') <=> ($b['month'] ?? '9999'));

            return $out;
        };

        foreach ($payload['rows'] as &$prodRow) {
            $pid = $prodRow['productId'];
            foreach ($prodRow['monthly'] as $cm => &$cell) {
                $cell[$targetKey] = $toList($prod[$pid][$cm] ?? []);
            }
            unset($cell);
            foreach ($prodRow['programs'] as &$pg) {
                foreach ($pg['monthly'] as $cm => &$cell) {
                    $cell[$targetKey] = $toList($prog[$pid][$pg['programId']][$cm] ?? []);
                }
                unset($cell);
            }
            unset($pg);
        }
        unset($prodRow);
        foreach ($payload['grandTotals']['monthly'] as $cm => &$cell) {
            $cell[$targetKey] = $toList($grand[$cm] ?? []);
        }
        unset($cell);
    }

    /**
     * Общая сборка матрицы продукт×программа×месяц из строк агрегации
     * (одинаковый формат строк у quarterlyMatrix и inWorkMatrix).
     * $rows ожидает колонки: product_id/product_name/program_id/program_name/
     * period_month/volume/cnt/revenue/points/client_count/fc_count.
     */
    public function assembleMatrix($rows, callable $base, $periodExpr, $periodRaw, string $from, string $to, array $months): array
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

        // Клиенты — distinct (как ФК): один клиент может иметь несколько
        // контрактов/продуктов, поэтому суммировать client_count по ячейкам
        // нельзя — итоги задвоятся. Считаем уникальных клиентов отдельно.
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
            ->whereNotNull('pg.providerName')->distinct()->orderBy('pg.providerName')->pluck('pg.providerName');
        $allProducts = $base()
            ->join('product as p', 'p.id', '=', 'co.product')
            ->select('p.id', 'p.name')->distinct()->orderBy('p.name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]);

        return [
            'period'      => ['from' => $from, 'to' => $to, 'months' => $this->nonEmptyMonths($months, $grand)],
            'rows'        => $result,
            'grandTotals' => $grand,
            'suppliers'   => $allSuppliers->values(),
            'products'    => $allProducts->values(),
        ];
    }

    /** Слой-прогноз (В работе / Активировано): метрики по продукт×месяц.
     *  volume/count/clients/fc — SQL; revenue/points — прогноз из контракта. */
    public function totalLayerForecast(callable $base, string $periodCol): array
    {
        $periodExpr = 'TO_CHAR(DATE_TRUNC(\'month\', co."'.$periodCol.'"::date), \'YYYY-MM\')';
        $rows = $base()
            ->select([
                'co.product as pid',
                DB::raw($periodExpr.' as m'),
                DB::raw('SUM(COALESCE(co.ammount,0) * '.$this->matrixSupport->rateExpr($periodCol).') as volume'),
                DB::raw('COUNT(DISTINCT co.id) as cnt'),
                DB::raw('COUNT(DISTINCT co.client) as cl'),
                DB::raw('COUNT(DISTINCT co.consultant) as fc'),
            ])
            ->groupBy('co.product', DB::raw('DATE_TRUNC(\'month\', co."'.$periodCol.'"::date)'))
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->pid]['m'][$r->m] = [
                'volume' => round((float) $r->volume, 2), 'count' => (int) $r->cnt,
                'clientCount' => (int) $r->cl, 'fcCount' => (int) $r->fc,
                'revenue' => 0, 'points' => 0,
            ];
        }

        // Прогноз выручки/баллов из контракта (как injectInWorkPoints).
        $vat = \App\Support\VatRate::percentOrDefault();
        $defaultDs = (float) \App\Models\SystemSetting::value('commission.default_ds_percent', 100);
        $contracts = $base()
            ->select([
                'co.product as pid', 'co.program as program_id',
                DB::raw($periodExpr.' as m'),
                'co.ammount', 'co.term', 'pg.dsPercent as program_ds',
                DB::raw('co."'.$periodCol.'"::date as cdate'),
                DB::raw($this->matrixSupport->rateExpr($periodCol).' as rate'),
            ])
            ->get();
        $dsCache = [];
        foreach ($contracts as $c) {
            $amountRub = (float) $c->ammount * (float) $c->rate;
            $amountNoVat = $vat > 0 ? $amountRub / (1 + $vat / 100) : $amountRub;
            $ds = $c->program_ds !== null ? (float) $c->program_ds : 0.0;
            if ($ds <= 0) {
                $key = $c->program_id.'|'.$c->term.'|'.$c->cdate;
                if (! array_key_exists($key, $dsCache)) {
                    $dsCache[$key] = \App\Services\CommissionCalculator::resolveLegacyDsCommission(
                        (int) $c->program_id, $c->term, null, (string) $c->cdate
                    );
                }
                $ds = (float) ($dsCache[$key] ?? 0);
            }
            if ($ds <= 0) {
                $ds = $defaultDs;
            }
            $rev = $amountNoVat * $ds / 100;
            if (isset($map[$c->pid]['m'][$c->m])) {
                $map[$c->pid]['m'][$c->m]['revenue'] += $rev;
                $map[$c->pid]['m'][$c->m]['points'] += $rev / 100;
            }
        }

        return $map;
    }

    /** Слой «Факт»: метрики по продукт×месяц транзакции (revenue/points из транзакций). */
    public function totalLayerFact(callable $base): array
    {
        $rows = $base()
            ->select([
                'co.product as pid',
                't.dateMonth as m',
                // Объём факта = сумма транзакций (t.amountRUB). Раньше тут был
                // co.ammount×rate на запросе с грануляцией по ТРАНЗАКЦИЯМ → сумма
                // контракта задваивалась на число его транзакций (объём ×~2.7,
                // расходился с партнёрским отчётом). Кол-во/выручка/баллы — уже
                // per-transaction, поэтому совпадали.
                DB::raw('SUM(COALESCE(t."amountRUB",0)) as volume'),
                DB::raw('COUNT(DISTINCT t.id) as cnt'),
                DB::raw('SUM(COALESCE(t."commissionsAmountRUB",0)) as revenue'),
                DB::raw('SUM(COALESCE(t."personalVolume",0)) as points'),
                DB::raw('COUNT(DISTINCT co.client) as cl'),
                DB::raw('COUNT(DISTINCT co.consultant) as fc'),
            ])
            ->groupBy('co.product', 't.dateMonth')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->pid]['m'][$r->m] = [
                'volume' => round((float) $r->volume, 2), 'count' => (int) $r->cnt,
                'revenue' => round((float) $r->revenue, 2), 'points' => round((float) $r->points, 2),
                'clientCount' => (int) $r->cl, 'fcCount' => (int) $r->fc,
            ];
        }

        return $map;
    }

    /**
     * DISTINCT ФК/клиентов для «Итого» — union по 3 слоям.
     * Суммировать fc/client по слоям и месяцам нельзя: один ФК/клиент бывает
     * и в «Активировано», и в «Факт», и в нескольких месяцах → задвоение.
     * Тянем «события» (продукт, месяц, ФК, клиент) из всех слоёв и считаем
     * уникальных на каждом уровне (продукт×месяц / продукт / месяц / гранд /
     * продукт×слой). Возвращает наборы id (для подсчёта через count()).
     */
    public function totalDistinctCounts(callable $inworkBase, callable $actBase, callable $factBase): array
    {
        $pm = []; $p = []; $mo = []; $g = ['fc' => [], 'cl' => []]; $pl = [];
        $add = function ($pid, $m, $layer, $fc, $cl) use (&$pm, &$p, &$mo, &$g, &$pl) {
            foreach (['fc' => $fc, 'cl' => $cl] as $f => $id) {
                if ($id === null) continue;
                $pm[$pid][$m][$f][$id] = 1;
                $p[$pid][$f][$id] = 1;
                $mo[$m][$f][$id] = 1;
                $g[$f][$id] = 1;
                $pl[$pid][$layer][$f][$id] = 1;
            }
        };
        $pull = function (callable $base, string $periodSql, string $layer) use ($add) {
            foreach ($base()->select([
                'co.product as pid',
                DB::raw($periodSql.' as m'),
                'co.consultant as fc',
                'co.client as cl',
            ])->get() as $r) {
                $add($r->pid, $r->m, $layer, $r->fc, $r->cl);
            }
        };
        $pull($inworkBase, 'TO_CHAR(DATE_TRUNC(\'month\', co."createDate"::date), \'YYYY-MM\')', 'inwork');
        $pull($actBase,    'TO_CHAR(DATE_TRUNC(\'month\', co."openDate"::date), \'YYYY-MM\')', 'activated');
        foreach ($factBase()->select(['co.product as pid', 't.dateMonth as m', 'co.consultant as fc', 'co.client as cl'])->get() as $r) {
            $add($r->pid, $r->m, 'fact', $r->fc, $r->cl);
        }

        return compact('pm', 'p', 'mo', 'g', 'pl');
    }
}
