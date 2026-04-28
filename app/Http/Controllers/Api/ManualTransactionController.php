<?php

namespace App\Http\Controllers\Api;

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

    public function __construct(private readonly CommissionCalculator $calculator) {}

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
        if ($request->filled('product')) {
            $q->where('c.product', $request->product);
        }
        if ($request->filled('program')) {
            $q->where('c.program', $request->program);
        }
        if ($request->filled('supplier')) {
            $q->where('pr.providerName', 'ilike', '%' . $request->supplier . '%');
        }
        if ($request->filled('provider')) {
            $q->where('pr.vendorName', 'ilike', '%' . $request->provider . '%');
        }
        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $q->where(function ($w) use ($term) {
                $w->where('c.number', 'ilike', $term)
                  ->orWhere('c.clientName', 'ilike', $term)
                  ->orWhere('c.consultantName', 'ilike', $term)
                  ->orWhere('c.productName', 'ilike', $term)
                  ->orWhere('c.programName', 'ilike', $term);
            });
        }

        $total = $q->count();
        $rows = $q->orderByDesc('c.openDate')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get([
                'c.*',
                'pr.providerName as joinedSupplier',
                'pr.vendorName as joinedProvider',
            ]);

        $currencyIds = $rows->pluck('currency')->filter()->unique();
        $currencies = $currencyIds->isNotEmpty()
            ? DB::table('currency')->whereIn('id', $currencyIds)->pluck('symbol', 'id')
            : collect();

        $data = $rows->map(fn ($c) => [
            'id' => $c->id,
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
            'supplierName' => $c->joinedSupplier ?? null,
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
            'contractIds.*' => ['integer'],
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
                'c.product as productId',
                'c.productName',
                'c.program as programId',
                'c.programName',
                'pr.providerName as supplierName',
                'pr.vendorName as providerName',
                'cur.symbol as currencySymbol',
                'cur.nameRu as currencyName',
            ]);

        $productIds = $rows->pluck('productId')->filter()->unique()->all();
        $paramsByProduct = $this->loadParametersForProducts($productIds);

        $data = $rows->map(fn ($r) => $this->serializeDraft($r, $paramsByProduct));

        return response()->json(['data' => $data]);
    }

    /**
     * Обновить поле черновика. Превью НЕ пересчитывается — пользователь
     * сам жмёт «Рассчитать транзакции», чтобы увидеть новые цифры.
     * Любая правка инвалидирует существующее превью (стираем previewCalc).
     */
    public function updateDraft(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'parameter' => ['nullable', 'string', 'max:50'],
            'yearKV' => ['nullable', 'integer'],
            'dsCommissionPercentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commissionOverride' => ['nullable', 'boolean'],
            'customCommission' => ['nullable', 'boolean'],
            'dsCommissionAbsolute' => ['nullable', 'numeric', 'min:0'],
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

        if ($request->filled('currency')) {
            $update['currency'] = (int) $request->currency;
            $update['currencyRate'] = $this->fetchCurrencyRate((int) $request->currency);
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
            if (! $draft || ! $draft->amount || ! $draft->date) {
                $results['skipped']++;
                continue;
            }
            $preview = $this->computePreview($draft);
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

    public function clearDrafts(): JsonResponse
    {
        DB::table('transaction_draft')->delete();
        return response()->json(['ok' => true]);
    }

    /** Список уникальных поставщиков/провайдеров для фильтров. */
    public function suppliersAndProviders(): JsonResponse
    {
        $suppliers = DB::table('program')
            ->whereNotNull('providerName')
            ->whereNull('dateDeleted')
            ->distinct()
            ->orderBy('providerName')
            ->pluck('providerName');
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

    /** Доступные ставки %ДС для продукта (модалка «Изменить»). */
    public function productRates(int $productId): JsonResponse
    {
        $rates = DB::table('dsCommission')
            ->where('product', $productId)
            ->where('active', true)
            ->whereNull('dateDeleted')
            ->orderByDesc('comission')
            ->get(['id', 'comission', 'commissionAbsolute', 'programName', 'date', 'dateFinish']);
        return response()->json(['rates' => $rates]);
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

        $results = ['fixed' => [], 'errors' => []];
        foreach ($request->ids as $draftId) {
            $draft = $this->loadDraftWithRefs((int) $draftId);
            if (! $draft) {
                $results['errors'][] = ['id' => $draftId, 'reason' => 'Не найден'];
                continue;
            }
            if (! $draft->amount || ! $draft->date) {
                $results['errors'][] = ['id' => $draftId, 'reason' => 'Заполните дату и сумму'];
                continue;
            }

            try {
                $txId = DB::transaction(function () use ($draft, $request) {
                    $rate = (float) ($draft->currencyRate ?: 1);
                    $amount = (float) $draft->amount;
                    $amountRub = $amount * $rate;

                    $usdRow = DB::table('currencyRate')->where('currency', 5)->orderByDesc('date')->first();
                    $usdRate = (float) ($usdRow->rate ?? 1);
                    $amountUsd = $usdRate > 0 ? $amountRub / $usdRate : 0;

                    $date = \Carbon\Carbon::parse($draft->date);

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
                        'dsCommissionPercentage' => $draft->dsCommissionPercentage,
                        'dsCommissionAbsolute' => $draft->dsCommissionAbsolute,
                        'customCommission' => $draft->customCommission ?: false,
                        'userChanged' => $request->user()->id,
                    ]);

                    return $txId;
                });

                $calcResult = $this->calculator->calculateForTransaction($txId);
                if (isset($calcResult['error'])) {
                    $results['errors'][] = ['id' => $draftId, 'reason' => $calcResult['error']];
                    continue;
                }

                DB::table('transaction_draft')->where('id', $draftId)->delete();
                $results['fixed'][] = $txId;
            } catch (\Throwable $e) {
                $results['errors'][] = ['id' => $draftId, 'reason' => $e->getMessage()];
            }
        }

        return response()->json($results);
    }

    /** Превью-расчёт (без записи). Дублирует ключевые шаги CommissionCalculator. */
    private function computePreview(object $draft): array
    {
        if (! $draft->amount || ! $draft->date || ! $draft->contract) {
            return ['ready' => false];
        }

        $contract = DB::table('contract')->where('id', $draft->contract)->first();
        if (! $contract || ! $contract->consultant) return ['ready' => false];

        $rate = (float) ($draft->currencyRate ?: 1);
        $amountRub = (float) $draft->amount * $rate;

        $vat = DB::table('vat')
            ->where('dateFrom', '<=', now())
            ->where('dateTo', '>=', now())
            ->first();
        $vatPercent = (float) ($vat->value ?? 0);
        $amountNoVat = $amountRub / (1 + $vatPercent / 100);

        $programRow = $contract->program
            ? DB::table('program')->where('id', $contract->program)->first()
            : null;

        // %ДС: override → программа → справочник dsCommission → 100%
        $dsPercent = (float) ($draft->dsCommissionPercentage ?? 0);
        if ($dsPercent <= 0 && $programRow && $programRow->dsPercent !== null) {
            $dsPercent = (float) $programRow->dsPercent;
        }
        if ($dsPercent <= 0 && $contract->program) {
            $dsCom = DB::table('dsCommission')
                ->where('program', $contract->program)
                ->where('active', true)
                ->whereNull('dateDeleted')
                ->first();
            $dsPercent = (float) ($dsCom->comission ?? 0);
        }
        if ($dsPercent <= 0) $dsPercent = 100;

        // Своя комиссия: пользователь сам ввёл сумму ДохДС → %ДС обратным расчётом.
        $incomeDS = $amountNoVat * $dsPercent / 100;
        if ($draft->customCommission && $draft->dsCommissionAbsolute) {
            $incomeDS = (float) $draft->dsCommissionAbsolute;
            $dsPercent = $amountNoVat > 0 ? round($incomeDS / $amountNoVat * 100, 4) : 0;
        }

        // Личный объём (баллы)
        $points = $this->computePoints($programRow, $amountNoVat, $amountRub, $dsPercent);

        // Цепочка наставников: вверх по inviter, маржинальная разница процентов.
        $consultantId = (int) $contract->consultant;

        // Spec ✅Бизнес-логика «Неизвестного консультанта»: 0% и без каскада.
        if ($consultantId === \App\Services\CommissionCalculator::UNKNOWN_CONSULTANT_ID) {
            return [
                'ready' => true,
                'amountRUB' => round($amountRub, 2),
                'amountNoVat' => round($amountNoVat, 2),
                'vat' => round($amountRub - $amountNoVat, 2),
                'vatPercent' => $vatPercent,
                'dsCommissionPercentage' => round($dsPercent, 4),
                'incomeDS' => round($incomeDS, 2),
                'personalVolume' => round($points, 4),
                'partnersTotal' => 0,
                'profitDS' => round($incomeDS, 2),
                'chain' => [[
                    'consultantId' => $consultantId,
                    'name' => 'Неизвестный консультант',
                    'percent' => 0,
                    'lp' => round($points, 2),
                    'points' => 0,
                    'sum' => 0,
                    'isDirect' => true,
                    'isUnknown' => true,
                ]],
                'unknownConsultant' => true,
            ];
        }

        $directQual = $this->resolveQual($consultantId, $draft->date);
        $directPercent = $directQual['percent'];

        $chain = [];
        $chain[] = [
            'consultantId' => $consultantId,
            'name' => DB::table('consultant')->where('id', $consultantId)->value('personName'),
            'percent' => $directPercent,
            'lp' => round($points, 2),
            'points' => round($points * $directPercent / 100, 2),
            'sum' => round($points * $directPercent, 2),
            'isDirect' => true,
        ];

        $current = $consultantId;
        $prevPercent = $directPercent;
        $visited = [$consultantId];
        for ($i = 0; $i < 20; $i++) {
            $row = DB::table('consultant')->where('id', $current)->first();
            $inviterId = $row->inviter ?? null;
            if (! $inviterId || in_array($inviterId, $visited)) break;
            $visited[] = $inviterId;

            $inviter = DB::table('consultant')->where('id', $inviterId)->first();
            if (! $inviter) break;

            $invQual = $this->resolveQual($inviterId, $draft->date);
            $margin = $invQual['percent'] - $prevPercent;

            $chain[] = [
                'consultantId' => $inviterId,
                'name' => $inviter->personName,
                'percent' => $invQual['percent'],
                'lp' => 0,
                'points' => $margin > 0 ? round($points * $margin / 100, 2) : 0,
                'sum' => $margin > 0 ? round($points * $margin, 2) : 0,
                'isDirect' => false,
            ];

            $prevPercent = max($prevPercent, $invQual['percent']);
            $current = $inviterId;
        }

        $partnersTotal = array_sum(array_column($chain, 'sum'));
        $profitDS = round($incomeDS - $partnersTotal, 2);

        $usdRow = DB::table('currencyRate')->where('currency', 5)->orderByDesc('date')->first();
        $usdRate = (float) ($usdRow->rate ?? 0);
        $incomeDsUsd = $usdRate > 0 ? round($incomeDS / $usdRate, 2) : 0;
        $amountNoVatUsd = $usdRate > 0 ? round($amountNoVat / $usdRate, 2) : 0;

        return [
            'ready' => true,
            'amountRUB' => round($amountRub, 2),
            'amountNoVat' => round($amountNoVat, 2),
            'amountNoVatUSD' => $amountNoVatUsd,
            'vat' => round($amountRub - $amountNoVat, 2),
            'vatPercent' => $vatPercent,
            'dsCommissionPercentage' => round($dsPercent, 4),
            'incomeDS' => round($incomeDS, 2),
            'incomeDSUSD' => $incomeDsUsd,
            'personalVolume' => round($points, 4),
            'partnersTotal' => round($partnersTotal, 2),
            'profitDS' => $profitDS,
            'chain' => $chain,
        ];
    }

    private function computePoints(?object $program, float $amountNoVat, float $amountRub, float $dsPercent): float
    {
        $method = $program->pointsMethod ?? null;
        $fixed = $program?->fixedCost !== null ? (float) $program->fixedCost : null;
        $min = $program?->pointsMin !== null ? (float) $program->pointsMin : null;
        return match ($method) {
            'cost_div_100' => ($fixed ?? $amountRub) / 100,
            'amount_div_100' => $amountRub / 100,
            'fixed' => (float) ($min ?? 0),
            default => $amountNoVat * $dsPercent / 10000,
        };
    }

    private function resolveQual(int $consultantId, ?string $date): array
    {
        if (! $date) return ['percent' => 15, 'levelId' => null];
        $startOfMonth = \Carbon\Carbon::parse($date)->startOfMonth()->toDateString();

        $log = DB::table('qualificationLog')
            ->where('consultant', $consultantId)
            ->whereNull('dateDeleted')
            ->where('date', '<', $startOfMonth)
            ->orderByDesc('date')
            ->first();

        $levelId = $log?->nominalLevel ?? $log?->calculationLevel ?? null;
        if (! $levelId) return ['percent' => 15, 'levelId' => null];

        $level = DB::table('status_levels')->where('id', $levelId)->first();
        return ['percent' => (float) ($level->percent ?? 15), 'levelId' => $level?->id];
    }

    /**
     * Какие параметры (commissionCalcProperty) есть у продукта.
     * Список параметров = distinct commissionCalcProperty всех активных
     * dsCommission-строк, которые относятся к программам этого продукта.
     * Если у продукта <=1 параметра — UI скроет дропдаун (выбор не нужен).
     */
    private function loadParametersForProducts(array $productIds): array
    {
        if (! count($productIds)) return [];

        $rows = DB::table('dsCommission as dc')
            ->join('commissionCalcProperty as cp', 'cp.id', '=', 'dc.commissionCalcProperty')
            ->where('dc.active', true)
            ->whereNull('dc.dateDeleted')
            ->whereIn('dc.product', $productIds)
            ->select(['dc.product as productId', 'cp.id', 'cp.title'])
            ->distinct()
            ->orderBy('cp.title')
            ->get();

        $byProduct = [];
        foreach ($rows as $r) {
            $byProduct[$r->productId][] = ['id' => $r->id, 'title' => $r->title];
        }
        return $byProduct;
    }

    private function fetchCurrencyRate(int $currencyId): float
    {
        if ($currencyId === 67) return 1.0; // RUB
        $row = DB::table('currencyRate')
            ->where('currency', $currencyId)
            ->orderByDesc('date')
            ->first();
        return (float) ($row->rate ?? 1);
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
                'c.product as productId',
                'c.productName',
                'c.program as programId',
                'c.programName',
                'pr.providerName as supplierName',
                'pr.vendorName as providerName',
                'cur.symbol as currencySymbol',
                'cur.nameRu as currencyName',
            ]);
    }

    private function serializeDraft(object $r, ?array $paramsByProduct = null): array
    {
        if ($paramsByProduct === null && $r->productId ?? null) {
            $paramsByProduct = $this->loadParametersForProducts([(int) $r->productId]);
        }
        $params = $r->productId ? ($paramsByProduct[$r->productId] ?? []) : [];

        return [
            'id' => $r->id,
            'contractId' => $r->contract,
            'contractNumber' => $r->contractNumber ?? null,
            'clientName' => $r->clientName ?? null,
            'consultantId' => $r->consultant,
            'consultantName' => $r->consultantName ?? null,
            'productId' => $r->productId ?? null,
            'productName' => $r->productName ?? null,
            'programId' => $r->programId ?? null,
            'programName' => $r->programName ?? null,
            'supplierName' => $r->supplierName ?? null,
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
            'dsCommissionPercentage' => $r->dsCommissionPercentage !== null ? (float) $r->dsCommissionPercentage : null,
            'commissionOverride' => (bool) $r->commissionOverride,
            'customCommission' => (bool) $r->customCommission,
            'dsCommissionAbsolute' => $r->dsCommissionAbsolute !== null ? (float) $r->dsCommissionAbsolute : null,
            'preview' => $r->previewCalc ? json_decode($r->previewCalc, true) : null,
        ];
    }
}
