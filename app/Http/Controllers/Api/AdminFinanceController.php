<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFinanceController extends Controller
{
    use PaginatesRequests;

    /**
     * Транзакции (per spec ✅Комиссии §1.2). Расширенный набор колонок:
     *  - Индикатор периода (frozen синий/серый)
     *  - № контракта, Открыт (контракта), Клиент, Партнёр
     *  - Дата транзакции, Комментарий
     *  - Свойство (commissionCalcProperty.title)
     *  - Год контракта (contract.term)
     *  - Год выплаты КВ (transaction.score)
     *  - Транзакция (исх валюта) + Транзакция в RUB
     *  - %DS, Доход DS, Доход DS RUB/USD
     *  - Без НДС RUB / USD
     */
    public function transactions(Request $request, \App\Services\PeriodFreezeService $freeze): JsonResponse
    {
        $query = DB::table('transaction as t')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->whereNull('t.deletedAt');

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($w) use ($term) {
                $w->where('c.consultantName', 'ilike', $term)
                  ->orWhere('c.number', 'ilike', $term)
                  ->orWhere('c.clientName', 'ilike', $term)
                  ->orWhere('t.id::text', 'ilike', $term);
            });
        }
        // Дополнительные раздельные фильтры per spec ✅Комиссии §1.1
        if ($request->filled('client')) {
            $query->where('c.clientName', 'ilike', '%' . $request->client . '%');
        }
        if ($request->filled('contract_number')) {
            $query->where('c.number', 'ilike', '%' . $request->contract_number . '%');
        }
        if ($request->filled('comment')) {
            $query->where('t.comment', 'ilike', '%' . $request->comment . '%');
        }
        if ($request->filled('supplier')) {
            // supplier — это program.providerName / vendorName на legacy. Делаем
            // join один раз, без N+1.
            $query->join('program as pr', 'pr.id', '=', 'c.program')
                  ->where(function ($w) use ($request) {
                      $sup = '%' . $request->supplier . '%';
                      $w->where('pr.providerName', 'ilike', $sup)
                        ->orWhere('pr.vendorName', 'ilike', $sup);
                  });
        }
        if ($request->filled('date_from')) {
            $query->where('t.date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('t.date', '<=', $request->date_to . ' 23:59:59');
        }
        if ($request->filled('month')) {
            $query->where('t.dateMonth', $request->month);
        }
        // hide_zero=1 — скрываем уплайн-строки с amountRUB=0 (margin=0).
        if ($request->boolean('hide_zero')) {
            $query->where('t.amountRUB', '>', 0);
        }
        // Фильтр «Партнёр в цепочке» per spec ✅Транзакции —
        // ищем все транзакции, у которых указанный консультант есть
        // в апплайне (вверх по inviter) консультанта контракта.
        if ($request->filled('chain_partner')) {
            $needle = '%' . $request->chain_partner . '%';

            // 1) находим всех консультантов, чьё имя матчит запросу
            $matchedIds = DB::table('consultant')
                ->where('personName', 'ilike', $needle)
                ->pluck('id');

            if ($matchedIds->isNotEmpty()) {
                // 2) для каждого спускаемся вниз по структуре (inviter дерево)
                //    и собираем все нижестоящие consultant.id — их транзакции
                //    нужно показать.
                $allDescendants = collect($matchedIds);
                $current = collect($matchedIds);
                for ($depth = 0; $depth < 20 && $current->isNotEmpty(); $depth++) {
                    $next = DB::table('consultant')
                        ->whereIn('inviter', $current)
                        ->whereNull('dateDeleted')
                        ->pluck('id');
                    $allDescendants = $allDescendants->merge($next)->unique();
                    $current = $next;
                }
                $query->whereIn('c.consultant', $allDescendants->all());
            } else {
                $query->whereRaw('1=0'); // no match → empty result
            }
        }

        $total = $query->count();

        // Агрегаты по всем строкам фильтра (не только видимая страница) —
        // для итоговой панели сверху таблицы. Запрос отдельный, чтобы
        // не тянуть JOIN'ы на справочники без надобности. Префикс t.
        // обязателен — contract тоже имеет колонку amountRUB
        // (ambiguous column).
        $aggregates = (clone $query)
            ->selectRaw('
                SUM(t."amountRUB") AS amount_rub,
                SUM(t."commissionsAmountRUB") AS commissions_rub,
                SUM(t."commissionsAmountUSD") AS commissions_usd,
                SUM(t."netRevenueRUB") AS net_rub,
                SUM(t."netRevenueUSD") AS net_usd
            ')
            ->first();

        $rows = $query->orderByDesc('t.date')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get([
                't.*',
                'c.number as contractNumber',
                'c.clientName as clientName',
                'c.consultantName as consultantName',
                'c.openDate as contractOpenDate',
                'c.term as contractTerm',
                'c.product as productId',
            ]);

        $currencyIds = $rows->pluck('currency')->filter()->unique();
        $currencies = $currencyIds->isNotEmpty()
            ? DB::table('currency')->whereIn('id', $currencyIds)->pluck('symbol', 'id')
            : collect();

        $propIds = $rows->pluck('commissionCalcProperty')->filter()->unique();
        $properties = $propIds->isNotEmpty()
            ? DB::table('commissionCalcProperty')->whereIn('id', $propIds)->pluck('title', 'id')
            : collect();

        // Config-флаги продукта — UI скрывает «Свойство»/«Срок»/«Год КВ»
        // у тех продуктов, где они не релевантны.
        $productIds = $rows->pluck('productId')->filter()->unique();
        $productFlags = $productIds->isNotEmpty()
            ? DB::table('product')->whereIn('id', $productIds)
                ->get(['id', 'has_property', 'has_term', 'has_year_kv'])
                ->keyBy('id')
            : collect();

        // Заморозка периодов — для индикатора цвета
        $periods = $rows->map(fn ($t) => [(int) $t->dateYear, (int) substr((string) $t->dateMonth, -2)])
            ->unique(fn ($p) => $p[0] . '-' . $p[1]);
        $frozenSet = collect();
        foreach ($periods as [$y, $m]) {
            if ($y && $m && $freeze->isFrozen($y, $m)) {
                $frozenSet->put("$y-$m", true);
            }
        }

        $data = $rows->map(function ($t) use ($currencies, $properties, $frozenSet, $productFlags) {
            $month = (int) substr((string) $t->dateMonth, -2);
            $year = (int) $t->dateYear;
            $isFrozen = $frozenSet->get("$year-$month", false);
            $flags = $t->productId ? ($productFlags[$t->productId] ?? null) : null;
            return [
                'id' => $t->id,
                'periodFrozen' => $isFrozen,
                'contract' => $t->contract,
                'contractNumber' => $t->contractNumber,
                'contractOpenDate' => $t->contractOpenDate,
                'contractTerm' => $flags && ! $flags->has_term ? null : $t->contractTerm,
                'clientName' => $t->clientName,
                'consultantName' => $t->consultantName,
                'amount' => round((float) ($t->amount ?? 0), 2),
                'amountRUB' => round((float) ($t->amountRUB ?? 0), 2),
                'amountUSD' => round((float) ($t->amountUSD ?? 0), 2),
                'date' => $t->date,
                'comment' => $t->comment,
                // Если у продукта has_property=false — показываем '—'
                // вместо реального значения (даже если оно есть в БД).
                // Это сделано чтобы UI чётко передавал «у этого продукта
                // понятия "свойство" не существует».
                'propertyTitle' => $flags && ! $flags->has_property ? null
                    : ($t->commissionCalcProperty ? ($properties[$t->commissionCalcProperty] ?? null) : null),
                'yearKV' => $flags && ! $flags->has_year_kv ? null : $t->score,
                'productHasProperty' => $flags ? (bool) $flags->has_property : true,
                'productHasTerm' => $flags ? (bool) $flags->has_term : true,
                'productHasYearKv' => $flags ? (bool) $flags->has_year_kv : true,
                'dsCommissionPercentage' => $t->dsCommissionPercentage !== null
                    ? round((float) $t->dsCommissionPercentage, 2) : null,
                'commissionsAmountRUB' => round((float) ($t->commissionsAmountRUB ?? 0), 2),
                'commissionsAmountUSD' => round((float) ($t->commissionsAmountUSD ?? 0), 2),
                'netRevenueRUB' => round((float) ($t->netRevenueRUB ?? 0), 2),
                'netRevenueUSD' => round((float) ($t->netRevenueUSD ?? 0), 2),
                'currencySymbol' => $t->currency ? ($currencies[$t->currency] ?? null) : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'total' => $total,
            'aggregates' => [
                'amountRUB' => round((float) ($aggregates->amount_rub ?? 0), 2),
                'commissionsAmountRUB' => round((float) ($aggregates->commissions_rub ?? 0), 2),
                'commissionsAmountUSD' => round((float) ($aggregates->commissions_usd ?? 0), 2),
                'netRevenueRUB' => round((float) ($aggregates->net_rub ?? 0), 2),
                'netRevenueUSD' => round((float) ($aggregates->net_usd ?? 0), 2),
            ],
        ]);
    }

    /** Комиссии */
    public function commissions(Request $request): JsonResponse
    {
        $query = DB::table('commission')->whereNull('deletedAt');

        if ($request->filled('consultant')) {
            $query->where('consultant', $request->consultant);
        }
        if ($request->filled('month')) {
            $query->where('dateMonth', $request->month);
        }
        if ($request->filled('search')) {
            $query->where('consultantPersonName', 'ilike', '%' . $request->search . '%');
        }
        // hide_zero=1 — скрываем строки уплайн-наставников с margin=0
        // (та же квалификация, что у нижестоящего → 0 ₽). Per user feedback —
        // включено по умолчанию из UI, чтобы убрать «шум» из тысяч 0,00-строк.
        if ($request->boolean('hide_zero')) {
            $query->where('amountRUB', '>', 0);
        }

        $total = $query->count();
        $rows = $query->orderByDesc('date')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        // Batch load consultant names
        $consultantIds = $rows->pluck('consultant')->filter()->unique();
        $consultantNames = $consultantIds->isNotEmpty()
            ? DB::table('consultant')->whereIn('id', $consultantIds)->pluck('personName', 'id')
            : collect();

        $data = $rows->map(fn ($c) => [
                'id' => $c->id,
                'consultant' => $c->consultant,
                'consultantName' => $c->consultant ? ($consultantNames[$c->consultant] ?? null) : null,
                'type' => $c->type,
                'amountRUB' => round((float) ($c->amountRUB ?? 0), 2),
                'personalVolume' => round((float) ($c->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($c->groupVolume ?? 0), 2),
                'groupBonusRub' => round((float) ($c->groupBonusRub ?? 0), 2),
                'percent' => $c->percent,
                'date' => $c->date,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Пул */
    public function pool(Request $request): JsonResponse
    {
        $query = DB::table('poolLog');

        if ($request->filled('month')) {
            // filter by date range if month provided
            $start = $request->month . '-01';
            $end = date('Y-m-t', strtotime($start));
            $query->whereBetween('date', [$start, $end]);
        }

        $total = $query->count();
        $data = $query->orderByDesc('date')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Квалификации */
    /**
     * Per spec ✅Квалификации.md §2: «Единая квалификация — у партнёра в
     * месяц только ОДИН показатель статуса». Возвращаем выбранный месяц +
     * сравнение с предыдущим месяцем (Блок 2 + Блок 3 в спеке).
     */
    public function qualifications(Request $request): JsonResponse
    {
        $month = $request->input('month', now()->format('Y-m'));
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        $prevStart = date('Y-m-01', strtotime($start . ' -1 month'));
        $prevEnd = date('Y-m-t', strtotime($prevStart));

        // Все consultant_id с записью за выбранный или предыдущий месяц
        $consultantQuery = DB::table('qualificationLog')
            ->whereNull('dateDeleted')
            ->where(function ($w) use ($start, $end, $prevStart, $prevEnd) {
                $w->whereBetween('date', [$start, $end])
                  ->orWhereBetween('date', [$prevStart, $prevEnd]);
            });

        if ($request->filled('search')) {
            $consultantQuery->where('consultantPersonName', 'ilike', '%' . $request->search . '%');
        }

        // Фильтр по статусу активности — переносим на server-side, чтобы
        // pagination и total были консистентны (раньше фильтровалось
        // в массиве на фронте поверх 25-row страницы — total врал).
        if ($request->filled('activity')) {
            $activityMap = ['active' => 1, 'terminated' => 3, 'registered' => 4, 'excluded' => 5];
            $activityId = $activityMap[$request->activity] ?? null;
            if ($activityId !== null) {
                $consultantQuery->whereIn('consultant', function ($sub) use ($activityId) {
                    $sub->select('id')->from('consultant')->where('activity', $activityId);
                });
            }
        }

        $consultantIds = $consultantQuery->distinct()->pluck('consultant')->all();

        // Фильтр «только ненулевые логи»
        if ($request->boolean('non_zero_only')) {
            $nonZeroIds = DB::table('qualificationLog')
                ->whereNull('dateDeleted')
                ->whereIn('consultant', $consultantIds)
                ->where(function ($w) {
                    $w->where('personalVolume', '>', 0)
                      ->orWhere('groupVolume', '>', 0);
                })
                ->pluck('consultant')
                ->unique()
                ->all();
            $consultantIds = array_values(array_intersect($consultantIds, $nonZeroIds));
        }

        $total = count($consultantIds);
        $offset = ($request->input('page', 1) - 1) * 25;
        $pageIds = array_slice($consultantIds, $offset, 25);

        if (empty($pageIds)) {
            return response()->json(['data' => [], 'total' => 0, 'monthLabel' => $month, 'prevMonthLabel' => substr($prevStart, 0, 7)]);
        }

        // Вытаскиваем все нужные строки одним запросом
        $logs = DB::table('qualificationLog')
            ->whereNull('dateDeleted')
            ->whereIn('consultant', $pageIds)
            ->where(function ($w) use ($start, $end, $prevStart, $prevEnd) {
                $w->whereBetween('date', [$start, $end])
                  ->orWhereBetween('date', [$prevStart, $prevEnd]);
            })
            ->get();

        $consultants = DB::table('consultant')
            ->whereIn('id', $pageIds)
            ->get(['id', 'personName', 'activity'])
            ->keyBy('id');

        // status_levels lookup
        $levels = DB::table('status_levels')->get()->keyBy('id');

        $resolveLevel = function ($nominal, $calculation) use ($levels) {
            $a = $nominal ? ($levels[$nominal] ?? null) : null;
            $b = $calculation ? ($levels[$calculation] ?? null) : null;
            if (! $a && ! $b) return null;
            if (! $a) return $b;
            if (! $b) return $a;
            return ($a->level >= $b->level) ? $a : $b;
        };

        $byConsultant = [];
        foreach ($logs as $l) {
            $isCurrent = $l->date >= $start && $l->date <= $end;
            $bucket = $isCurrent ? 'current' : 'previous';
            $level = $resolveLevel($l->nominalLevel, $l->calculationLevel);
            $byConsultant[$l->consultant][$bucket] = [
                'id' => $l->id,
                'personalVolume' => round((float) ($l->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($l->groupVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($l->groupVolumeCumulative ?? 0), 2),
                'levelId' => $level?->id,
                'levelTitle' => $level?->title,
                'levelNum' => $level?->level,
                'mandatoryGP' => (float) ($level?->mandatoryGP ?? 0),
                'date' => $l->date,
            ];
        }

        $activityMap = [1 => 'active', 3 => 'terminated', 4 => 'registered', 5 => 'excluded'];

        $data = [];
        foreach ($pageIds as $cid) {
            $cons = $consultants[$cid] ?? null;
            if (! $cons) continue;
            $data[] = [
                'consultant' => (int) $cid,
                'consultantName' => $cons->personName,
                'activity' => $activityMap[$cons->activity ?? 0] ?? 'unknown',
                'current' => $byConsultant[$cid]['current'] ?? null,
                'previous' => $byConsultant[$cid]['previous'] ?? null,
            ];
        }

        return response()->json([
            'data' => $data,
            'total' => $total,
            'monthLabel' => $month,
            'prevMonthLabel' => substr($prevStart, 0, 7),
        ]);
    }

    /** История квалификаций партнёра — все месяцы по убыванию даты. */
    public function qualificationHistory(int $consultantId): JsonResponse
    {
        $rows = DB::table('qualificationLog')
            ->where('consultant', $consultantId)
            ->whereNull('dateDeleted')
            ->orderByDesc('date')
            ->get();

        $levels = DB::table('status_levels')->get()->keyBy('id');

        $data = $rows->map(function ($r) use ($levels) {
            $a = $r->nominalLevel ? ($levels[$r->nominalLevel] ?? null) : null;
            $b = $r->calculationLevel ? ($levels[$r->calculationLevel] ?? null) : null;
            $level = (! $a) ? $b : ((! $b) ? $a : (($a->level >= $b->level) ? $a : $b));
            return [
                'date' => substr((string) $r->date, 0, 7),
                'personalVolume' => round((float) ($r->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($r->groupVolume ?? 0), 2),
                'groupVolumeCumulative' => round((float) ($r->groupVolumeCumulative ?? 0), 2),
                'levelNum' => $level?->level,
                'levelTitle' => $level?->title,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /admin/commissions/chain/{transactionId}
     * Цепочка commission rows для одной транзакции (для аккордеона на
     * странице Комиссии per spec ✅Комиссии §1.3).
     */
    public function commissionChain(int $transactionId): JsonResponse
    {
        $rows = DB::table('commission as cm')
            ->leftJoin('consultant as c', 'c.id', '=', 'cm.consultant')
            ->leftJoin('status_levels as sl', 'sl.id', '=', 'cm.calculationLevel')
            ->where('cm.transaction', $transactionId)
            ->whereNull('cm.deletedAt')
            ->orderBy('cm.chainOrder')
            ->select([
                'cm.id', 'cm.consultant', 'c.personName as consultantName',
                'cm.chainOrder', 'cm.percent',
                'cm.personalVolume', 'cm.groupVolume',
                'cm.groupBonus', 'cm.amountRUB',
                'sl.title as levelTitle', 'sl.level as levelNum',
            ])
            ->get();

        $tx = DB::table('transaction')->where('id', $transactionId)
            ->first(['netRevenueRUB', 'amountRUB', 'profitRUB']);
        $totalCommission = $rows->sum(fn ($r) => (float) ($r->amountRUB ?? 0));
        $profitDS = (float) ($tx?->profitRUB ?? (($tx?->netRevenueRUB ?? 0) - $totalCommission));

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'consultantId' => $r->consultant,
                'consultantName' => $r->consultantName,
                'chainOrder' => (int) ($r->chainOrder ?? 0),
                'percent' => (float) ($r->percent ?? 0),
                'levelTitle' => $r->levelTitle,
                'levelNum' => $r->levelNum,
                'personalVolume' => round((float) ($r->personalVolume ?? 0), 2),
                'groupVolume' => round((float) ($r->groupVolume ?? 0), 2),
                'groupBonus' => round((float) ($r->groupBonus ?? 0), 2),
                'amountRUB' => round((float) ($r->amountRUB ?? 0), 2),
            ])->all(),
            'profitDS' => round($profitDS, 2),
            'totalCommission' => round($totalCommission, 2),
        ]);
    }

    /**
     * Прочие начисления — CRUD.
     *
     * Источников данных два:
     * 1. `other_accruals` — новая таблица для ручных операций (источник
     *    «manual», полный CRUD).
     * 2. `commission` WHERE type='nonTransactional' — legacy-история
     *    «Прочих начислений» из Directual (источник «legacy», read-only).
     *
     * Для UI оба источника объединяются. Legacy-строки помечаются
     * `editable=false`, чтобы фронт скрывал кнопки edit/delete.
     */
    public function charges(Request $request): JsonResponse
    {
        // 1. Новая таблица — manual entries.
        // Postgres folds unquoted identifiers to lowercase, поэтому в
        // UNION-алиасах используем snake_case (consultant_name, accrual_date,
        // created_at) — иначе ORDER BY/SELECT в обёрточном fromSub() ломается.
        $newQuery = DB::table('other_accruals as oa')
            ->leftJoin('consultant as c', 'oa.consultant', '=', 'c.id')
            ->select([
                'oa.id', 'oa.consultant', DB::raw("'manual' as source"),
                DB::raw('c."personName" as consultant_name'),
                'oa.type', 'oa.amount', 'oa.points', 'oa.comment',
                DB::raw('oa.accrual_date as accrual_date'),
                DB::raw('oa.created_at as created_at'),
            ]);

        if ($request->filled('search')) $newQuery->where('c.personName', 'ilike', '%' . $request->search . '%');
        if ($request->filled('comment')) $newQuery->where('oa.comment', 'ilike', '%' . $request->comment . '%');
        if ($request->filled('type')) $newQuery->where('oa.type', $request->type);
        if ($request->filled('date_from')) $newQuery->where('oa.accrual_date', '>=', $request->date_from);
        if ($request->filled('date_to')) $newQuery->where('oa.accrual_date', '<=', $request->date_to);
        if ($request->filled('year')) $newQuery->whereRaw('EXTRACT(YEAR FROM oa.accrual_date) = ?', [(int) $request->year]);
        if ($request->filled('month')) $newQuery->whereRaw('EXTRACT(MONTH FROM oa.accrual_date) = ?', [(int) $request->month]);

        // 2. Legacy commission.type='nonTransactional' — history (read-only).
        $legacyQuery = DB::table('commission as cm')
            ->leftJoin('consultant as c', 'cm.consultant', '=', 'c.id')
            ->where('cm.type', 'nonTransactional')
            ->whereNull('cm.deletedAt')
            ->select([
                'cm.id', 'cm.consultant', DB::raw("'legacy' as source"),
                DB::raw('c."personName" as consultant_name'),
                DB::raw("'rub' as type"),
                DB::raw('COALESCE(cm."amountRUB", cm.amount, 0) as amount'),
                DB::raw('COALESCE(cm."personalVolume", 0) as points'),
                'cm.comment',
                DB::raw('cm.date as accrual_date'),
                DB::raw('cm."createdAt" as created_at'),
            ]);

        if ($request->filled('search')) $legacyQuery->where('c.personName', 'ilike', '%' . $request->search . '%');
        if ($request->filled('comment')) $legacyQuery->where('cm.comment', 'ilike', '%' . $request->comment . '%');
        if ($request->filled('type') && $request->type === 'points') {
            $legacyQuery->whereRaw('1=0');
        }
        if ($request->filled('date_from')) $legacyQuery->where('cm.date', '>=', $request->date_from);
        if ($request->filled('date_to')) $legacyQuery->where('cm.date', '<=', $request->date_to);
        if ($request->filled('year')) $legacyQuery->whereRaw('EXTRACT(YEAR FROM cm.date) = ?', [(int) $request->year]);
        if ($request->filled('month')) $legacyQuery->whereRaw('EXTRACT(MONTH FROM cm.date) = ?', [(int) $request->month]);

        $union = $newQuery->unionAll($legacyQuery);
        $sub = DB::query()->fromSub($union, 'u');
        $total = (clone $sub)->count();

        $rows = $sub->orderByDesc('accrual_date')
            ->orderByDesc('created_at')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        $data = $rows->map(fn ($r) => [
            'id' => $r->id,
            'source' => $r->source,
            'editable' => $r->source === 'manual',
            'consultantName' => $r->consultant_name ?: ('Консультант #' . $r->consultant),
            'consultant' => $r->consultant,
            'type' => $r->type,
            'amount' => round((float) $r->amount, 2),
            'points' => round((float) $r->points, 2),
            'comment' => $r->comment,
            'accrualDate' => $r->accrual_date,
            'createdAt' => $r->created_at,
        ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /**
     * Создать начисление. Если в запросе есть баллы — они сразу
     * прибавляются к consultant.personalVolume и
     * consultant.groupVolumeCumulative (spec ✅Прочие начисления Part 2 §3).
     * Рубли (`amount`) остаются только в other_accruals и влияют на
     * финансовый баланс через агрегацию в реестре выплат.
     *
     * Баллы НЕ каскадятся по inviter-цепочке по прямому указанию спеки:
     * "не должны генерировать финансовую комиссию для вышестоящих
     * наставников, как это происходит при обычной продаже".
     */
    public function storeCharge(Request $request): JsonResponse
    {
        $request->validate([
            'consultant' => 'required|integer|exists:consultant,id',
            // Per spec ✅Прочие начисления §3: тип = Рубли | Баллы.
            // Старые типы (bonus/penalty/compensation) тоже принимаются для
            // обратной совместимости — они мапятся в 'rub' семантику.
            'type' => 'required|in:rub,points,bonus,penalty,compensation',
            'amount' => 'required|numeric',
            'comment' => 'required|string|max:2000',
        ]);

        $consultantId = (int) $request->consultant;
        $type = $request->type;
        $value = (float) $request->amount;

        // Маршрутизация per spec §3: Рубли → amount, Баллы → points.
        $isPoints = $type === 'points';
        $amountRub = $isPoints ? 0.0 : $value;
        $points = $isPoints ? $value : 0.0;

        $id = DB::transaction(function () use ($request, $consultantId, $type, $amountRub, $points) {
            $id = DB::table('other_accruals')->insertGetId([
                'consultant' => $consultantId,
                'type' => $type,
                'amount' => $amountRub,
                'points' => $points,
                'comment' => $request->comment,
                'accrual_date' => $request->input('accrual_date', now()),
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($points != 0.0) {
                DB::table('consultant')
                    ->where('id', $consultantId)
                    ->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) + {$points}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) + {$points}"),
                    ]);
            }

            return $id;
        });

        return response()->json(['message' => 'Начисление создано', 'id' => $id], 201);
    }

    /**
     * Обновить начисление. Если баллы изменились — корректируем
     * personalVolume/groupVolumeCumulative на разницу (delta).
     */
    public function updateCharge(Request $request, int $id): JsonResponse
    {
        $row = DB::table('other_accruals')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Начисление не найдено'], 404);
        }

        $request->validate([
            'consultant' => 'required|integer|exists:consultant,id',
            'type' => 'required|in:rub,points,bonus,penalty,compensation',
            'amount' => 'required|numeric',
            'comment' => 'required|string|max:2000',
        ]);

        $type = $request->type;
        $value = (float) $request->amount;
        $isPoints = $type === 'points';
        $newAmountRub = $isPoints ? 0.0 : $value;
        $newPoints = $isPoints ? $value : 0.0;

        $oldPoints = (float) ($row->points ?? 0);
        $delta = $newPoints - $oldPoints;

        DB::transaction(function () use ($request, $id, $row, $type, $newAmountRub, $newPoints, $delta) {
            DB::table('other_accruals')->where('id', $id)->update([
                'consultant' => $request->consultant,
                'type' => $type,
                'amount' => $newAmountRub,
                'points' => $newPoints,
                'comment' => $request->comment,
                'accrual_date' => $request->input('accrual_date', $row->accrual_date),
                'updated_at' => now(),
            ]);

            // Если консультант не менялся — просто прибавляем дельту по баллам.
            // Если поменялся — у старого вычитаем oldPoints, у нового добавляем newPoints.
            if ($request->consultant == $row->consultant) {
                if ($delta != 0.0) {
                    DB::table('consultant')->where('id', $row->consultant)->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) + {$delta}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) + {$delta}"),
                    ]);
                }
            } else {
                if ($row->points != 0.0) {
                    DB::table('consultant')->where('id', $row->consultant)->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) - {$row->points}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) - {$row->points}"),
                    ]);
                }
                if ($newPoints != 0.0) {
                    DB::table('consultant')->where('id', $request->consultant)->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) + {$newPoints}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) + {$newPoints}"),
                    ]);
                }
            }
        });

        return response()->json(['message' => 'Начисление обновлено']);
    }

    /**
     * Удалить начисление с реверсивной транзакцией.
     * Per ✅Прочие начисления Part 2 §4: удаление должно откатить
     * изменения баланса (+100 баллов → удалили → −100 баллов обратно).
     */
    public function deleteCharge(int $id, Request $request): JsonResponse
    {
        // source=legacy → soft-delete commission row (Directual-история).
        // source=manual (default) → удалить из other_accruals + откатить баллы.
        $source = $request->query('source', 'manual');

        if ($source === 'legacy') {
            $row = DB::table('commission')->where('id', $id)->where('type', 'nonTransactional')->first();
            if (! $row) {
                return response()->json(['message' => 'Legacy-начисление не найдено'], 404);
            }
            DB::table('commission')->where('id', $id)->update([
                'deletedAt' => now(),
            ]);
            return response()->json([
                'message' => 'Legacy-начисление помечено удалённым (soft-delete)',
            ]);
        }

        $row = DB::table('other_accruals')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Начисление не найдено'], 404);
        }

        DB::transaction(function () use ($row) {
            $points = (float) ($row->points ?? 0);

            if ($points != 0.0) {
                DB::table('consultant')
                    ->where('id', $row->consultant)
                    ->update([
                        'personalVolume' => DB::raw("COALESCE(\"personalVolume\", 0) - {$points}"),
                        'groupVolumeCumulative' => DB::raw("COALESCE(\"groupVolumeCumulative\", 0) - {$points}"),
                    ]);
            }

            DB::table('other_accruals')->where('id', $row->id)->delete();
        });

        return response()->json(['message' => 'Начисление удалено, баланс откатан']);
    }

    /** Реестр выплат */
    public function payments(Request $request): JsonResponse
    {
        $query = DB::table('consultantPayment')
            ->join('consultantBalance', 'consultantPayment.consultantBalance', '=', 'consultantBalance.id')
            ->join('consultant', 'consultantBalance.consultant', '=', 'consultant.id')
            ->select(
                'consultantPayment.id',
                'consultantPayment.amount',
                'consultantPayment.paymentDate',
                'consultantPayment.status',
                'consultantPayment.comment',
                'consultant.personName',
                'consultant.id as consultantId'
            );

        if ($request->filled('search')) {
            $query->where('consultant.personName', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('status')) {
            $query->where('consultantPayment.status', (int) $request->status);
        }

        $total = $query->count();
        // Postgres sorts NULLs first on DESC by default; half the legacy rows
        // have no paymentDate, so the first 25 were a wall of prochеrк's.
        // Push real dates to the top; fall back to id for deterministic order.
        $data = $query->orderByRaw('"paymentDate" DESC NULLS LAST')
            ->orderByDesc('consultantPayment.id')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'consultantName' => $p->personName,
                'amount' => round((float) $p->amount, 2),
                'paymentDate' => $p->paymentDate,
                'status' => $p->status,
                'comment' => $p->comment,
            ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Отчёты */
    public function reports(): JsonResponse
    {
        return response()->json(['data' => [], 'message' => 'В разработке']);
    }

    /** Доступность отчётов */
    public function reportAvailability(): JsonResponse
    {
        return response()->json(['data' => [], 'message' => 'В разработке']);
    }

    /**
     * Валюты и НДС (per spec ✅Валюты и НДС.md):
     * - currencyRates: помесячные курсы (последние 24 месяца), с периодом
     *   и кодом валюты для редактирования.
     * - vat: история ставок, текущая (dateTo > now или max value) маркируется
     *   isCurrent для отображения «настоящее время».
     */
    public function currencies(): JsonResponse
    {
        $currencyMeta = DB::table('currency')->orderBy('id')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->nameRu ?? $c->nameEn ?? $c->currencyName ?? '',
                'symbol' => $c->symbol,
            ])->keyBy('id');

        // Курсы за последние 24 месяца, отсортированы по убыванию даты
        $minDate = now()->subMonths(24)->startOfMonth();
        $rates = DB::table('currencyRate')
            ->where('date', '>=', $minDate)
            ->orderByDesc('date')
            ->orderBy('currency')
            ->get()
            ->map(function ($r) use ($currencyMeta) {
                $meta = $currencyMeta[$r->currency] ?? null;
                return [
                    'id' => $r->id,
                    'currencyId' => $r->currency,
                    'symbol' => $meta['symbol'] ?? '',
                    'currencyName' => $meta['name'] ?? '',
                    'rate' => round((float) $r->rate, 8),
                    'date' => $r->date,
                    'period' => $r->date ? substr((string) $r->date, 0, 7) : null,
                ];
            });

        // VAT история
        $vatRows = DB::table('vat')->orderBy('dateFrom')->get();
        $now = now();
        $vat = $vatRows->map(function ($v) use ($now) {
            $isCurrent = $v->dateFrom <= $now && (! $v->dateTo || $v->dateTo >= $now->copy()->addYears(10));
            return [
                'id' => $v->id,
                'value' => (float) $v->value,
                'dateFrom' => $v->dateFrom,
                'dateTo' => $v->dateTo,
                'isCurrent' => $isCurrent,
            ];
        });

        return response()->json([
            'currencies' => $currencyMeta->values(),
            'currencyRates' => $rates,
            'vat' => $vat,
        ]);
    }

    /**
     * PATCH /admin/currencies/rates/{id} — обновить курс за период
     * + автоматический пересчёт всех валютных транзакций этого месяца
     * (per spec ✅Валюты и НДС §2.1 шаг 3 «Глобальный пересчёт»).
     */
    public function updateCurrencyRate(Request $request, int $id, \App\Services\CurrencyRecalculator $recalc): JsonResponse
    {
        $request->validate(['rate' => 'required|numeric|min:0']);
        $row = DB::table('currencyRate')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Курс не найден'], 404);

        DB::table('currencyRate')->where('id', $id)->update(['rate' => $request->rate]);

        $stats = $recalc->recalcForRate($id);

        return response()->json([
            'message' => 'Курс обновлён',
            'recalculation' => $stats,
        ]);
    }

    /**
     * POST /admin/currencies/vat — добавить новую ставку НДС с указанной даты.
     * Закрывает предыдущую ставку (выставляет dateTo в день перед новой dateFrom).
     */
    public function addVatRate(Request $request): JsonResponse
    {
        $request->validate([
            'value' => 'required|numeric|min:0',
            'dateFrom' => 'required|date',
        ]);

        DB::transaction(function () use ($request) {
            // Закрываем самую свежую активную ставку
            $current = DB::table('vat')->orderByDesc('dateFrom')->first();
            $newFrom = $request->dateFrom;
            $closeDate = (new \DateTime($newFrom))->modify('-1 day')->format('Y-m-d 23:59:59');
            if ($current) {
                DB::table('vat')->where('id', $current->id)->update(['dateTo' => $closeDate]);
            }
            DB::table('vat')->insert([
                'value' => $request->value,
                'dateFrom' => $newFrom,
                'dateTo' => '2050-01-01 00:00:00', // дальняя дата = «настоящее время»
            ]);
        });

        return response()->json(['message' => 'Ставка НДС добавлена']);
    }

    /** Импорт транзакций — placeholder */
    public function transactionImport(): JsonResponse
    {
        return response()->json(['data' => [], 'message' => 'В разработке']);
    }

    /**
     * Архив отчётов (per spec ✅Отчеты.md §1.2).
     */
    public function reportArchive(): JsonResponse
    {
        $rows = DB::table('report_archive')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
        $data = $rows->map(fn ($r) => [
            'id' => $r->id,
            'type' => $r->type,
            'status' => $r->status,
            'dateFrom' => $r->date_from,
            'dateTo' => $r->date_to,
            'createdAt' => $r->created_at,
            'fileUrl' => $r->status === 'ready' ? url('/api/v1/admin/reports/' . $r->id . '/download') : null,
            'errorMessage' => $r->error_message,
        ]);
        return response()->json(['data' => $data]);
    }

    /**
     * Запуск генерации отчёта (per spec ✅Отчеты §2.1 — async).
     * Создаём запись «generating» и диспатчим GenerateReportJob.
     * Воркер (queue:work) обработает её в фоне.
     */
    public function generateReport(\Illuminate\Http\Request $request, \App\Services\ReportGenerator $gen): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|max:60',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'activity' => 'nullable|integer',
        ]);

        $filters = array_filter(['activity' => $data['activity'] ?? null]);
        $id = $gen->reserveArchive(
            (string) $data['type'],
            (string) $data['date_from'],
            (string) $data['date_to'],
            $filters,
            $request->user()?->id,
        );

        \App\Jobs\GenerateReportJob::dispatch($id);

        return response()->json(['message' => 'Отчёт поставлен в очередь', 'id' => $id]);
    }

    /** Скачать готовый отчёт. */
    public function downloadReport(int $id)
    {
        $row = DB::table('report_archive')->where('id', $id)->first();
        if (! $row) {
            abort(404, 'Архив не найден');
        }
        if ($row->status !== 'ready') {
            abort(409, "Файл ещё не готов (статус: {$row->status})");
        }
        if (! $row->file_path) {
            abort(404, 'У записи отсутствует file_path');
        }

        // Резолвим путь напрямую через storage_path — так избегаем
        // расхождений между local/private диском Laravel 11 и тем, что
        // у нас в БД сохранён legacy-путь без префикса private/.
        $candidates = [
            \Storage::disk('local')->path($row->file_path),
            storage_path('app/' . $row->file_path),
            storage_path('app/private/' . $row->file_path),
        ];
        $absPath = null;
        foreach ($candidates as $p) {
            if (file_exists($p)) { $absPath = $p; break; }
        }
        if (! $absPath) {
            \Log::warning('downloadReport: файл не найден ни по одному пути', [
                'id' => $id, 'file_path' => $row->file_path, 'tried' => $candidates,
            ]);
            abort(404, 'Файл отсутствует на диске');
        }

        $filename = sprintf(
            'report-%s-%s-%s.csv',
            preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $row->type),
            substr((string) $row->date_from, 0, 10),
            substr((string) $row->date_to, 0, 10),
        );

        return response()->download($absPath, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    /**
     * Месячная финализация: применить штрафы Отрыв/ОП к комиссиям месяца.
     * Идемпотентно. Защищено от запуска по закрытому периоду.
     */
    public function finalizeMonth(Request $request, \App\Services\MonthlyFinalisationRunner $runner): JsonResponse
    {
        $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $stats = $runner->applyForMonth((int) $request->year, (int) $request->month);
        return response()->json($stats);
    }
}
