<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin API over the audit-driven catalog
 * (`products_catalog` + `programs_catalog`, populated from the Excel audit).
 *
 * Designed to be a drop-in replacement for AdminProductController on the
 * Admin/Products.vue page — the response shape mirrors what the existing
 * page expects (camelCase keys: typeName, productType, hasProperty,
 * publishStatus, visibleToCalculator, providerName, vendorName, dsPercent,
 * pointsMethod, …) so the page template needs zero changes.
 *
 * Fields that exist only on the legacy `product`/`program` tables (images,
 * description, productType FK, educationCourseId, course links, etc.) are
 * surfaced as null so the form renders without errors but the audit-driven
 * catalog stays minimal.
 */
class AdminProductCatalogController extends Controller
{
    use PaginatesRequests;

    /** Distinct ТИП values for filter chips. */
    public function types(): JsonResponse
    {
        return response()->json(
            DB::table('products_catalog')
                ->whereNotNull('type')
                ->groupBy('type')
                ->selectRaw('type, COUNT(*) AS products')
                ->orderBy('type')
                ->get()
        );
    }

    /** GET /admin/products-catalog — paginated list shaped like AdminProductController::index. */
    public function indexProducts(Request $request): JsonResponse
    {
        $q = DB::table('products_catalog as p')
            ->leftJoin('programs_catalog as g', 'g.product_id', '=', 'p.id')
            ->groupBy('p.id', 'p.name', 'p.type', 'p.open_product_url', 'p.active', 'p.created_at',
                'p.image_url', 'p.hero_image', 'p.description', 'p.legacy_product_id',
                'p.visible_to_resident', 'p.visible_to_calculator')
            ->select([
                'p.id',
                'p.name',
                'p.type',
                'p.open_product_url',
                'p.active',
                'p.created_at',
                'p.image_url',
                'p.hero_image',
                'p.description',
                'p.legacy_product_id',
                'p.visible_to_resident',
                'p.visible_to_calculator',
                DB::raw('COUNT(g.id) AS programs_count'),
                DB::raw('COUNT(g.id) FILTER (WHERE g.active=true)  AS programs_active'),
                DB::raw('COUNT(g.id) FILTER (WHERE g.has_red=true) AS programs_red'),
                DB::raw("string_agg(DISTINCT g.terms_summary, ',') AS all_terms"),
                DB::raw("string_agg(DISTINCT g.years_summary, ',') AS all_years"),
            ]);

        if ($s = trim((string) $request->input('search', ''))) {
            $q->where('p.name', 'ilike', "%{$s}%");
        }
        if ($request->filled('active')) {
            $wantsActive = filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);
            $q->where('p.active', $wantsActive);
        }

        $total = DB::table(DB::raw('(' . $q->toSql() . ') as t'))
            ->mergeBindings($q)
            ->count();

        $rows = $q
            ->orderByRaw('COUNT(g.id) DESC')
            ->orderBy('p.name')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        return response()->json([
            'data'  => $rows->map(fn ($r) => self::productListRow($r))->all(),
            'total' => $total,
        ]);
    }

    /** GET /admin/products-catalog/{id}/programs */
    public function programs(int $id): JsonResponse
    {
        $rows = DB::table('programs_catalog')
            ->where('product_id', $id)
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => self::programRow($r))->all(),
        ]);
    }

    /** GET /admin/products-catalog/{id} */
    public function showProduct(int $id): JsonResponse
    {
        $r = DB::table('products_catalog as p')
            ->leftJoin('programs_catalog as g', 'g.product_id', '=', 'p.id')
            ->groupBy('p.id', 'p.name', 'p.type', 'p.open_product_url', 'p.active', 'p.created_at',
                'p.image_url', 'p.hero_image', 'p.description', 'p.legacy_product_id',
                'p.visible_to_resident', 'p.visible_to_calculator')
            ->where('p.id', $id)
            ->select([
                'p.id', 'p.name', 'p.type', 'p.open_product_url', 'p.active', 'p.created_at',
                'p.image_url', 'p.hero_image', 'p.description', 'p.legacy_product_id',
                'p.visible_to_resident', 'p.visible_to_calculator',
                DB::raw('COUNT(g.id) AS programs_count'),
                DB::raw('COUNT(g.id) FILTER (WHERE g.active=true)  AS programs_active'),
                DB::raw('COUNT(g.id) FILTER (WHERE g.has_red=true) AS programs_red'),
            ])
            ->first();

        abort_unless((bool) $r, 404);
        return response()->json(self::productListRow($r));
    }

    /** GET /admin/products-catalog/references — productType + courses (same shape as legacy). */
    public function references(): JsonResponse
    {
        $types = DB::table('products_catalog')
            ->whereNotNull('type')
            ->groupBy('type')
            ->orderBy('type')
            ->get(['type as name', DB::raw('NULL::int as id'), DB::raw('NULL::int as categoryId')]);

        $courses = DB::table('education_courses')
            ->where('active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'product_id']);

        $categories = DB::table('productCategory')
            ->orderBy('productCategoryName')
            ->get(['id', 'productCategoryName as name']);

        return response()->json([
            'types'      => $types,
            'courses'    => $courses,
            'categories' => $categories,
        ]);
    }

    /** POST /admin/products-catalog */
    public function storeProduct(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name'           => 'required|string|max:255',
            'type'           => 'nullable|string|max:255',
            'active'         => 'nullable|boolean',
            'openProductUrl' => 'nullable|string|max:1000',
            'description'    => 'nullable|string|max:4000',
            'imageUrl'       => 'nullable|string|max:1000',
            'heroImage'      => 'nullable|string|max:1000',
        ]);

        $id = DB::table('products_catalog')->insertGetId([
            'name'             => $payload['name'],
            'type'             => $payload['type'] ?? null,
            'open_product_url' => $payload['openProductUrl'] ?? null,
            'description'      => $payload['description'] ?? null,
            'image_url'        => $payload['imageUrl'] ?? null,
            'hero_image'       => $payload['heroImage'] ?? null,
            'active'           => $payload['active'] ?? true,
            'imported_from'    => 'admin-ui',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $this->showProduct($id);
    }

    /** PUT /admin/products-catalog/{id} */
    public function updateProduct(int $id, Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'type'                => 'nullable|string|max:255',
            'active'              => 'nullable|boolean',
            'openProductUrl'      => 'nullable|string|max:1000',
            'description'         => 'nullable|string|max:4000',
            'imageUrl'            => 'nullable|string|max:1000',
            'heroImage'           => 'nullable|string|max:1000',
            // Видимость продукта-зонтика (migration 2026_05_28_000030).
            // visible_to_calculator=false убирает продукт и ВСЕ его программы
            // из калькулятора без необходимости снимать active.
            'visibleToResident'   => 'nullable|boolean',
            'visibleToCalculator' => 'nullable|boolean',
            // Остальные поля формы (productType, educationCourseId, ...) пока
            // не маппятся на catalog-схему и тихо игнорируются.
        ]);

        // null — валидное значение (очистить поле), поэтому через has()
        // отличаем «не прислали» от «прислали null».
        $update = ['updated_at' => now()];
        if ($request->has('name'))                $update['name']                  = $payload['name'];
        if ($request->has('type'))                $update['type']                  = $payload['type'];
        if ($request->has('active'))              $update['active']                = $payload['active'];
        if ($request->has('openProductUrl'))      $update['open_product_url']      = $payload['openProductUrl'];
        if ($request->has('description'))         $update['description']           = $payload['description'];
        if ($request->has('imageUrl'))            $update['image_url']             = $payload['imageUrl'];
        if ($request->has('heroImage'))           $update['hero_image']            = $payload['heroImage'];
        if ($request->has('visibleToResident'))   $update['visible_to_resident']   = (bool) ($payload['visibleToResident'] ?? true);
        if ($request->has('visibleToCalculator')) $update['visible_to_calculator'] = (bool) ($payload['visibleToCalculator'] ?? true);

        DB::table('products_catalog')->where('id', $id)->update($update);

        // Калькулятор кэширует матрицу продуктов на 10 минут — без инвалидации
        // снятая галка появится в дропдауне только через эти 10 минут.
        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix:v2');

        return $this->showProduct($id);
    }

    /** DELETE /admin/products-catalog/{id} — soft-delete (active=false) to match page wording. */
    public function destroyProduct(int $id): JsonResponse
    {
        DB::table('products_catalog')->where('id', $id)->update([
            'active'     => false,
            'updated_at' => now(),
        ]);
        return response()->json(['status' => 'deactivated']);
    }

    /** POST /admin/products-catalog/{id}/toggle-publish — toggles `active`. */
    public function togglePublish(int $id): JsonResponse
    {
        $row = DB::table('products_catalog')->where('id', $id)->first();
        abort_unless($row, 404);
        $next = ! $row->active;
        DB::table('products_catalog')->where('id', $id)->update([
            'active'     => $next,
            'updated_at' => now(),
        ]);
        return response()->json(['publishStatus' => $next ? 'published' : 'draft']);
    }

    /**
     * POST /admin/products-catalog/{id}/image
     *
     * Сохраняет логотип (kind=image) или баннер (kind=hero) в
     * storage/app/public/products/, записывает URL в соответствующую
     * колонку products_catalog. Шаблон скопирован из AdminProductController.
     * Требует `php artisan storage:link` для /storage/* отдачи.
     */
    public function uploadImage(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|image|max:4096',
            'kind' => 'required|in:image,hero',
        ]);

        $row = DB::table('products_catalog')->where('id', $id)->first();
        abort_unless((bool) $row, 404);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = sprintf('%d-%s-%s.%s', $id, $request->kind, substr(md5(uniqid('', true)), 0, 8), $ext);
        $path = $file->storeAs('products', $name, 'public');
        $url = '/storage/' . $path;

        $column = $request->kind === 'image' ? 'image_url' : 'hero_image';
        DB::table('products_catalog')->where('id', $id)->update([
            $column => $url,
            'updated_at' => now(),
        ]);

        return response()->json(['url' => $url, 'kind' => $request->kind]);
    }

    /** POST /admin/products-catalog/{id}/programs */
    public function storeProgram(int $productId, Request $request): JsonResponse
    {
        $payload = self::extractProgramPayload($request);
        $newId = DB::table('programs_catalog')->insertGetId(array_merge($payload, [
            'product_id'     => $productId,
            'imported_from'  => 'admin-ui',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]));
        return $this->showSingleProgram($newId);
    }

    /** PUT /admin/products-catalog/{productId}/programs/{programId} */
    public function updateProgram(int $productId, int $programId, Request $request): JsonResponse
    {
        $payload = self::extractProgramPayload($request);
        $payload['updated_at'] = now();
        DB::table('programs_catalog')
            ->where('id', $programId)
            ->where('product_id', $productId)
            ->update($payload);
        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix:v2');
        return $this->showSingleProgram($programId);
    }

    /** DELETE /admin/products-catalog/{productId}/programs/{programId} — soft via active=false. */
    public function destroyProgram(int $productId, int $programId): JsonResponse
    {
        DB::table('programs_catalog')
            ->where('id', $programId)
            ->where('product_id', $productId)
            ->update(['active' => false, 'updated_at' => now()]);
        return response()->json(['status' => 'deactivated']);
    }

    /** GET single program (used internally and by showProgram route). */
    public function showProgram(int $id): JsonResponse
    {
        return $this->showSingleProgram($id);
    }

    private function showSingleProgram(int $id): JsonResponse
    {
        $r = DB::table('programs_catalog')->where('id', $id)->first();
        abort_unless((bool) $r, 404);
        return response()->json(self::programRow($r));
    }

    /* ------------------------------------------------------------------
     * Shape adapters
     * ------------------------------------------------------------------ */

    /** Shape one products_catalog row + aggregates the way Products.vue list expects. */
    private static function productListRow(object $r): array
    {
        $hasTerm    = ! empty($r->all_terms ?? null);
        $hasYearKv  = ! empty($r->all_years ?? null);
        $programs   = (int) ($r->programs_count ?? 0);
        $active     = (bool) $r->active;

        return [
            'id'                   => (int) $r->id,
            'name'                 => $r->name,
            // `type` — реальная категория из products_catalog (строка).
            // Эту строку шлёт фронт обратно в updateProduct. `typeName` оставлен
            // как алиас для legacy-чтений (например, в карточке витрины).
            'type'                 => $r->type,
            'typeName'             => $r->type,
            'productType'          => null,
            // Поля из расширения каталога (migration 2026_05_28_000010):
            // description / image_url / hero_image / legacy_product_id.
            'description'          => $r->description ?? null,
            'imageUrl'             => $r->image_url ?? null,
            'heroImage'            => $r->hero_image ?? null,
            'legacyProductId'      => $r->legacy_product_id ?? null,
            'educationCourseId'    => null,
            'educationUrl'         => null,
            'instructionUrl'       => null,
            'openProductUrl'       => $r->open_product_url ?? null,
            'noComission'          => false,
            'active'               => $active,
            // Per operator rule: a product (umbrella) is itself not coloured —
            // it stays available as long as `active=true`, even when all of
            // its programs got tagged red.  The red filter lives only on the
            // program level.
            //
            // visible_to_resident / visible_to_calculator — отдельные тоглы
            // (migration 2026_05_28_000030). На старых средах их может не
            // быть в SELECT → fallback на $active.
            'visibleToResident'    => property_exists($r, 'visible_to_resident')
                ? (bool) $r->visible_to_resident
                : $active,
            'visibleToCalculator'  => property_exists($r, 'visible_to_calculator')
                ? (bool) $r->visible_to_calculator
                : $active,
            'hasProperty'          => false,
            'hasTerm'              => $hasTerm,
            'hasYearKv'            => $hasYearKv,
            'publishStatus'        => $active ? 'published' : 'draft',
            'programCount'         => $programs,
            'programsRed'          => (int) ($r->programs_red ?? 0),
            'programsActive'       => (int) ($r->programs_active ?? 0),
        ];
    }

    /** Shape one programs_catalog row the way Products.vue expects. */
    private static function programRow(object $r): array
    {
        $tariffs = is_string($r->tariffs ?? null) ? json_decode($r->tariffs, true) : ($r->tariffs ?? null);
        $tariffs = is_array($tariffs) ? $tariffs : [];

        // Extract a single representative tariff line for the flat view.
        $first = $tariffs[0] ?? [];

        // Term — try to parse the first numeric value out of terms_summary.
        $term = null;
        if (! empty($r->terms_summary)) {
            foreach (explode(',', $r->terms_summary) as $t) {
                $t = trim($t);
                if (is_numeric($t)) { $term = (int) $t; break; }
            }
        }

        $active = (bool) $r->active;
        // visible_to_resident / visible_to_calculator — новые колонки
        // (migration 2026_05_28_000020). На старых средах их может не
        // быть → дефолт = $active, чтобы не ломать обратную совместимость.
        $visibleToResident = property_exists($r, 'visible_to_resident')
            ? (bool) $r->visible_to_resident
            : $active;
        $visibleToCalculator = property_exists($r, 'visible_to_calculator')
            ? (bool) $r->visible_to_calculator
            : ($active && ! (bool) $r->has_red);
        return [
            'id'                   => (int) $r->id,
            'name'                 => $r->name,
            'productId'            => (int) $r->product_id,
            'providerName'         => $r->vendor,
            'vendorName'           => $r->category,
            'currencyName'         => $r->currency,
            'currency'             => null,
            'formLink'             => $r->form_link ?? null,
            'term'                 => $term,
            'termsSummary'         => $r->terms_summary,
            'yearsSummary'         => $r->years_summary,
            // Два формата ключей: ds_percent/fixed_cost (старый sync-from-sheet)
            // и ds_pct/price (новый products:audit-20260529). Читаем оба.
            'dsPercent'            => $first['ds_percent'] ?? $first['ds_pct'] ?? null,
            'fixedCost'            => $first['fixed_cost'] ?? $first['price'] ?? null,
            'pointsMethod'         => null,
            'pointsFormula'        => $first['formula'] ?? null,
            'pointsMin'            => $first['points'] ?? null,
            'pointsMax'            => $first['points_max'] ?? null,
            'kvPayoutYear'         => $first['year_kv'] ?? null,
            'calcComment'          => $r->comment_snippets ?? $first['comment'] ?? null,
            'active'               => $active,
            'visibleToResident'    => $visibleToResident,
            'visibleToCalculator'  => $visibleToCalculator,
            'hasRed'               => (bool) $r->has_red,
            'dominantColor'        => $r->dominant_color,
            'rateLines'            => (int) $r->rate_lines,
            'tariffs'              => $tariffs,
        ];
    }

    /** Subset of incoming program payload that maps onto programs_catalog columns. */
    private static function extractProgramPayload(Request $request): array
    {
        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'providerName'        => 'nullable|string|max:255',
            'vendorName'          => 'nullable|string|max:255',
            'currencyName'        => 'nullable|string|max:32',
            'currency'            => 'nullable',
            'term'                => 'nullable|integer',
            'active'              => 'nullable|boolean',
            'visibleToResident'   => 'nullable|boolean',
            'visibleToCalculator' => 'nullable|boolean',
            'formLink'            => 'nullable|string|max:1000',
        ]);

        $out = [
            'name'   => $data['name'],
            'vendor' => $data['providerName'] ?? null,
            'category' => $data['vendorName'] ?? null,
            'active' => $data['active'] ?? true,
        ];
        if (isset($data['currencyName'])) {
            $out['currency'] = $data['currencyName'];
        }
        if (isset($data['term'])) {
            $out['terms_summary'] = (string) $data['term'];
        }
        // Видимость (migration 2026_05_28_000020). По has() отличаем «не
        // прислали» от «прислали false» — иначе чекбокс «снять» не работал бы.
        if ($request->has('visibleToResident')) {
            $out['visible_to_resident'] = (bool) ($data['visibleToResident'] ?? true);
        }
        if ($request->has('visibleToCalculator')) {
            $out['visible_to_calculator'] = (bool) ($data['visibleToCalculator'] ?? true);
        }
        // formLink: keep null distinct from "not sent" via has() so operators
        // can clear the field by sending null explicitly.
        if ($request->has('formLink')) {
            $out['form_link'] = $data['formLink'];
        }
        return $out;
    }
}
