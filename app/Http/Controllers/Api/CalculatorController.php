<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Калькулятор объёмов работает напрямую с audit-каталогом
 * (`products_catalog` + `programs_catalog.tariffs` JSONB), где у каждой
 * программы хранится массив тарифных строк со свойством, сроком, годом
 * выплаты КВ и %ДС. Из legacy остаются только справочники квалификаций
 * (status_levels), валют (currency, currencyRate) и НДС (vat) — они в
 * audit-каталог не переезжали.
 */
class CalculatorController extends Controller
{
    /**
     * GET /calculator/product-matrix
     *
     * Каскад для UI:
     *   - products: только active products_catalog;
     *   - programs: только active programs_catalog, с агрегатами по тарифам
     *     (availableProperties, availableTerms, kvPayoutYear, currency);
     *   - properties / terms — глобальные distinct'ы из всех тарифов
     *     активных программ (id равен сам строке/числу — UI на них
     *     отображает item-value).
     */
    public function productMatrix(): JsonResponse
    {
        $payload = Cache::remember('calculator:product-matrix:v2', now()->addMinutes(10), function () {
            // Видим программу в калькуляторе только если ОБА уровня
            // (продукт-зонтик + программа) имеют visible_to_calculator=true.
            // Колонки добавлены миграциями 2026_05_28_000020 (programs) и
            // 2026_05_28_000030 (products) — defaults true, поэтому на старых
            // средах фильтр пропускает всё как раньше.
            $products = DB::table('products_catalog')
                ->where('active', true)
                ->where('visible_to_calculator', true)
                ->orderBy('name')
                ->get(['id', 'name', 'type']);

            $programs = DB::table('programs_catalog')
                ->where('active', true)
                ->where('visible_to_calculator', true)
                ->whereIn('product_id', $products->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'product_id', 'name', 'currency', 'tariffs']);

            $globalProperties = [];   // ['upfront' => true, …]
            $globalTerms      = [];   // [5 => true, …]
            $productFlags     = [];   // productId → ['hasProperty'=>..., 'hasTerm'=>..., 'hasYearKv'=>...]

            $programItems = $programs->map(function ($pr) use (&$globalProperties, &$globalTerms, &$productFlags) {
                $tariffs = self::decodeTariffs($pr->tariffs);
                $availProps = $availTerms = [];
                $maxYear = 0;
                foreach ($tariffs as $t) {
                    $p = self::normProperty($t['property'] ?? null);
                    $tm = self::normTerm($t['term'] ?? null);
                    $yr = self::normYear($t['year'] ?? null);
                    if ($p !== null)  { $availProps[$p] = true;  $globalProperties[$p] = true; }
                    if ($tm !== null) { $availTerms[$tm] = true; $globalTerms[$tm] = true; }
                    if ($yr !== null && $yr > $maxYear) $maxYear = $yr;
                }

                // Конфиг-флаги релевантности — для UI: показывать ли поля
                // «Свойство»/«Срок»/«Год КВ». Берём по продукту OR между программами.
                $pid = (int) $pr->product_id;
                $productFlags[$pid] ??= ['hasProperty' => false, 'hasTerm' => false, 'hasYearKv' => false];
                if ($availProps) $productFlags[$pid]['hasProperty'] = true;
                if ($availTerms) $productFlags[$pid]['hasTerm'] = true;
                if ($maxYear)    $productFlags[$pid]['hasYearKv'] = true;

                return [
                    'id'                  => (int) $pr->id,
                    'name'                => $pr->name,
                    'productId'           => (int) $pr->product_id,
                    'term'                => null,                     // legacy-поле, для UI не нужно
                    'currency'            => $pr->currency,            // строка (USD/EUR/RUB/KZT)
                    'availableProperties' => array_keys($availProps),  // массив строк
                    'availableTerms'      => array_keys($availTerms),  // массив чисел
                    'kvPayoutYear'        => $maxYear ?: null,
                ];
            });

            $productItems = $products->map(fn ($p) => [
                'id'           => (int) $p->id,
                'name'         => $p->name,
                'typeId'       => null,            // legacy-поле, не используем
                'typeName'     => $p->type,
                'hasProperty'  => $productFlags[(int) $p->id]['hasProperty'] ?? false,
                'hasTerm'      => $productFlags[(int) $p->id]['hasTerm'] ?? false,
                'hasYearKv'    => $productFlags[(int) $p->id]['hasYearKv'] ?? false,
            ]);

            // Глобальный справочник свойств/сроков — для UI v-select.
            // item-value = id; item-title = title. Здесь id и title — одна и та же
            // строка/число, потому что новый каталог хранит их «как есть».
            $properties = collect(array_keys($globalProperties))->sort()->values()
                ->map(fn ($p) => ['id' => $p, 'title' => $p]);
            $terms      = collect(array_keys($globalTerms))->sort()->values()
                ->map(fn ($t) => ['id' => $t, 'term' => $t]);

            $levels = DB::table('status_levels')->orderBy('level')->get()
                ->map(fn ($l) => ['id' => $l->id, 'level' => $l->level, 'title' => $l->title, 'percent' => $l->percent]);

            $currencies = DB::table('currency')
                ->whereIn('id', [5, 17, 67]) // USD, EUR, RUB
                ->orWhere('priority', '>', 0)
                ->orderByDesc('priority')
                ->get()
                ->map(fn ($c) => ['id' => $c->id, 'symbol' => $c->symbol, 'name' => $c->nameRu ?? $c->currencyName]);

            // categories/types оставлены для обратной совместимости фронта.
            return [
                'categories' => [],
                'types'      => [],
                'products'   => $productItems->all(),
                'programs'   => $programItems->all(),
                'properties' => $properties->all(),
                'terms'      => $terms->all(),
                'levels'     => $levels,
                'currencies' => $currencies,
            ];
        });

        return response()->json($payload);
    }

    /**
     * POST /calculator/calculate
     *
     * Принимает `program` как id из `programs_catalog`. Ищет в её
     * `tariffs` JSONB строку, удовлетворяющую (property, term, year),
     * и считает по %ДС из этого тарифа.
     */
    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'qualification' => 'required|integer',
            'program'       => 'required|integer',
            // property / term / kvPayoutYear — строки/числа, не FK.
            'calcProperty'  => 'nullable|string',
            'termContract'  => 'nullable|numeric',
            'kvPayoutYear'  => 'nullable|integer',
            'amount'        => 'required|numeric|min:0.01',
            'currency'      => 'required|integer',
        ]);

        $programId = (int) $request->input('program');
        $program   = DB::table('programs_catalog')->where('id', $programId)->where('active', true)->first();
        if (! $program) {
            return response()->json(['error' => 'Программа не найдена или неактивна'], 422);
        }

        $product = DB::table('products_catalog')->where('id', $program->product_id)->first();

        // Квалификация (legacy справочник — оставлен, в audit-каталог не переезжал)
        $qual = DB::table('status_levels')->where('id', $request->input('qualification'))->first();
        if (! $qual) {
            return response()->json(['error' => 'Квалификация не найдена'], 422);
        }

        $property = $request->filled('calcProperty') ? self::normProperty($request->input('calcProperty')) : null;
        $term     = $request->filled('termContract') ? self::normTerm($request->input('termContract'))     : null;
        $year     = $request->filled('kvPayoutYear') ? self::normYear($request->input('kvPayoutYear'))     : null;
        $amount   = (float) $request->input('amount');
        $currencyId = (int) $request->input('currency');

        // Поиск подходящего тарифа: точное совпадение по всем заданным
        // полям. Если параметр пришёл null — игнорируем его при матчинге.
        $tariffs = self::decodeTariffs($program->tariffs);
        $tariff = null;
        foreach ($tariffs as $t) {
            $tp = self::normProperty($t['property'] ?? null);
            $tt = self::normTerm($t['term'] ?? null);
            $ty = self::normYear($t['year'] ?? null);
            if ($property !== null && $tp !== $property) continue;
            if ($term     !== null && $tt !== $term)     continue;
            if ($year     !== null && $ty !== $year)     continue;
            $tariff = $t;
            break;
        }
        if (! $tariff) {
            // Фоллбэк — первый тариф в массиве (если programs_catalog не
            // развёрнута по property/term/year, тарифы плоские).
            $tariff = $tariffs[0] ?? null;
        }
        if (! $tariff) {
            return response()->json(['error' => 'У программы нет ни одного тарифа'], 422);
        }

        // ds_percent в JSONB лежит как доля (0..1, например 0.0625 = 6.25%).
        // Переводим в проценты, чтобы дальше пользоваться той же формулой,
        // что и legacy-калькулятор использовал для dsCommission.comission.
        $dsPercent = (float) str_replace(',', '.', (string) ($tariff['ds_percent'] ?? '0'));
        $dsCommissionPercent = $dsPercent * 100.0;

        // Курс валюты — берём свежий из currencyRate (67 = RUB → 1.0).
        $currencyRate = 1.0;
        if ($currencyId !== 67) {
            $rate = DB::table('currencyRate')->where('currency', $currencyId)->orderByDesc('date')->first();
            $currencyRate = (float) ($rate->rate ?? 1.0);
        }

        // НДС из справочника на текущую дату.
        $vat = DB::table('vat')
            ->where('dateFrom', '<=', now())
            ->where('dateTo',   '>=', now())
            ->first();
        $vatPercent = (float) ($vat->value ?? 0);

        $amountRub   = $amount * $currencyRate;
        $amountNoVat = $amountRub / (1 + $vatPercent / 100);

        // КВ (доход DS) = amountNoVat × %ДС / 100.
        // ЛП  = amountNoVat × %ДС / 10000 (так это считал legacy-калькулятор;
        // формула «amount_times_ds»). Для образовательных/фикс-стоимостных
        // программ JSONB-тариф пока не различает методики — расчёт по
        // common formula. Если потребуется другая методика — будем её
        // считывать из tariff.points/tariff.fixed_cost явным флагом.
        $dsIncome       = $amountNoVat * $dsCommissionPercent / 100;
        $personalVolume = $amountNoVat * $dsCommissionPercent / 10000;
        $groupBonus     = $personalVolume * $qual->percent / 100;
        $groupBonusRub  = $groupBonus * 100;

        // Сохранение в историю. FK на legacy program не нужен — пишем
        // NULL, а контекст продукта/программы/тарифа кладём в meta_json
        // (jsonb-колонка добавлена миграцией).
        $historyId = null;
        try {
            $meta = [
                'source'       => 'products_catalog',
                'product_id'   => $product->id ?? null,
                'product_name' => $product->name ?? null,
                'program_id'   => (int) $program->id,
                'program_name' => $program->name,
                'property'     => $property,
                'term'         => $term,
                'year'         => $year,
                'ds_percent'   => $tariff['ds_percent'] ?? null,
                'formula'      => $tariff['formula'] ?? null,
            ];
            $row = [
                'user_field'    => $request->user()?->id,
                'qulaification' => $qual->id,  // оригинальный typo в схеме
                'program'       => null,        // FK на legacy program не используется
                'calcProperty'  => null,
                'termContract'  => null,
                'amount'        => $amount,
                'currency'      => $currencyId,
                'peronalVolume' => round($personalVolume, 2),
                'groupBonus'    => round($groupBonus, 4),
                'groupBonusRub' => round($groupBonusRub, 2),
                'createdAt'     => now(),
            ];
            if (Schema::hasColumn('volumeCalculator', 'meta_json')) {
                $row['meta_json'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
            }
            $historyId = DB::table('volumeCalculator')->insertGetId($row);
        } catch (\Exception $e) {
            Log::debug('calculator save-to-history skipped', ['exception' => $e->getMessage()]);
        }

        return response()->json([
            'personalVolume'      => round($personalVolume, 2),
            'groupBonus'          => round($groupBonus, 4),
            'groupBonusRub'       => round($groupBonusRub, 2),
            'commission'          => round($dsIncome, 2),
            'amountRub'           => round($amountRub, 2),
            'amountNoVat'         => round($amountNoVat, 2),
            'dsCommissionPercent' => round($dsCommissionPercent, 2),
            'vatPercent'          => $vatPercent,
            'currencyRate'        => $currencyRate,
            'historyId'           => $historyId,
            'tariffFormula'       => $tariff['formula'] ?? null,
            'tariffComment'       => $tariff['comment'] ?? null,
        ]);
    }

    /**
     * GET /calculator/history — список последних расчётов пользователя.
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
            if ($rows->isEmpty()) return response()->json([]);

            $qualIds = $rows->pluck('qulaification')->filter()->unique();
            $qualifications = $qualIds->isNotEmpty()
                ? DB::table('status_levels')->whereIn('id', $qualIds)->get()->keyBy('id')
                : collect();

            $hasMeta = Schema::hasColumn('volumeCalculator', 'meta_json');

            $history = $rows->map(function ($h) use ($qualifications, $hasMeta) {
                $qual = $h->qulaification ? $qualifications->get($h->qulaification) : null;
                $meta = $hasMeta && ! empty($h->meta_json)
                    ? (is_array($h->meta_json) ? $h->meta_json : json_decode((string) $h->meta_json, true))
                    : [];

                return [
                    'id'              => $h->id,
                    'qualification'   => $qual ? "{$qual->level} [{$qual->title}]" : '—',
                    'productName'     => $meta['product_name'] ?? '—',
                    'programName'     => $meta['program_name'] ?? '—',
                    'property'        => $meta['property']     ?? '—',
                    'term'            => $meta['term']         ?? null,
                    'kvPayoutYear'    => $meta['year']         ?? null,
                    'amount'          => $h->amount,
                    'personalVolume'  => $h->peronalVolume ?? 0,
                    'groupBonus'      => $h->groupBonus ?? 0,
                    'groupBonusRub'   => $h->groupBonusRub ?? 0,
                    'createdAt'       => $h->createdAt,
                ];
            });

            return response()->json($history);
        } catch (\Exception $e) {
            Log::error('calculator history load failed', ['user_id' => $userId, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Не удалось загрузить историю'], 500);
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

    /* ------------------------------------------------------------------
     * Helpers — нормализация значений из JSONB-тарифа.
     * ------------------------------------------------------------------ */

    /** programs_catalog.tariffs хранится как jsonb; в PHP может прийти string|array|null. */
    private static function decodeTariffs($raw): array
    {
        if (is_array($raw))  return $raw;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function normProperty($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function normTerm($v): ?int
    {
        if ($v === null || $v === '') return null;
        // term может быть числом или строкой ("10", "15-20"). Берём первое число.
        if (is_numeric($v)) return (int) $v;
        if (preg_match('/(\d+)/', (string) $v, $m)) return (int) $m[1];
        return null;
    }

    private static function normYear($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) {
            $n = (int) $v;
            return $n > 0 ? $n : null;
        }
        if (preg_match('/(\d+)/', (string) $v, $m)) return (int) $m[1];
        return null;
    }
}
