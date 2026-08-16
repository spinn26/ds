<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Список транзакций (/admin/transactions) — фильтры, итоги и сборка строк.
 *
 * Вынесено из AdminFinanceController: метод занимал 445 строк и был самым
 * большим в проекте. Код перенесён дословно — состав колонок, округления и
 * содержимое итогов прежние.
 *
 * ⚠ Это путь ДЕНЕГ. Здесь считаются «Прибыль», «Комиссия» и «Удержание ДС»,
 * и три места нельзя трогать без сверки с AdminTransactionsListingTest:
 *   - итоги считаются по ВСЕМУ отфильтрованному набору, а не по странице;
 *   - «Прибыль» — живая разность (доход ДС минус сумма цепочки), а не
 *     денормализованная колонка: она отстаёт после ночных штрафов;
 *   - «Комиссия» = вся цепочка, «Комиссия ФК» = только прямой партнёр
 *     (chainOrder=1); разница между ними — отрыв наставников.
 */
class TransactionsListingService
{
    /**
     * @param callable $sorter    применяет сортировку контроллера
     * @param callable $paginator применяет offset/limit контроллера
     * @return array<string, mixed>
     */
    public function build(Request $request, PeriodFreezeService $freeze, callable $sorter, callable $paginator): array
    {
        $query = $this->filtered($request);

        // Считаем ДО пагинации и по всему отфильтрованному набору.
        $total = $query->count();
        $totals = $this->totals($query);

        $rows = $this->page($query, $request, $sorter, $paginator);

        return [
            'data' => $this->present($rows, $freeze),
            'total' => $total,
            'aggregates' => $totals,
        ];
    }

    /** Запрос со всеми фильтрами, без сортировки и пагинации. */
    private function filtered(Request $request)
    {
        $query = DB::table('transaction as t')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->whereNull('t.deletedAt');

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            // Generic search box = partner (consultant) name + contract number.
            // Client name has its own `client` filter — keep it out of the OR so a
            // partner surname doesn't leak matches across the client column.
            $query->where(function ($w) use ($term) {
                $w->where('c.consultantName', 'ilike', $term)
                  ->orWhere('c.number', 'ilike', $term);
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
            $query->leftJoin('program as pr', 'pr.id', '=', 'c.program')
                  ->leftJoin('products_catalog as pcf', 'pcf.legacy_product_id', '=', 'c.product')
                  ->leftJoin('product as prodf', 'prodf.id', '=', 'c.product');
            \App\Support\SupplierResolver::applyFilter(
                $query,
                (string) $request->supplier,
                'COALESCE(pcf.name, prodf.name)',
                \App\Support\SupplierResolver::sqlProviderExpr('pr', 'pcf')
            );
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


        return $query;
    }

    /**
     * Итоги панели над таблицей — по всему отфильтрованному набору.
     *
     * ⚠ «Прибыль» здесь живая (доход ДС минус сумма цепочки), а не из
     * денормализованной колонки: та отстаёт после ночных штрафов. «Комиссия»
     * считается по ВСЕЙ цепочке, «Комиссия ФК» — только по прямому партнёру.
     *
     * @return array<string, float>
     */
    private function totals($query): array
    {

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
                SUM(COALESCE(t."amountRUB", 0) * COALESCE(t."dsCommissionPercentage", 0) / 100) AS commissions_gross_rub,
                SUM(t."netRevenueRUB") AS net_rub,
                SUM(t."netRevenueUSD") AS net_usd,
                SUM(t."profitRUB") AS profit_rub
            ')
            ->first();

        // Итог «Комиссия» — дедуп-сумма commission.amountRUB по всей цепочке
        // за ВСЕ отфильтрованные транзакции, той же логикой (transaction,
        // consultant, chainOrder), что и per-row колонка. Раньше здесь был
        // ярлык netRevenueRUB − profitRUB: при рассинхроне netRevenueRUB в
        // части транзакций (значение «раздуто») он давал абсурдный итог
        // (напр. 343к вместо 7к). Клон $query берём ДО ->select()/пагинации.
        $filteredTxIds = (clone $query)->select('t.id');
        $dedupCommission = DB::table('commission as cm')
            ->selectRaw('DISTINCT ON (cm.transaction, cm.consultant, cm."chainOrder") cm."amountRUB" AS a')
            ->whereIn('cm.transaction', $filteredTxIds)
            ->whereNull('cm.deletedAt')
            ->orderByRaw('cm.transaction, cm.consultant, cm."chainOrder", cm.id DESC');
        $commissionTotal = (float) DB::query()->fromSub($dedupCommission, 'd')->sum('d.a');

        // Σ комиссий ТОЛЬКО прямых партнёров (chainOrder=1) — итог колонки
        // «Комиссия», которая теперь показывает комиссию прямого партнёра
        // (получателя транзакции), а не всю цепочку. Дедуп по транзакции
        // (свежая строка по max id). Полная цепочка остаётся в «Удержание ДС».
        $directDedup = DB::table('commission as cm')
            ->selectRaw('DISTINCT ON (cm.transaction) cm."amountRUB" AS a')
            ->whereIn('cm.transaction', (clone $query)->select('t.id'))
            ->whereRaw('cm."chainOrder" = 1')
            ->whereNull('cm.deletedAt')
            ->orderByRaw('cm.transaction, cm.id DESC');
        $directCommissionTotal = (float) DB::query()->fromSub($directDedup, 'd')->sum('d.a');

        // Базовый SELECT — фиксируем заранее, чтобы потом можно было
        // addSelect() для commission-полей при сортировке по ним.
        return [
            'amountRUB' => round((float) ($aggregates->amount_rub ?? 0), 2),
            'commissionsAmountGrossRUB' => round((float) ($aggregates->commissions_gross_rub ?? 0), 2),
            'commissionsAmountRUB' => round((float) ($aggregates->commissions_rub ?? 0), 2),
            'commissionsAmountUSD' => round((float) ($aggregates->commissions_usd ?? 0), 2),
            'netRevenueRUB' => round((float) ($aggregates->net_rub ?? 0), 2),
            'netRevenueUSD' => round((float) ($aggregates->net_usd ?? 0), 2),
            // Live итог «Прибыль» = Σ Доход ДС без НДС − Σ комиссий цепочки
            // (совпадает с суммой per-row live-прибыли; denorm profit_rub
            // может отставать после ночных штрафов).
            'profitRUB' => round((float) ($aggregates->commissions_rub ?? 0) - $commissionTotal, 2),
            // Итог колонки «Комиссия ФК» = Σ комиссий прямых партнёров
            // (chainOrder=1) за отфильтрованные транзакции.
            'partnerCommissionRUB' => round($directCommissionTotal, 2),
            // Итог колонки «Комиссия» = Σ по всей цепочке (прямой + отрыв).
            'dsWithholdingRUB' => round($commissionTotal, 2),
        ];
    }

    /** Страница строк: select, сортировка по запросу, пагинация. */
    private function page($query, Request $request, callable $sorter, callable $paginator)
    {
        $query->select([
            't.*',
            'c.number as contractNumber',
            'c.clientName as clientName',
            'c.consultantName as consultantName',
            'c.openDate as contractOpenDate',
            'c.term as contractTerm',
            'c.product as productId',
            'c.program as programId',
        ]);

        // Сортировка по полям, которых нет в таблице transaction
        // (ЛП/ГП/Баллы/Комиссия/Удержание ДС лежат в commission). Чтобы
        // СУБД могла ORDER BY, добавляем коррелированные подзапросы в
        // SELECT — только если сортировка реально по этим колонкам, иначе
        // не платим за лишние подзапросы.
        $commissionSortMap = [
            'partnerPV' => 'partner_pv',
            'partnerGV' => 'partner_gv',
            'partnerBonus' => 'partner_bonus',
            // dsWithholdingRUB на UI — дубль колонки «Комиссия», sort по
            // тому же выражению.
            'partnerCommissionRUB' => 'partner_commission',
            'dsWithholdingRUB' => 'partner_commission',
        ];
        $requestedSort = $request->input('sort_by');

        if (isset($commissionSortMap[$requestedSort])) {
            // Один подзапрос для PV/GV/Bonus (одна и та же строка
            // chainOrder=1 с дедупом по max id), один — для Σ комиссий
            // по всей цепочке с дедупом по (consultant, chainOrder).
            // Дедуп идентичен тому, что в commissionChain() и батч-загрузке
            // ниже — суммы строго совпадают.
            $query->addSelect([
                DB::raw('(SELECT cm."personalVolume"
                          FROM commission cm
                          WHERE cm.transaction = t.id
                            AND cm."chainOrder" = 1
                            AND cm."deletedAt" IS NULL
                          ORDER BY cm.id DESC
                          LIMIT 1) AS partner_pv'),
                DB::raw('(SELECT cm."groupVolume"
                          FROM commission cm
                          WHERE cm.transaction = t.id
                            AND cm."chainOrder" = 1
                            AND cm."deletedAt" IS NULL
                          ORDER BY cm.id DESC
                          LIMIT 1) AS partner_gv'),
                DB::raw('(SELECT cm."groupBonus"
                          FROM commission cm
                          WHERE cm.transaction = t.id
                            AND cm."chainOrder" = 1
                            AND cm."deletedAt" IS NULL
                          ORDER BY cm.id DESC
                          LIMIT 1) AS partner_bonus'),
                DB::raw('(SELECT COALESCE(SUM(d."amountRUB"), 0)
                          FROM (
                              SELECT DISTINCT ON (cm.consultant, cm."chainOrder")
                                     cm."amountRUB"
                              FROM commission cm
                              WHERE cm.transaction = t.id
                                AND cm."deletedAt" IS NULL
                              ORDER BY cm.consultant, cm."chainOrder", cm.id DESC
                          ) d) AS partner_commission'),
            ]);

            $dir = strtolower((string) $request->input('sort_dir', 'desc'));
            $dir = $dir === 'asc' ? 'asc' : 'desc';
            // NULLS LAST — у транзакций без цепочки partner_* = NULL,
            // их естественно класть в конец независимо от направления.
            $query->orderByRaw("{$commissionSortMap[$requestedSort]} {$dir} NULLS LAST")
                  ->orderBy('t.id', 'desc'); // tie-breaker
        } else {
            $sorter($query, [
                'date' => 't.date',
                'contractNumber' => 'c.number',
                'contractOpenDate' => 'c."openDate"',
                'clientName' => 'c."clientName"',
                'consultantName' => 'co."personName"',
                'amount' => 't.amount',
                'amountRUB' => 't."amountRUB"',
                'commissionsAmountRUB' => 't."commissionsAmountRUB"',
                'netRevenueRUB' => 't."netRevenueRUB"',
                'dsCommissionPercentage' => 't."dsCommissionPercentage"',
                'yearKV' => 't.score',
                'profitRUB' => 't."profitRUB"',
            ], 't.date', 'desc');
        }

        $rows = $paginator($query)->get();

        return $rows;
    }
    /**
     * Всё связанное — одной пачкой на страницу: справочники, каталог,
     * цепочка комиссий и признак заморозки периода.
     *
     * @return array<string, mixed>
     */
    private function related($rows, PeriodFreezeService $freeze): array
    {

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
        // Также берём product.name для колонки «Продукт».
        $productIds = $rows->pluck('productId')->filter()->unique();
        $productFlags = $productIds->isNotEmpty()
            ? DB::table('product')->whereIn('id', $productIds)
                ->get(['id', 'name', 'has_property', 'has_term', 'has_year_kv'])
                ->keyBy('id')
            : collect();

        // Программа и поставщик — батчем по c.program.
        $programIds = $rows->pluck('programId')->filter()->unique();
        $programMeta = $programIds->isNotEmpty()
            ? DB::table('program')->whereIn('id', $programIds)
                ->get(['id', 'name', 'providerName', 'vendorName'])
                ->keyBy('id')
            : collect();

        // Актуальные названия продукта / поставщика / программы — из каталога
        // (products_catalog / programs_catalog), а не из legacy product/program:
        // после ремапа 2026-07-06 legacy-имена устарели/перемешаны (БКС СЖ / ГГА /
        // IPO вперемешку), каталог — единственный source of truth. Связь:
        // contract.product = products_catalog.legacy_product_id,
        // contract.program = programs_catalog.legacy_program_id.
        $productCatalog = $productIds->isNotEmpty()
            ? DB::table('products_catalog')
                ->whereNotNull('legacy_product_id')
                ->whereIn('legacy_product_id', $productIds)
                ->get(['legacy_product_id', 'name', 'provider_name'])
                ->keyBy('legacy_product_id')
            : collect();
        $programCatalog = $programIds->isNotEmpty()
            ? DB::table('programs_catalog')
                ->whereNotNull('legacy_program_id')
                ->whereIn('legacy_program_id', $programIds)
                ->get(['legacy_program_id', 'name'])
                ->keyBy('legacy_program_id')
            : collect();

        // Commission-цепочка батчем по transaction. Нужно для колонок:
        //  - Комиссия (сумма commission.amountRUB по всей цепочке)
        //  - ЛП / ГП / Баллы (по строке прямого партнёра chainOrder=1)
        // Дедуп: после повторных пересчётов в commission остаются дубли
        // (старые версии без deletedAt). Берём DISTINCT ON по
        // (transaction, consultant, chainOrder) — самую свежую (max id).
        // Это та же логика, что в commissionChain() выше.
        $txIds = $rows->pluck('id')->all();
        $commissionByTx = collect();
        $partnerRowByTx = collect();
        if (! empty($txIds)) {
            $placeholders = implode(',', array_fill(0, count($txIds), '?'));
            $deduped = DB::select("
                SELECT DISTINCT ON (cm.transaction, cm.consultant, cm.\"chainOrder\")
                    cm.transaction       AS transaction,
                    cm.\"chainOrder\"     AS \"chainOrder\",
                    cm.\"amountRUB\"      AS \"amountRUB\",
                    cm.\"personalVolume\" AS \"personalVolume\",
                    cm.\"groupVolume\"    AS \"groupVolume\",
                    cm.\"groupBonus\"     AS \"groupBonus\"
                FROM commission cm
                WHERE cm.transaction IN ($placeholders)
                  AND cm.\"deletedAt\" IS NULL
                ORDER BY cm.transaction, cm.consultant, cm.\"chainOrder\", cm.id DESC
            ", $txIds);

            foreach ($deduped as $r) {
                $txId = (int) $r->transaction;
                $commissionByTx->put(
                    $txId,
                    ($commissionByTx->get($txId, 0.0)) + (float) ($r->amountRUB ?? 0)
                );
                if ((int) $r->chainOrder === 1) {
                    $partnerRowByTx->put($txId, $r);
                }
            }
        }

        // Заморозка периодов — для индикатора цвета
        $periods = $rows->map(fn ($t) => [(int) $t->dateYear, (int) substr((string) $t->dateMonth, -2)])
            ->unique(fn ($p) => $p[0] . '-' . $p[1]);
        $frozenSet = collect();
        foreach ($periods as [$y, $m]) {
            if ($y && $m && $freeze->isFrozen($y, $m)) {
                $frozenSet->put("$y-$m", true);
            }
        }


        return ['currencies' => $currencies, 'properties' => $properties, 'productFlags' => $productFlags, 'programMeta' => $programMeta, 'productCatalog' => $productCatalog, 'programCatalog' => $programCatalog, 'commissionByTx' => $commissionByTx, 'partnerRowByTx' => $partnerRowByTx, 'frozenSet' => $frozenSet];
    }

    /** Строки страницы → массив для ответа. */
    private function present($rows, PeriodFreezeService $freeze)
    {
        ['currencies' => $currencies, 'properties' => $properties, 'productFlags' => $productFlags, 'programMeta' => $programMeta, 'productCatalog' => $productCatalog, 'programCatalog' => $programCatalog, 'commissionByTx' => $commissionByTx, 'partnerRowByTx' => $partnerRowByTx, 'frozenSet' => $frozenSet] = $this->related($rows, $freeze);

        $data = $rows->map(function ($t) use ($currencies, $properties, $frozenSet, $productFlags, $programMeta, $productCatalog, $programCatalog, $commissionByTx, $partnerRowByTx) {
            $month = (int) substr((string) $t->dateMonth, -2);
            $year = (int) $t->dateYear;
            $isFrozen = $frozenSet->get("$year-$month", false);
            $flags = $t->productId ? ($productFlags[$t->productId] ?? null) : null;
            $prog = $t->programId ? ($programMeta[$t->programId] ?? null) : null;
            $cat = $t->productId ? ($productCatalog[$t->productId] ?? null) : null;
            $progCat = $t->programId ? ($programCatalog[$t->programId] ?? null) : null;
            // Имя продукта — каталог первым (после ремапа 2026-07-06 это источник истины).
            // Без `?->` слева от `??`: обращение к свойству null внутри `??`
            // и так безопасно, а анализатор на лишний оператор ругается.
            $productName = $cat->name ?? $flags->name ?? null;
            // Поставщик — канонический порядок (см. SupplierResolver::sqlProviderExpr):
            // vendorName -> providerName -> каталог. Каталожный provider_name у части
            // продуктов хранит конечного страховщика («Ренессанс»), а поставщик — канал
            // («ГГА»), поэтому catalog-first здесь давал неверное значение.
            $rawProvider = ($prog?->vendorName ?: null)
                ?? ($prog?->providerName ?: null)
                ?? ($cat?->provider_name ?: null);
            $partnerRow = $partnerRowByTx->get((int) $t->id);
            $totalCommission = (float) $commissionByTx->get((int) $t->id, 0);
            return [
                'id' => $t->id,
                'periodFrozen' => $isFrozen,
                'contract' => $t->contract,
                'contractNumber' => $t->contractNumber,
                'contractOpenDate' => $t->contractOpenDate,
                'contractTerm' => $flags && ! $flags->has_term ? null : $t->contractTerm,
                'clientName' => $t->clientName,
                'consultantName' => $t->consultantName,
                // SupplierResolver still needed for Insmart-products (provider held
                // as end-insurer). Feed it the catalog-resolved product name.
                'providerName' => \App\Support\SupplierResolver::resolve($productName, $rawProvider),
                'productName' => $productName,
                'programName' => $progCat->name ?? $prog->name ?? null,
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
                // «Доход ДС без НДС» — commissionsAmountRUB считается калькулятором
                // от суммы без НДС (amountNoVat × %ДС). «Доход ДС» (до удержания НДС) =
                // от суммы с НДС = amountRUB × %ДС/100 (= без_НДС × (1+НДС)). Считаем из
                // полей самой транзакции, чтобы значение не зависело от истории ставки НДС.
                'commissionsAmountGrossRUB' => $t->dsCommissionPercentage !== null
                    ? round((float) ($t->amountRUB ?? 0) * (float) $t->dsCommissionPercentage / 100, 2)
                    : round((float) ($t->commissionsAmountRUB ?? 0), 2),
                'commissionsAmountRUB' => round((float) ($t->commissionsAmountRUB ?? 0), 2),
                'commissionsAmountUSD' => round((float) ($t->commissionsAmountUSD ?? 0), 2),
                // Доход ДС в валюте контракта (ТЗ 2026-08-07). null у рублёвых
                // и у старых строк, посчитанных до появления поля — фронт в
                // этом случае рисует прочерк, а не вводящий в заблуждение ноль.
                'commissionsAmountCurrency' => $t->commissionsAmountCurrency !== null
                    ? round((float) $t->commissionsAmountCurrency, 2) : null,
                // netRevenueRUB / profitRUB считаем В РЕАЛ-ТАЙМЕ из текущей
                // цепочки ($totalCommission), а не из denorm-полей транзакции.
                // Denorm-поля пишутся один раз при расчёте и НЕ обновляются
                // ночным штрафом отрыва/ОП (тот меняет только commission) —
                // поэтому «отставали». Live: profit = Доход ДС без НДС − Σ
                // комиссий; netRevenue = сумма без НДС − Σ комиссий. Цепочка
                // уже загружена батчем выше — лишних запросов нет.
                'netRevenueRUB' => (float) ($t->dsCommissionPercentage ?? 0) > 0
                    ? round((float) ($t->commissionsAmountRUB ?? 0) * 100 / (float) $t->dsCommissionPercentage - $totalCommission, 2)
                    : round((float) ($t->netRevenueRUB ?? 0), 2),
                'netRevenueUSD' => round((float) ($t->netRevenueUSD ?? 0), 2),
                'currencySymbol' => $t->currency ? ($currencies[$t->currency] ?? null) : null,
                // Поля из commission-цепочки.
                //
                // ⚠ Семантика колонок УТВЕРЖДЕНА владельцем 2026-07-13 — не менять
                // без явного запроса (её уже переигрывали дважды):
                //   «Комиссия»     = dsWithholdingRUB = Σ по ВСЕЙ цепочке.
                //                    Так требует спека ✅Комиссии: «Прибыль ДС =
                //                    Доход без НДС − Σ комиссий цепочки».
                //   «Комиссия ФК»  = partnerCommissionRUB = только прямой партнёр
                //                    (chainOrder=1) = жирная строка в попапе цепочки.
                //                    Скрыта по умолчанию. Разница с «Комиссией» =
                //                    отрыв вышестоящих наставников.
                //
                // partnerPV/GV/Bonus — показатели прямого партнёра
                // (chainOrder=1). Если цепочки нет — все нули.
                'partnerCommissionRUB' => $partnerRow ? round((float) ($partnerRow->amountRUB ?? 0), 2) : 0,
                'partnerPV' => $partnerRow ? round((float) ($partnerRow->personalVolume ?? 0), 2) : null,
                'partnerGV' => $partnerRow ? round((float) ($partnerRow->groupVolume ?? 0), 2) : null,
                'partnerBonus' => $partnerRow ? round((float) ($partnerRow->groupBonus ?? 0), 2) : null,
                // Live: Доход ДС без НДС − Σ комиссий цепочки (не denorm-поле).
                'profitRUB' => round((float) ($t->commissionsAmountRUB ?? 0) - $totalCommission, 2),
                // «Удержание ДС» = Σ amountRUB по ВСЕЙ цепочке выплат (прямой
                // партнёр + аплайн-отрыв) — сколько ДС всего выплачивает по
                // сделке. Именно эта сумма вычитается в «Прибыль»
                // (Доход без НДС − Σ цепочки). Разница с «Комиссией» = отрыв
                // вышестоящих наставников.
                'dsWithholdingRUB' => round($totalCommission, 2),
            ];
        });


        return $data;
    }
}
