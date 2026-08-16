<?php

namespace App\Http\Controllers\Api;

use App\Services\ManualDraftPreviewService;
use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Services\CommissionCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Раздел «Ручной ввод транзакций» (spec ✅Транзакции.md).
 * Двухзонный воркфлоу: верх — поиск контрактов, низ — рабочие черновики
 * в `transaction_draft` с превью-расчётом и фиксацией в боевую `transaction`.
 *
 * Превью-расчёт мирорит CommissionCalculator, но ничего не пишет в БД,
 * что позволяет показывать суммы и цепочку наставников в реальном времени
 * по мере ввода данных. Фиксация делает реальный insert + запускает каскад.
 */
class ManualTransactionController extends Controller
{
    use PaginatesRequests;

    public function __construct(
        private readonly CommissionCalculator $calculator,
        private readonly ManualDraftPreviewService $preview,
    ) {}

    /** Поиск контрактов для верхней таблицы. */
    public function searchContracts(Request $request): JsonResponse
    {
        $q = DB::table('contract as c')
            ->leftJoin('program as pr', 'pr.id', '=', 'c.program')
            ->whereNull('c.deletedAt');

        if ($request->filled('consultantName')) {
            $q->where('c.consultantName', 'ilike', '%' . $request->consultantName . '%');
        }
        if ($request->filled('clientName')) {
            $q->where('c.clientName', 'ilike', '%' . $request->clientName . '%');
        }
        if ($request->filled('number')) {
            $q->where('c.number', 'ilike', '%' . $request->number . '%');
        }
        // Per user: транзакция не может быть без номера контракта.
        // ~398 legacy-контрактов без c.number отфильтровываем — на них
        // нельзя завести транзакцию (некуда привязать). Индекс
        // contract_number_idx (partial WHERE number IS NOT NULL) ускоряет
        // запрос на ~17k активных контрактов.
        $q->whereNotNull('c.number')->where('c.number', '!=', '');
        if ($request->filled('product')) {
            $q->where('c.product', $request->product);
        }
        if ($request->filled('program')) {
            $q->where('c.program', $request->program);
        }
        if ($request->filled('supplier')) {
            // Выражение поставщика — то же, которым ниже собирается колонка
            // (legacy program.providerName + правило Insmart).
            \App\Support\SupplierResolver::applyFilter(
                $q,
                (string) $request->supplier,
                'c."productName"',
                \App\Support\SupplierResolver::sqlProviderExpr('pr', null)
            );
        }
        if ($request->filled('provider')) {
            $q->where('pr.vendorName', 'ilike', '%' . $request->provider . '%');
        }
        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            // Picker search = contract number + party names only.
            // productName/programName caused false hits on letter-combos — drop
            // them (they have dedicated `product`/`program` filters).
            $q->where(function ($w) use ($term) {
                $w->where('c.number', 'ilike', $term)
                  ->orWhere('c.consultantName', 'ilike', $term)
                  ->orWhere('c.clientName', 'ilike', $term);
            });
        }

        $total = $q->count();
        $rows = $q->orderByDesc('c.openDate')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get([
                'c.*',
                // Поставщик — канонический порядок (vendorName -> providerName),
                // как в остальных списках. Раньше здесь был только providerName,
                // и «Ручной ввод» показывал поставщика иначе, чем «Контракты».
                DB::raw(\App\Support\SupplierResolver::sqlProviderExpr('pr', null) . ' as "joinedSupplier"'),
                'pr.vendorName as joinedProvider',
            ]);

        $currencyIds = $rows->pluck('currency')->filter()->unique();
        $currencies = $currencyIds->isNotEmpty()
            ? DB::table('currency')->whereIn('id', $currencyIds)->pluck('symbol', 'id')
            : collect();

        $data = $rows->map(fn ($c) => [
            'id' => $c->id,
            // Гарантировано не-null: WHERE c.number IS NOT NULL добавлен выше.
            'number' => $c->number,
            'clientName' => $c->clientName,
            'consultantName' => $c->consultantName,
            'consultantId' => $c->consultant,
            'openDate' => $c->openDate,
            'term' => $c->term,
            'productId' => $c->product,
            'productName' => $c->productName,
            'programId' => $c->program,
            'programName' => $c->programName,
            // Insmart-продукты показываем поставщиком «Insmart» (страховщик
            // уходит в субпоставщика) — единообразно с отчётами (SupplierResolver).
            'supplierName' => \App\Support\SupplierResolver::resolve($c->productName, $c->joinedSupplier ?? null),
            'providerName' => $c->joinedProvider ?? null,
            'amount' => round((float) ($c->ammount ?? 0), 2),
            'currencyId' => $c->currency,
            'currencySymbol' => $c->currency ? ($currencies[$c->currency] ?? null) : null,
        ]);

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** Создать черновики из выбранных контрактов. */
    public function createDrafts(Request $request): JsonResponse
    {
        $request->validate([
            'contractIds' => ['required', 'array', 'min:1'],
            // exists защищает от создания draft'ов на несуществующих контрактах,
            // которые потом упадут на расчёте.
            'contractIds.*' => ['integer', 'exists:contract,id'],
        ]);

        $userId = $request->user()->id;
        $contracts = DB::table('contract')
            ->whereIn('id', $request->contractIds)
            ->whereNull('deletedAt')
            ->get();

        $created = [];
        foreach ($contracts as $c) {
            $id = DB::table('transaction_draft')->insertGetId([
                'contract' => $c->id,
                'consultant' => $c->consultant,
                'currency' => $c->currency,
                'currencyRate' => $c->currency ? $this->fetchCurrencyRate((int) $c->currency) : 1,
                'parameter' => null,
                'createdBy' => $userId,
                'createdAt' => now(),
                'updatedAt' => now(),
            ]);
            $created[] = $id;
        }

        return response()->json(['ids' => $created]);
    }

    /** Список текущих черновиков (видимых пользователю — все staff делят пул). */
    public function listDrafts(Request $request): JsonResponse
    {
        $rows = DB::table('transaction_draft as td')
            ->leftJoin('contract as c', 'c.id', '=', 'td.contract')
            ->leftJoin('product as p', 'p.id', '=', 'c.product')
            ->leftJoin('program as pr', 'pr.id', '=', 'c.program')
            ->leftJoin('currency as cur', 'cur.id', '=', 'td.currency')
            ->orderBy('td.createdAt')
            ->get([
                'td.*',
                'c.number as contractNumber',
                'c.clientName',
                'c.consultantName',
                'c.term as contractTerm',
                'c.product as productId',
                'c.productName',
                'c.program as programId',
                'c.programName',
                'pr.providerName as supplierName',
                'pr.vendorName as providerName',
                'cur.symbol as currencySymbol',
                'cur.nameRu as currencyName',
                // Флаги продукта обязательно тянем здесь же — иначе
                // serializeDraft дефолтит productHasProperty=true и фронт
                // показывает дропдаун «Свойство» даже для продуктов
                // вроде «Эволюция», у которых has_property=false.
                'p.has_property as productHasProperty',
                'p.has_term as productHasTerm',
                'p.has_year_kv as productHasYearKv',
            ]);

        $programIds = $rows->pluck('programId')->filter()->unique()->all();
        $paramsByProgram = $this->loadParametersForPrograms($programIds);

        $data = $rows->map(fn ($r) => $this->serializeDraft($r, $paramsByProgram));

        // Текущий НДС отдаём вместе со списком — фронту он нужен ДО расчёта,
        // чтобы конвертация «Своя комиссия» (С НДС → без НДС) была стабильной
        // (иначе при вводе до «Рассчитать» vatPercent=0 и сумма «улетает» в без-НДС).
        return response()->json([
            'data' => $data,
            'vatPercent' => \App\Support\VatRate::percentOrDefault(),
        ]);
    }

    /**
     * Обновить поле черновика. Превью НЕ пересчитывается — пользователь
     * сам жмёт «Рассчитать транзакции», чтобы увидеть новые цифры.
     * Любая правка инвалидирует существующее превью (стираем previewCalc).
     */
    public function updateDraft(Request $request, int $id): JsonResponse
    {
        $request->validate([
            // Отрицательная сумма — это СТОРНО (возврат клиента, отмена взноса).
            // Движок такие сделки считает корректно: доход ДС, ЛП и вся цепочка
            // комиссий уходят в минус, т.е. ранее начисленное вычитается (в базе
            // уже есть 52 такие сделки из легаси). Ноль по-прежнему бессмыслен и
            // отсекается ниже (! $draft->amount).
            'amount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'parameter' => ['nullable', 'string', 'max:50'],
            'yearKV' => ['nullable', 'integer'],
            'dsCommissionPercentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commissionOverride' => ['nullable', 'boolean'],
            'customCommission' => ['nullable', 'boolean'],
            // У сторно доход ДС тоже отрицательный — «своя комиссия» должна это
            // позволять, иначе возврат нельзя завести с ручным доходом ДС.
            'dsCommissionAbsolute' => ['nullable', 'numeric'],
        ]);

        $draft = DB::table('transaction_draft')->where('id', $id)->first();
        if (! $draft) {
            return response()->json(['message' => 'Черновик не найден'], 404);
        }

        $update = $request->only([
            'amount', 'currency', 'date', 'comment', 'parameter', 'yearKV',
            'dsCommissionPercentage', 'commissionOverride',
            'customCommission', 'dsCommissionAbsolute',
        ]);

        // Курс зависит от МЕСЯЦА СДЕЛКИ, поэтому пересчитываем его и при смене
        // валюты, и при смене даты. Раньше курс фиксировался один раз (при
        // создании черновика / смене валюты) и при переносе даты на другой месяц
        // оставался прежним.
        if ($request->filled('currency') || $request->filled('date')) {
            $currencyId = $request->filled('currency')
                ? (int) $request->currency
                : (int) ($draft->currency ?? \App\Support\CurrencyRates::RUB_CURRENCY_ID);
            $effectiveDate = $request->filled('date') ? $request->date : ($draft->date ?? null);

            if ($request->filled('currency')) {
                $update['currency'] = $currencyId;
            }
            // Курс в черновике — справочное поле (реальный берётся заново при
            // фиксации, см. calculateDrafts). Дыру в справочнике показываем
            // нулём и логом, а не 500-й на каждое редактирование черновика.
            $update['currencyRate'] = \App\Support\CurrencyRates::forDateOrDefault($currencyId, $effectiveDate, 0.0);
        }

        // Любое изменение полей инвалидирует ранее рассчитанное превью.
        $update['previewCalc'] = null;
        $update['updatedAt'] = now();
        DB::table('transaction_draft')->where('id', $id)->update($update);

        return response()->json($this->serializeDraft($this->loadDraftWithRefs($id)));
    }

    /**
     * Рассчитать (или пересчитать) превью для всех черновиков либо для
     * указанного списка ids. Запускается явно по клику «Рассчитать».
     * Зафиксированные строки не появляются здесь — они уже в transaction.
     */
    public function calculateDrafts(Request $request): JsonResponse
    {
        $ids = $request->input('ids');
        $query = DB::table('transaction_draft');
        if (is_array($ids) && count($ids)) $query->whereIn('id', $ids);
        $allIds = $query->pluck('id');

        $results = ['calculated' => 0, 'skipped' => 0];
        foreach ($allIds as $id) {
            $draft = $this->loadDraftWithRefs((int) $id);
            // Ноль — валидная сумма (нулевая транзакция), поэтому проверяем
            // именно незаполненность, а не falsy: 0 не должен отсекаться.
            if (! $draft || $draft->amount === null || $draft->amount === '' || ! $draft->date) {
                $results['skipped']++;
                continue;
            }
            $preview = $this->preview->compute($draft, fn (array $ids) => $this->loadParametersForPrograms($ids));
            DB::table('transaction_draft')->where('id', $id)->update([
                'previewCalc' => json_encode($preview, JSON_UNESCAPED_UNICODE),
                'updatedAt' => now(),
            ]);
            $results['calculated']++;
        }

        return response()->json($results);
    }

    public function deleteDraft(int $id): JsonResponse
    {
        DB::table('transaction_draft')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Дубль черновика — для случая нескольких одинаковых взносов по одному
     * контракту (напр. два 10 000 RUB на одну дату). Копируем заполненные
     * поля, сбрасываем previewCalc и commission-флаги — пусть оператор
     * пересчитает заново после правки.
     */
    public function duplicateDraft(Request $request, int $id): JsonResponse
    {
        $src = DB::table('transaction_draft')->where('id', $id)->first();
        if (! $src) return response()->json(['message' => 'Не найден'], 404);

        $newId = DB::table('transaction_draft')->insertGetId([
            'contract' => $src->contract,
            'consultant' => $src->consultant,
            'currency' => $src->currency,
            'currencyRate' => $src->currencyRate,
            'amount' => $src->amount,
            'date' => $src->date,
            'comment' => $src->comment,
            'parameter' => $src->parameter,
            'yearKV' => $src->yearKV,
            'dsCommissionPercentage' => $src->dsCommissionPercentage,
            // Намеренно НЕ копируем: previewCalc (требует пересчёта),
            // customCommission / dsCommissionAbsolute / commissionOverride
            // (флаги вводятся осознанно для каждой строки).
            'createdBy' => $request->user()->id,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return response()->json(['id' => $newId]);
    }

    public function clearDrafts(): JsonResponse
    {
        DB::table('transaction_draft')->delete();
        return response()->json(['ok' => true]);
    }

    /** Список уникальных поставщиков/провайдеров для фильтров. */
    public function suppliersAndProviders(): JsonResponse
    {
        // Insmart-продукты (названы «… Inssmart») сворачиваем в единого
        // поставщика «Insmart» — их providerName хранит конечного страховщика
        // (Армеец/БАСК/Верна…), а в UI поставщик должен быть «Insmart».
        // См. SupplierResolver — та же логика, что в отчётах/комиссиях.
        $supRows = DB::table('program as pr')
            ->leftJoin('product as p', 'p.id', '=', 'pr.product')
            ->whereNotNull('pr.providerName')
            ->whereNull('pr.dateDeleted')
            ->distinct()
            ->get(['pr.providerName', 'p.name as productName']);
        $suppliers = [];
        $hasInsmart = false;
        foreach ($supRows as $r) {
            if (\App\Support\SupplierResolver::isInsmartProduct($r->productName)) {
                $hasInsmart = true;
            } elseif ($r->providerName !== null && $r->providerName !== '') {
                $suppliers[$r->providerName] = true;
            }
        }
        $suppliers = array_keys($suppliers);
        sort($suppliers, SORT_NATURAL | SORT_FLAG_CASE);
        if ($hasInsmart) array_unshift($suppliers, 'Insmart');
        $suppliers = array_values($suppliers);

        $providers = DB::table('program')
            ->whereNotNull('vendorName')
            ->whereNull('dateDeleted')
            ->distinct()
            ->orderBy('vendorName')
            ->pluck('vendorName');
        return response()->json([
            'suppliers' => $suppliers,
            'providers' => $providers,
        ]);
    }

    /**
     * Доступные ставки %ДС для контракта (модалка «Изменить»).
     *
     * Per spec ✅Ручной ввод §2.2 + правка 2026-06-03: тарифы ИТА/Медлайф
     * должны выпадать согласно программе И сроку контракта. Без этого по
     * Medlife выпадало 1213 строк (17 программ), по ИТА EVO — 25 строк по
     * всем срокам вперемешку (ставка «1 год» = 15.5% при сроке 5 vs 77.5%
     * при сроке 25 — выбрать осмысленно нельзя). Ставка различается по
     * program × termContract × commissionCalcProperty (год выплаты КВ),
     * который выводим в подпись.
     *
     * program/term/date приходят query-параметрами из контракта/черновика.
     * Фильтр по date снимает исторические версии тарифа: у Medlife одна и та
     * же program+term+год хранится несколькими строками с разным окном
     * [date, dateFinish) (напр. 25.08% до 2023-10-01 и 26.49% после) — без
     * него оператору выпадает просроченная ставка.
     *
     * Прогрессивное ослабление, если самая узкая выборка пуста:
     * program+term+date → снять date → снять term. Снятый фильтр помечаем
     * (relaxedDate / relaxedTerm), чтобы UI предупредил оператора.
     */
    public function productRates(Request $request, int $productId): JsonResponse
    {
        $program = $request->filled('program') ? (int) $request->program : null;
        $term = $request->filled('term') ? (int) $request->term : null;
        $date = $request->filled('date') ? $request->date : null;

        $select = [
            'dc.id', 'dc.comission', 'dc.commissionAbsolute', 'dc.programName',
            'dc.termContract', 'dc.date', 'dc.dateFinish', 'cp.title as propertyTitle',
        ];

        // Спека ✅Ручной ввод транзакций (п.2.2): для ИТА/Medlife модалка
        // показывает «список ВСЕХ доступных ставок… например 5.69%, 6.01%» —
        // специалист выбирает нужный уровень сам. Поэтому по ДАТЕ НЕ фильтруем
        // (иначе видна лишь одна версия); дата нужна только чтобы пометить
        // версию, действующую на дату транзакции.
        $build = function (bool $withTerm) use ($productId, $program, $term, $select) {
            return DB::table('dsCommission as dc')
                ->leftJoin('commissionCalcProperty as cp', 'cp.id', '=', 'dc.commissionCalcProperty')
                ->where('dc.product', $productId)
                ->where('dc.active', true)
                ->whereNull('dc.dateDeleted')
                ->when($program, fn ($q) => $q->where('dc.program', $program))
                ->when($withTerm && $term !== null, fn ($q) => $q->where('dc.termContract', $term))
                ->orderBy('dc.termContract')
                ->orderBy('dc.commissionCalcProperty')
                ->orderByDesc('dc.date') // новейшая версия сверху
                ->get($select);
        };

        $relaxedTerm = false;
        $rates = $build(true);
        if ($rates->isEmpty() && $term !== null) {
            $relaxedTerm = true;
            $rates = $build(false);
        }

        // Помечаем версию, действующую на дату транзакции (для подсветки в UI).
        $rates = $rates->map(function ($r) use ($date) {
            $r->activeOnDate = $date !== null
                && ($r->date === null || $r->date <= $date)
                && ($r->dateFinish === null || $r->dateFinish > $date);
            return $r;
        })->values();

        return response()->json([
            'rates' => $rates,
            'relaxedTerm' => $relaxedTerm,
            'relaxedDate' => false, // по дате не фильтруем — все версии видны всегда
        ]);
    }

    /**
     * Зафиксировать выбранные черновики:
     *   - вставить запись в `transaction`
     *   - запустить CommissionCalculator (каскад)
     *   - удалить черновик
     */
    public function fixDrafts(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $freeze = app(\App\Services\PeriodFreezeService::class);

        $results = ['fixed' => [], 'errors' => []];
        foreach ($request->ids as $draftId) {
            $draft = $this->loadDraftWithRefs((int) $draftId);
            if (! $draft) {
                $results['errors'][] = ['id' => $draftId, 'reason' => 'Не найден'];
                continue;
            }
            // Ноль допустим — требуем лишь заполненности суммы и даты.
            if ($draft->amount === null || $draft->amount === '' || ! $draft->date) {
                $results['errors'][] = ['id' => $draftId, 'reason' => 'Заполните дату и сумму'];
                continue;
            }

            // Дубли по сумме+дате в транзакциях допустимы (несколько взносов по
            // одному контракту) — анти-дубль убран по требованию.

            // Заморозка периода — ДО вставки. Раньше строка ложилась в закрытый
            // месяц, а 422 прилетал уже из калькулятора (он вызывался вне
            // транзакции) → транзакция оставалась в БД с нулевыми комиссиями.
            $draftDate = \Carbon\Carbon::parse($draft->date);
            if ($freeze->isFrozen((int) $draftDate->year, (int) $draftDate->month)) {
                $results['errors'][] = [
                    'id' => $draftId,
                    'reason' => "Период {$draftDate->month}.{$draftDate->year} закрыт — внесение запрещено.",
                ];
                continue;
            }

            try {
                $txId = DB::transaction(function () use ($draft, $request) {
                    // Курс берём по дате сделки заново, а не из черновика: он мог
                    // быть зафиксирован до того, как оператор проставил/переставил
                    // дату (см. CurrencyRates — курс средневзвешенный ЗА МЕСЯЦ).
                    $rate = \App\Support\CurrencyRates::forDate(
                        $draft->currency ? (int) $draft->currency : null,
                        $draft->date
                    );
                    $amount = (float) $draft->amount;
                    $amountRub = $amount * $rate;

                    $usdRate = \App\Support\CurrencyRates::usdForDate($draft->date);
                    $amountUsd = $usdRate > 0 ? $amountRub / $usdRate : 0;

                    $date = \Carbon\Carbon::parse($draft->date);

                    // draft.parameter — это commissionCalcProperty.id, выбранный
                    // в дропдауне «Свойство». Для has_year_kv продуктов Свойство
                    // скрыто в UI — берём ID из yearKV автоматически.
                    $draftFull = $this->loadDraftWithRefs($draft->id);
                    $storedParam = $draft->parameter;
                    if (($draftFull?->productHasYearKv ?? false) && $storedParam === null && $draft->yearKV !== null) {
                        $yearNum = (int) $draft->yearKV;
                        $params = $this->loadParametersForPrograms([(int) $draft->contract])[$draft->contract] ?? [];
                        // loadParametersForPrograms keys by programId, not contractId — re-fetch
                        $programId = $draftFull?->programId ?? null;
                        if ($programId) {
                            $params2 = $this->loadParametersForPrograms([(int) $programId])[(int) $programId] ?? [];
                            foreach ($params2 as $p) {
                                if (preg_match('/(?<!\d)' . $yearNum . '(?!\d)/', $p['title'])) {
                                    $storedParam = (string) $p['id'];
                                    break;
                                }
                            }
                        }
                    }
                    // Выравниваем PK-сиквенс: после Directual-восстановления он
                    // отстаёт → insertGetId врезается в существующий id (duplicate
                    // transaction_pkey). setval идемпотентен и дёшев.
                    DB::statement("SELECT setval(pg_get_serial_sequence('transaction', 'id'), GREATEST((SELECT MAX(id) FROM transaction), 1))");
                    $txId = DB::table('transaction')->insertGetId([
                        'contract' => $draft->contract,
                        'amount' => $amount,
                        'amountRUB' => round($amountRub, 2),
                        'amountUSD' => round($amountUsd, 2),
                        'currency' => $draft->currency,
                        'currencyRate' => $rate,
                        'usdRate' => $usdRate,
                        'date' => $date,
                        'dateDay' => $date,
                        'dateMonth' => $date->format('Y-m'),
                        'dateYear' => $date->format('Y'),
                        'dateCreated' => now(),
                        'changedAt' => now(),
                        'comment' => $draft->comment ?: 'Ручной ввод #' . $request->user()->id,
                        'commissionCalcProperty' => $storedParam !== null && $storedParam !== ''
                            ? (int) $storedParam : null,
                        'score' => $draft->yearKV !== null ? (int) $draft->yearKV : null,
                        'dsCommissionPercentage' => $draft->dsCommissionPercentage,
                        'dsCommissionAbsolute' => $draft->dsCommissionAbsolute,
                        'customCommission' => $draft->customCommission ?: false,
                        'userChanged' => $request->user()->id,
                    ]);

                    // Расчёт цепочки — в той же транзакции: спека требует
                    // «частичное создание цепочки откатывает всю операцию».
                    // Раньше calculateForTransaction() звался снаружи, и при
                    // его ошибке transaction оставалась висеть без комиссий.
                    $calcResult = $this->calculator->calculateForTransaction($txId);
                    if (isset($calcResult['error'])) {
                        throw new \RuntimeException($calcResult['error']);
                    }

                    return $txId;
                });

                DB::table('transaction_draft')->where('id', $draftId)->delete();
                $results['fixed'][] = $txId;
            } catch (\Throwable $e) {
                $results['errors'][] = ['id' => $draftId, 'reason' => $e->getMessage()];
            }
        }

        return response()->json($results);
    }






    /**
     * Какие параметры «Свойство» (commissionCalcProperty) есть у ПРОГРАММЫ.
     * Список = distinct commissionCalcProperty активных dsCommission-строк
     * именно этой программы. Раньше скоуп был по продукту — и для программы
     * «Эволюция ГГА 10 лет» выпадали свойства соседних программ того же
     * продукта (3/4/5 год), хотя у неё самой только «Стандарт». Скоуп по
     * программе делает «Свойство» соответствующим программе.
     * Если у программы <=1 параметра — UI скроет дропдаун (выбор не нужен).
     */
    private function loadParametersForPrograms(array $programIds): array
    {
        $programIds = array_values(array_filter(array_map('intval', $programIds)));
        if (! count($programIds)) return [];

        $rows = DB::table('dsCommission as dc')
            ->join('commissionCalcProperty as cp', 'cp.id', '=', 'dc.commissionCalcProperty')
            ->where('dc.active', true)
            ->whereNull('dc.dateDeleted')
            ->whereIn('dc.program', $programIds)
            ->select(['dc.program as programId', 'cp.id', 'cp.title'])
            ->distinct()
            ->orderBy('cp.title')
            ->get();

        $byProgram = [];
        foreach ($rows as $r) {
            $byProgram[$r->programId][] = ['id' => $r->id, 'title' => $r->title];
        }
        return $byProgram;
    }

    /**
     * Курс на дату сделки. При создании черновика даты ещё нет — берём текущий
     * месяц; как только оператор проставит дату, курс пересчитается в updateDraft.
     */
    private function fetchCurrencyRate(int $currencyId, $date = null): float
    {
        // См. updateDraft: поле черновика справочное, фиксация берёт курс заново.
        return \App\Support\CurrencyRates::forDateOrDefault($currencyId, $date, 0.0);
    }

    private function loadDraftWithRefs(int $id): ?object
    {
        return DB::table('transaction_draft as td')
            ->leftJoin('contract as c', 'c.id', '=', 'td.contract')
            ->leftJoin('product as p', 'p.id', '=', 'c.product')
            ->leftJoin('program as pr', 'pr.id', '=', 'c.program')
            ->leftJoin('currency as cur', 'cur.id', '=', 'td.currency')
            ->where('td.id', $id)
            ->first([
                'td.*',
                'c.number as contractNumber',
                'c.clientName',
                'c.consultantName',
                'c.term as contractTerm',
                'c.product as productId',
                'c.productName',
                'c.program as programId',
                'c.programName',
                'pr.providerName as supplierName',
                'pr.vendorName as providerName',
                'cur.symbol as currencySymbol',
                'cur.nameRu as currencyName',
                'p.has_property as productHasProperty',
                'p.has_term as productHasTerm',
                'p.has_year_kv as productHasYearKv',
            ]);
    }

    private function serializeDraft(object $r, ?array $paramsByProgram = null): array
    {
        if ($paramsByProgram === null && ($r->programId ?? null)) {
            $paramsByProgram = $this->loadParametersForPrograms([(int) $r->programId]);
        }
        $params = $r->programId ? ($paramsByProgram[$r->programId] ?? []) : [];

        return [
            'id' => $r->id,
            'contractId' => $r->contract,
            'contractNumber' => $r->contractNumber ?? null,
            'clientName' => $r->clientName ?? null,
            'consultantId' => $r->consultant,
            'consultantName' => $r->consultantName ?? null,
            'contractTerm' => $r->contractTerm ?? null,
            'productId' => $r->productId ?? null,
            'productName' => $r->productName ?? null,
            'programId' => $r->programId ?? null,
            'programName' => $r->programName ?? null,
            'supplierName' => \App\Support\SupplierResolver::resolve($r->productName ?? null, $r->supplierName ?? null),
            'providerName' => $r->providerName ?? null,
            'amount' => $r->amount !== null ? (float) $r->amount : null,
            'currencyId' => $r->currency,
            'currencySymbol' => $r->currencySymbol ?? null,
            'currencyRate' => $r->currencyRate !== null ? (float) $r->currencyRate : null,
            'date' => $r->date,
            'comment' => $r->comment,
            'parameter' => $r->parameter,
            'availableParameters' => $params,
            'yearKV' => $r->yearKV,
            // Флаги конкретного продукта — фронт скрывает колонки
            // «Свойство», «Срок», «Год КВ» если у продукта они не релевантны.
            // Дефолты true для legacy-черновиков без productId.
            'productHasProperty' => isset($r->productHasProperty) ? (bool) $r->productHasProperty : true,
            'productHasTerm'     => isset($r->productHasTerm)     ? (bool) $r->productHasTerm     : true,
            'productHasYearKv'   => isset($r->productHasYearKv)   ? (bool) $r->productHasYearKv   : true,
            'dsCommissionPercentage' => $r->dsCommissionPercentage !== null ? (float) $r->dsCommissionPercentage : null,
            'commissionOverride' => (bool) $r->commissionOverride,
            'customCommission' => (bool) $r->customCommission,
            'dsCommissionAbsolute' => $r->dsCommissionAbsolute !== null ? (float) $r->dsCommissionAbsolute : null,
            'preview' => $r->previewCalc ? json_decode($r->previewCalc, true) : null,
        ];
    }
}
