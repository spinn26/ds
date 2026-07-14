<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $payload = Cache::remember('calculator:product-matrix:v3', now()->addMinutes(10), function () {
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
                    // Новый audit-формат хранит год выплаты КВ в `year_kv`,
                    // старый — в `year`. Читаем оба (см. tariffDsPercent ниже).
                    $yr = self::normYear($t['year_kv'] ?? $t['year'] ?? null);
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
            // Дедуп по регистру: «mf» и «MF» — одно свойство. Оставляем
            // первое встреченное написание как отображаемое.
            $properties = collect(array_keys($globalProperties))
                ->unique(fn ($p) => mb_strtolower($p))
                ->sort()->values()
                ->map(fn ($p) => ['id' => $p, 'title' => $p]);
            // Сортируем по первому числу (диапазон "15-20" встаёт между 14 и 5/6
            // по своему первому числу, а не лексикографически). id/term — строка.
            $terms      = collect(array_keys($globalTerms))
                ->sortBy(fn ($t) => (int) (preg_match('/(\d+)/', (string) $t, $m) ? $m[1] : 0))
                ->values()
                ->map(fn ($t) => ['id' => (string) $t, 'term' => (string) $t]);

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
            'termContract'  => 'nullable|string',  // может быть диапазоном ("15-20")
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
            $ty = self::normYear($t['year_kv'] ?? $t['year'] ?? null);
            // Свойство сравниваем без учёта регистра («MF» == «mf»).
            if ($property !== null && mb_strtolower((string) $tp) !== mb_strtolower($property)) continue;
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

        // %ДС хранится в двух форматах: новый audit-каталог — `ds_pct`
        // как строка-процент ("72,50%"), старый — `ds_percent` как доля
        // (0..1, напр. 0.0625 = 6.25%). tariffDsPercent() приводит оба
        // к процентам, чтобы дальше работала единая формула.
        $dsCommissionPercent = self::tariffDsPercent($tariff);

        // Курс — средневзвешенный за ТЕКУЩИЙ месяц (спека «Валюты и НДС» §2.1:
        // калькулятор использует курс, относящийся к периоду расчёта). Раньше
        // брался «последний в справочнике» — то же значение, пока месяц текущий,
        // но формула теперь одна на всю платформу (см. CurrencyRates).
        $currencyRate = \App\Support\CurrencyRates::forDate($currencyId);

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

        // Формула пока читает проверенные legacy-справочники, а результат
        // сохраняется в каноническую историю v2 без изменения расчёта.
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
                'ds_percent'   => $tariff['ds_pct'] ?? $tariff['ds_percent'] ?? null,
                'formula'      => $tariff['formula'] ?? null,
            ];
            $v2 = DB::connection('pgsql_v2');
            $mappedIds = $v2->table('legacy_mappings')
                ->where('source_system', 'legacy_pg')
                ->where(function ($query) use ($product, $program) {
                    $query->where(function ($item) use ($product) {
                        $item->where('source_table', 'products_catalog')
                            ->where('source_key', (string) ($product->id ?? ''))
                            ->where('target_table', 'products');
                    })->orWhere(function ($item) use ($program) {
                        $item->where('source_table', 'programs_catalog')
                            ->where('source_key', (string) $program->id)
                            ->where('target_table', 'programs');
                    });
                })
                ->pluck('target_id', 'source_table');

            $historyId = $v2->table('calculator_runs')->insertGetId([
                'user_id' => $request->user()?->id,
                'qualification_level_id' => $qual->id,
                'product_id' => $mappedIds->get('products_catalog'),
                'program_id' => $mappedIds->get('programs_catalog'),
                'currency_id' => $currencyId,
                'amount' => $amount,
                'personal_volume_points' => round($personalVolume, 2),
                'group_bonus_points' => round($groupBonus, 4),
                'group_bonus_rub' => round($groupBonusRub, 2),
                'inputs' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'result' => json_encode([
                    'commission' => round($dsIncome, 2),
                    'amount_rub' => round($amountRub, 2),
                    'amount_without_vat' => round($amountNoVat, 2),
                    'ds_commission_percent' => round($dsCommissionPercent, 2),
                    'vat_percent' => $vatPercent,
                    'currency_rate' => $currencyRate,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
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
            $rows = DB::connection('pgsql_v2')->table('calculator_runs')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
            if ($rows->isEmpty()) return response()->json([]);

            $qualIds = $rows->pluck('qualification_level_id')->filter()->unique();
            $qualifications = $qualIds->isNotEmpty()
                ? DB::connection('pgsql_v2')->table('qualification_levels')->whereIn('id', $qualIds)->get()->keyBy('id')
                : collect();

            $history = $rows->map(function ($h) use ($qualifications) {
                $qual = $h->qualification_level_id ? $qualifications->get($h->qualification_level_id) : null;
                $meta = is_array($h->inputs) ? $h->inputs : (json_decode((string) $h->inputs, true) ?: []);

                return [
                    'id'              => $h->id,
                    'qualification'   => $qual ? "{$qual->level} [{$qual->title}]" : '—',
                    'productName'     => $meta['product_name'] ?? '—',
                    'programName'     => $meta['program_name'] ?? '—',
                    'property'        => $meta['property']     ?? '—',
                    'term'            => $meta['term']         ?? null,
                    'kvPayoutYear'    => $meta['year']         ?? null,
                    'amount'          => $h->amount,
                    'personalVolume'  => $h->personal_volume_points ?? 0,
                    'groupBonus'      => $h->group_bonus_points ?? 0,
                    'groupBonusRub'   => $h->group_bonus_rub ?? 0,
                    'createdAt'       => $h->created_at,
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
            DB::connection('pgsql_v2')->table('calculator_runs')->where('user_id', $request->user()->id)->delete();
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

    /**
     * %ДС из тарифа в процентах (не в долях).
     *  - `ds_pct` — строка-процент ("72,50%", "6.00%", "72,5") → как есть в %.
     *  - `ds_percent` — доля (0.725) → умножаем на 100.
     */
    private static function tariffDsPercent(array $tariff): float
    {
        $pct = $tariff['ds_pct'] ?? null;
        if ($pct !== null && $pct !== '') {
            $s = str_replace(['%', ' '], '', (string) $pct);
            $s = str_replace(',', '.', $s);
            return is_numeric($s) ? (float) $s : 0.0;
        }
        $frac = (float) str_replace(',', '.', (string) ($tariff['ds_percent'] ?? '0'));
        return $frac * 100.0;
    }

    private static function normProperty($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /**
     * Канонический срок контракта как СТРОКА — сохраняем диапазоны ("15-20").
     * Одиночное число нормализуем к int-строке ("10.0"/10 → "10"), диапазон —
     * убираем пробелы вокруг дефиса ("15 - 20" → "15-20"). И построение
     * выпадашки, и матчинг тарифа используют эту функцию → значения совпадают.
     */
    private static function normTerm($v): ?string
    {
        if ($v === null || $v === '') return null;
        $s = trim((string) $v);
        if ($s === '') return null;
        if (is_numeric($s)) return (string) (int) $s;       // "10" / 10 / "10.0" → "10"
        $s = preg_replace('/\s*-\s*/', '-', $s);            // "15 - 20" → "15-20"
        return $s !== '' ? $s : null;
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
