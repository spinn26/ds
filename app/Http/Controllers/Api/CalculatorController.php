<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CalculatorController extends Controller
{
    /**
     * Product matrix — каскадные данные для калькулятора:
     * categories → types → products → programs → properties → terms
     *
     * Cached for 10 minutes: eight SELECTs over reference tables that
     * change at most a few times per day (admin edits products/programs).
     * Staleness window is acceptable for calculator UX.
     */
    public function productMatrix(): JsonResponse
    {
        $payload = Cache::remember('calculator:product-matrix', now()->addMinutes(10), function () {
            $categories = DB::table('productCategory')->orderBy('productCategoryName')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->productCategoryName]);

            $types = DB::table('productType')->where('active', true)->orderBy('productTypeName')->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->productTypeName,
                    'categoryId' => $t->productTypeCategory,
                ]);

            $products = DB::table('product')
                ->where('active', true)
                ->where(function ($q) {
                    $q->where('visibleToCalculator', true)->orWhereNull('visibleToCalculator');
                })
                ->orderBy('name')->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'typeId' => $p->productType ?? null,
                ]);

            $programRows = DB::table('program')
                ->where('active', true)
                ->where(function ($q) {
                    $q->where('visibleToCalculator', true)->orWhereNull('visibleToCalculator');
                })
                ->orderBy('name')->get();

            // Per spec ✅Калькулятор объёмов §2: «Свойство / Срок / Год выплаты
            // КВ» показываются ТОЛЬКО если применимы к программе. Берём
            // discrete-варианты из активных тарифов (dsCommission) — если
            // вариантов нет, поле в UI скрывается.
            $programIds = $programRows->pluck('id')->all();
            $hasKvYear = Schema::hasColumn('dsCommission', 'kvPayoutYear');
            $cols = ['program', 'commissionCalcProperty', 'termContract'];
            if ($hasKvYear) $cols[] = 'kvPayoutYear';
            $tariffs = $programIds ? DB::table('dsCommission')
                ->whereIn('program', $programIds)
                ->where('active', true)
                ->whereNull('dateDeleted')
                ->where(function ($q) {
                    $q->whereNull('date')->orWhere('date', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('dateFinish')->orWhere('dateFinish', '>=', now());
                })
                ->get($cols) : collect();

            $byProgram = $tariffs->groupBy('program');

            $programs = $programRows->map(function ($p) use ($byProgram, $hasKvYear) {
                $rows = $byProgram[$p->id] ?? collect();
                $availableProperties = $rows->pluck('commissionCalcProperty')->filter()->unique()->values()->all();
                $availableTerms = $rows->pluck('termContract')->filter()->unique()->values()->all();
                $maxKvYear = $hasKvYear ? (int) $rows->pluck('kvPayoutYear')->filter()->max() : 0;
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'productId' => $p->product,
                    'term' => $p->term,
                    'currency' => $p->currency,
                    'calcPropertyId' => $p->commissionCalcProperty ?? null,
                    'termContractId' => $p->termContract ?? null,
                    'availableProperties' => array_map('intval', $availableProperties),
                    'availableTerms' => array_map('intval', $availableTerms),
                    'kvPayoutYear' => $maxKvYear ?: ($p->kvPayoutYear ?? null),
                ];
            });

            $properties = DB::table('commissionCalcProperty')->orderBy('title')->get()
                ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title]);

            $terms = DB::table('termContract')->orderBy('term')->get()
                ->map(fn ($t) => ['id' => $t->id, 'term' => $t->term]);

            $levels = DB::table('status_levels')->orderBy('level')->get()
                ->map(fn ($l) => ['id' => $l->id, 'level' => $l->level, 'title' => $l->title, 'percent' => $l->percent]);

            $currencies = DB::table('currency')
                ->whereIn('id', [5, 17, 67]) // USD, EUR, RUB
                ->orWhere('priority', '>', 0)
                ->orderByDesc('priority')
                ->get()
                ->map(fn ($c) => ['id' => $c->id, 'symbol' => $c->symbol, 'name' => $c->nameRu ?? $c->currencyName]);

            return [
                'categories' => $categories,
                'types' => $types,
                'products' => $products,
                'programs' => $programs,
                'properties' => $properties,
                'terms' => $terms,
                'levels' => $levels,
                'currencies' => $currencies,
            ];
        });

        return response()->json($payload);
    }

    /**
     * Рассчитать объёмы: ЛП, групповой бонус, комиссия.
     *
     * Формула (по спеке Фин.менеджера):
     * 1. amountRub = amount * currencyRate (если не RUB)
     * 2. amountNoVat = amountRub / (1 + vat/100)
     * 3. dsIncome = amountNoVat * dsCommission% / 100  (или commissionAbsolute если задан)
     * 4. personalVolume (ЛП) = amountNoVat * dsCommission% / 10000
     * 5. groupBonus = personalVolume * qualification.percent / 100
     * 6. groupBonusRub = groupBonus * 100
     */
    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'qualification' => 'required|integer',
            'program' => 'required|integer',
            // calcProperty/termContract — опциональны: для некоторых
            // программ (IPO, образовательные) свойство и срок не применимы.
            'calcProperty' => 'nullable|integer',
            'termContract' => 'nullable|integer',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|integer',
        ]);

        $qualificationId = $request->qualification;
        $programId = $request->program;
        $calcPropertyId = $request->calcProperty;
        $amount = (float) $request->amount;
        $currencyId = (int) $request->currency;
        $termContractId = $request->termContract;

        // 1. Квалификация
        $qualification = DB::table('status_levels')->where('id', $qualificationId)->first();
        if (! $qualification) {
            return response()->json(['error' => 'Квалификация не найдена'], 422);
        }

        // 2. Программа — может содержать "ручные" поля, заданные бэк-офисом
        //    в разделе «Продукты»: dsPercent, fixedCost, pointsMethod.
        //    Если они есть — используем их; иначе фоллбек на legacy dsCommission.
        $programRow = DB::table('program')->where('id', $programId)->first();

        // 2b. Legacy — dsCommission лукап (тариф для этой программы + свойства).
        // calcProperty/termContract могут быть null — для программ без свойства
        // или без срока. Используем whereNull в этом случае.
        $dsComQuery = DB::table('dsCommission')
            ->where('program', $programId)
            ->where('active', true)
            ->where('date', '<=', now())
            ->where('dateFinish', '>=', now())
            ->whereNull('dateDeleted');

        if ($calcPropertyId) {
            $dsComQuery->where('commissionCalcProperty', $calcPropertyId);
        } else {
            $dsComQuery->whereNull('commissionCalcProperty');
        }
        if ($termContractId) {
            $dsComQuery->where('termContract', $termContractId);
        }

        $dsCom = $dsComQuery->first();
        if (! $dsCom && $termContractId) {
            // Попробуем без termContract (для программ где срок optional).
            $fallback = DB::table('dsCommission')
                ->where('program', $programId)
                ->where('active', true)
                ->where('date', '<=', now())
                ->where('dateFinish', '>=', now())
                ->whereNull('dateDeleted');
            if ($calcPropertyId) $fallback->where('commissionCalcProperty', $calcPropertyId);
            else $fallback->whereNull('commissionCalcProperty');
            $dsCom = $fallback->first();
        }

        // Program-level dsPercent overrides legacy dsCommission if BackOffice set it.
        $dsCommissionPercent = $programRow && $programRow->dsPercent !== null
            ? (float) $programRow->dsPercent
            : (float) ($dsCom->comission ?? 0);
        $commissionAbsolute = (float) ($dsCom->commissionAbsolute ?? 0);

        // 3. Курс валюты (если не RUB)
        $currencyRate = 1.0;
        if ($currencyId !== 67) { // 67 = RUB
            $rate = DB::table('currencyRate')
                ->where('currency', $currencyId)
                ->orderByDesc('date')
                ->first();
            $currencyRate = (float) ($rate->rate ?? 1);
        }

        // 4. НДС
        $vat = DB::table('vat')
            ->where('dateFrom', '<=', now())
            ->where('dateTo', '>=', now())
            ->first();
        $vatPercent = (float) ($vat->value ?? 0);

        // 5. Расчёт
        $amountRub = $amount * $currencyRate;
        $amountNoVat = $amountRub / (1 + $vatPercent / 100);

        if ($commissionAbsolute > 0) {
            $dsIncome = $commissionAbsolute * $currencyRate;
            $dsIncomePercent = $amountRub > 0 ? ($dsIncome / $amountRub * 100) : 0;
        } else {
            $dsIncomePercent = $dsCommissionPercent;
            $dsIncome = $amountNoVat * $dsCommissionPercent / 100;
        }

        // ЛП по методике программы (если задана):
        //   cost_div_100   — фикс-стоимость / 100 (образовательные)
        //   amount_div_100 — amount / 100 (прямой оборот)
        //   amount_times_ds (default) — amount_no_vat × %ДС / 10000 (legacy)
        //   fixed          — pointsMin как плоское значение
        $personalVolume = $this->computePoints(
            method: $programRow->pointsMethod ?? null,
            amountNoVat: $amountNoVat,
            amountRub: $amountRub,
            dsIncomePercent: $dsIncomePercent,
            fixedCost: $programRow && $programRow->fixedCost !== null ? (float) $programRow->fixedCost : null,
            pointsMin: $programRow && $programRow->pointsMin !== null ? (float) $programRow->pointsMin : null,
        );
        $groupBonus = $personalVolume * $qualification->percent / 100;
        $groupBonusRub = $groupBonus * 100;

        // 6. Сохранить в историю (если таблица есть)
        $historyId = null;
        try {
            $historyId = DB::table('volumeCalculator')->insertGetId([
                'user_field' => $request->user()?->id,
                'qulaification' => $qualificationId, // typo in original DB
                'program' => $programId,
                'calcProperty' => $calcPropertyId,
                'amount' => $amount,
                'currency' => $currencyId,
                'termContract' => $termContractId,
                'peronalVolume' => round($personalVolume, 2), // typo in original DB
                'groupBonus' => round($groupBonus, 4),
                'groupBonusRub' => round($groupBonusRub, 2),
                'createdAt' => now(),
            ]);
        } catch (\Exception $e) {
            // volumeCalculator is optional — table missing means "don't save
            // history", not an error. Anything else is worth seeing in logs.
            Log::debug('calculator save-to-history skipped', ['exception' => $e->getMessage()]);
        }

        return response()->json([
            'personalVolume' => round($personalVolume, 2),
            'groupBonus' => round($groupBonus, 4),
            'groupBonusRub' => round($groupBonusRub, 2),
            'commission' => round($dsIncome, 2),
            'amountRub' => round($amountRub, 2),
            'amountNoVat' => round($amountNoVat, 2),
            'dsCommissionPercent' => round($dsIncomePercent, 2),
            'vatPercent' => $vatPercent,
            'currencyRate' => $currencyRate,
            'historyId' => $historyId,
        ]);
    }

    /**
     * История расчётов пользователя.
     */
    public function history(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        try {
            $rows = DB::table('volumeCalculator')
                ->where('user_field', $userId)
                ->orderByDesc('createdAt')
                ->limit(50)
                ->get();

            if ($rows->isEmpty()) {
                return response()->json([]);
            }

            // Batch-load lookups: previously each row ran four ->first()
            // calls, so 50 rows = 200 extra queries per history open.
            $qualIds = $rows->pluck('qulaification')->filter()->unique();
            $programIds = $rows->pluck('program')->filter()->unique();
            $propertyIds = $rows->pluck('calcProperty')->filter()->unique();

            $qualifications = $qualIds->isNotEmpty()
                ? DB::table('status_levels')->whereIn('id', $qualIds)->get()->keyBy('id')
                : collect();
            $programs = $programIds->isNotEmpty()
                ? DB::table('program')->whereIn('id', $programIds)->get()->keyBy('id')
                : collect();
            $productIds = $programs->pluck('product')->filter()->unique();
            $products = $productIds->isNotEmpty()
                ? DB::table('product')->whereIn('id', $productIds)->get()->keyBy('id')
                : collect();
            $properties = $propertyIds->isNotEmpty()
                ? DB::table('commissionCalcProperty')->whereIn('id', $propertyIds)->pluck('title', 'id')
                : collect();

            $history = $rows->map(function ($h) use ($qualifications, $programs, $products, $properties) {
                $qual = $h->qulaification ? $qualifications->get($h->qulaification) : null;
                $program = $h->program ? $programs->get($h->program) : null;
                $product = $program && $program->product ? $products->get($program->product) : null;

                return [
                    'id' => $h->id,
                    'qualification' => $qual ? "{$qual->level} [{$qual->title}]" : '—',
                    'productName' => $product->name ?? '—',
                    'programName' => $program->name ?? '—',
                    'property' => ($h->calcProperty ? ($properties[$h->calcProperty] ?? null) : null) ?? '—',
                    'amount' => $h->amount,
                    'personalVolume' => $h->peronalVolume ?? 0,
                    'groupBonus' => $h->groupBonus ?? 0,
                    'groupBonusRub' => $h->groupBonusRub ?? 0,
                    'createdAt' => $h->createdAt,
                ];
            });

            return response()->json($history);
        } catch (\Exception $e) {
            Log::error('calculator history load failed', ['user_id' => $userId, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Не удалось загрузить историю'], 500);
        }
    }

    /**
     * Очистить историю расчётов.
     */
    /**
     * Перевод «метода расчёта баллов» из program.pointsMethod в конкретное число.
     * Если метод не задан — используется legacy-формула (amount_no_vat × %ДС ÷ 10000).
     */
    private function computePoints(
        ?string $method,
        float $amountNoVat,
        float $amountRub,
        float $dsIncomePercent,
        ?float $fixedCost,
        ?float $pointsMin,
    ): float {
        switch ($method) {
            case 'cost_div_100':
                // Образовательные: точки = фикс-стоимость / 100.
                // Если fixedCost не задан — фоллбек на сумму транзакции.
                return ($fixedCost ?? $amountRub) / 100;
            case 'amount_div_100':
                // Стоимость контракта / 100.
                return $amountRub / 100;
            case 'fixed':
                // Плоское значение, без математики — например, «400 долларов ≈ pointsMin».
                return (float) ($pointsMin ?? 0);
            case 'amount_times_ds':
            default:
                // Legacy: amount_no_vat × %ДС / 10000.
                return $amountNoVat * $dsIncomePercent / 10000;
        }
    }

    public function clearHistory(Request $request): JsonResponse
    {
        try {
            DB::table('volumeCalculator')->where('user_field', $request->user()->id)->delete();
        } catch (\Exception $e) {
            Log::error('calculator clearHistory failed', ['user_id' => $request->user()->id, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Не удалось очистить историю'], 500);
        }

        return response()->json(['message' => 'История очищена']);
    }
}
