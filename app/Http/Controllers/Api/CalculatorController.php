<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculatorController extends Controller
{
    /**
     * Product matrix — каскадные данные для калькулятора:
     * categories → types → products → programs → properties → terms
     */
    public function productMatrix(): JsonResponse
    {
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

        $programs = DB::table('program')
            ->where('active', true)
            ->where(function ($q) {
                $q->where('visibleToCalculator', true)->orWhereNull('visibleToCalculator');
            })
            ->orderBy('name')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'productId' => $p->product,
                'term' => $p->term,
                'currency' => $p->currency,
                'calcPropertyId' => $p->commissionCalcProperty ?? null,
                'termContractId' => $p->termContract ?? null,
            ]);

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

        return response()->json([
            'categories' => $categories,
            'types' => $types,
            'products' => $products,
            'programs' => $programs,
            'properties' => $properties,
            'terms' => $terms,
            'levels' => $levels,
            'currencies' => $currencies,
        ]);
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
            'calcProperty' => 'required|integer',
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

        // 2. dsCommission — тариф для этой программы + свойства
        $dsComQuery = DB::table('dsCommission')
            ->where('program', $programId)
            ->where('commissionCalcProperty', $calcPropertyId)
            ->where('active', true)
            ->where('date', '<=', now())
            ->where('dateFinish', '>=', now())
            ->whereNull('dateDeleted');

        if ($termContractId) {
            $dsComQuery->where('termContract', $termContractId);
        }

        $dsCom = $dsComQuery->first();
        if (! $dsCom) {
            // Попробуем без termContract
            $dsCom = DB::table('dsCommission')
                ->where('program', $programId)
                ->where('commissionCalcProperty', $calcPropertyId)
                ->where('active', true)
                ->where('date', '<=', now())
                ->where('dateFinish', '>=', now())
                ->whereNull('dateDeleted')
                ->first();
        }

        $dsCommissionPercent = (float) ($dsCom->comission ?? 0);
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

        $personalVolume = $amountNoVat * $dsIncomePercent / 10000;
        $groupBonus = $personalVolume * $qualification->percent / 100;
        $groupBonusRub = $groupBonus * 100;

        // 6. Сохранить в историю (если таблица есть)
        $historyId = null;
        try {
            $historyId = DB::table('volumeCalculator')->insertGetId([
                'user' => $request->user()?->id,
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
            // volumeCalculator table may not exist
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
            $history = DB::table('volumeCalculator')
                ->where('user', $userId)
                ->orderByDesc('createdAt')
                ->limit(50)
                ->get()
                ->map(function ($h) {
                    $qual = $h->qulaification ? DB::table('status_levels')->where('id', $h->qulaification)->first() : null;
                    $program = $h->program ? DB::table('program')->where('id', $h->program)->first() : null;
                    $product = $program?->product ? DB::table('product')->where('id', $program->product)->first() : null;
                    $property = $h->calcProperty ? DB::table('commissionCalcProperty')->where('id', $h->calcProperty)->value('title') : null;

                    return [
                        'id' => $h->id,
                        'qualification' => $qual ? "{$qual->level} [{$qual->title}]" : '—',
                        'productName' => $product->name ?? '—',
                        'programName' => $program->name ?? '—',
                        'property' => $property ?? '—',
                        'amount' => $h->amount,
                        'personalVolume' => $h->peronalVolume ?? 0,
                        'groupBonus' => $h->groupBonus ?? 0,
                        'groupBonusRub' => $h->groupBonusRub ?? 0,
                        'createdAt' => $h->createdAt,
                    ];
                });

            return response()->json($history);
        } catch (\Exception $e) {
            return response()->json([]);
        }
    }

    /**
     * Очистить историю расчётов.
     */
    public function clearHistory(Request $request): JsonResponse
    {
        try {
            DB::table('volumeCalculator')->where('user', $request->user()->id)->delete();
        } catch (\Exception $e) {
            Log::error('calculator clearHistory failed', ['user_id' => $request->user()->id, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Не удалось очистить историю'], 500);
        }

        return response()->json(['message' => 'История очищена']);
    }
}
