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
        $q = DB::table('contract')->whereNull('deletedAt');

        if ($request->filled('consultantName')) {
            $q->where('consultantName', 'ilike', '%' . $request->consultantName . '%');
        }
        if ($request->filled('clientName')) {
            $q->where('clientName', 'ilike', '%' . $request->clientName . '%');
        }
        if ($request->filled('number')) {
            $q->where('number', 'ilike', '%' . $request->number . '%');
        }
        if ($request->filled('product')) {
            $q->where('product', $request->product);
        }
        if ($request->filled('program')) {
            $q->where('program', $request->program);
        }
        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $q->where(function ($w) use ($term) {
                $w->where('number', 'ilike', $term)
                  ->orWhere('clientName', 'ilike', $term)
                  ->orWhere('consultantName', 'ilike', $term)
                  ->orWhere('productName', 'ilike', $term)
                  ->orWhere('programName', 'ilike', $term);
            });
        }

        $total = $q->count();
        $rows = $q->orderByDesc('openDate')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        $currencyIds = $rows->pluck('currency')->filter()->unique();
        $currencies = $currencyIds->isNotEmpty()
            ? DB::table('currency')->whereIn('id', $currencyIds)->pluck('symbol', 'id')
            : collect();

        // For "Поставщик/Провайдер" we use program.providerName / vendorName.
        $programIds = $rows->pluck('program')->filter()->unique();
        $programs = $programIds->isNotEmpty()
            ? DB::table('program')->whereIn('id', $programIds)->get()->keyBy('id')
            : collect();

        $data = $rows->map(function ($c) use ($currencies, $programs) {
            $prog = $c->program ? ($programs[$c->program] ?? null) : null;
            return [
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
                'providerName' => $prog?->vendorName,
                'supplierName' => $prog?->providerName,
                'amount' => round((float) ($c->ammount ?? 0), 2),
                'currencyId' => $c->currency,
                'currencySymbol' => $c->currency ? ($currencies[$c->currency] ?? null) : null,
            ];
        });

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
                'parameter' => 'standard',
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
                'cur.name as currencyName',
            ]);

        $data = $rows->map(fn ($r) => $this->serializeDraft($r));

        return response()->json(['data' => $data]);
    }

    /** Обновить поле черновика. После записи возвращаем превью-расчёт. */
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

        // Когда переключают валюту — перезатираем currencyRate актуальным курсом.
        if ($request->filled('currency')) {
            $update['currency'] = (int) $request->currency;
            $update['currencyRate'] = $this->fetchCurrencyRate((int) $request->currency);
        }

        $update['updatedAt'] = now();
        DB::table('transaction_draft')->where('id', $id)->update($update);

        $fresh = $this->loadDraftWithRefs($id);
        $preview = $this->computePreview($fresh);

        DB::table('transaction_draft')->where('id', $id)->update([
            'previewCalc' => json_encode($preview, JSON_UNESCAPED_UNICODE),
        ]);

        $fresh->previewCalc = json_encode($preview, JSON_UNESCAPED_UNICODE);

        return response()->json($this->serializeDraft($fresh));
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

        return [
            'ready' => true,
            'amountRUB' => round($amountRub, 2),
            'amountNoVat' => round($amountNoVat, 2),
            'vat' => round($amountRub - $amountNoVat, 2),
            'vatPercent' => $vatPercent,
            'dsCommissionPercentage' => round($dsPercent, 4),
            'incomeDS' => round($incomeDS, 2),
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
                'cur.name as currencyName',
            ]);
    }

    private function serializeDraft(object $r): array
    {
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
            'parameter' => $r->parameter ?? 'standard',
            'yearKV' => $r->yearKV,
            'dsCommissionPercentage' => $r->dsCommissionPercentage !== null ? (float) $r->dsCommissionPercentage : null,
            'commissionOverride' => (bool) $r->commissionOverride,
            'customCommission' => (bool) $r->customCommission,
            'dsCommissionAbsolute' => $r->dsCommissionAbsolute !== null ? (float) $r->dsCommissionAbsolute : null,
            'preview' => $r->previewCalc ? json_decode($r->previewCalc, true) : null,
        ];
    }
}
