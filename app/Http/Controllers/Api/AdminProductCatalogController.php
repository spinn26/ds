<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AppliesSorting;
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
/**
 * Каталог продуктов и программ — ЕДИНСТВЕННОЕ место, где они правятся.
 *
 * До слияния каталогов (13.08.2026) карточка зеркалилась в legacy-таблицы
 * product/program: часть полей жила только там, и способ начисления ЛП или %ДС
 * программы из интерфейса поменять было нельзя. Теперь legacy — представления
 * поверх каталога, зеркалирование убрано, правится всё здесь.
 */
class AdminProductCatalogController extends Controller
{
    use PaginatesRequests;
    use AppliesSorting;

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
            ->groupBy('p.id', 'p.name', 'p.type', 'p.provider_name', 'p.open_product_url', 'p.active', 'p.created_at',
                'p.image_url', 'p.hero_image', 'p.description', 'p.legacy_product_id',
                'p.visible_to_resident', 'p.visible_to_calculator', 'p.is_primary',
                'p.accrual_forecast_months')
            ->select([
                'p.id',
                'p.name',
                'p.type',
                'p.provider_name',
                'p.open_product_url',
                'p.active',
                'p.created_at',
                'p.image_url',
                'p.hero_image',
                'p.description',
                'p.legacy_product_id',
                'p.visible_to_resident',
                'p.visible_to_calculator',
                'p.is_primary',
                'p.accrual_forecast_months',
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
        // «Искл. из калькулятора» = visible_to_calculator=false. Подбор продуктов
        // при заведении транзакций/в калькуляторе передаёт visible_to_calculator=1,
        // чтобы исключённые продукты не выводились. Админ-список продуктов
        // параметр не передаёт → видит все (для редактирования флага).
        if ($request->filled('visible_to_calculator')) {
            $q->where('p.visible_to_calculator', filter_var($request->input('visible_to_calculator'), FILTER_VALIDATE_BOOLEAN));
        }

        $total = DB::table(DB::raw('(' . $q->toSql() . ') as t'))
            ->mergeBindings($q)
            ->count();

        // Сортировка по клику на заголовок. programCount — агрегат
        // COUNT(g.id) (alias programs_count, допустим в ORDER BY у Postgres).
        // Дефолт сохраняет прежний порядок: больше программ → выше, затем имя.
        $this->applySorting($q, $request, [
            'name'                 => 'p.name',
            'active'               => 'p.active',
            'visibleToResident'    => 'p.visible_to_resident',
            'visibleToCalculator'  => 'p.visible_to_calculator',
            'programCount'         => 'programs_count',
        ], 'programs_count', 'desc');

        $rows = $q
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
            ->groupBy('p.id', 'p.name', 'p.type', 'p.provider_name', 'p.open_product_url', 'p.active', 'p.created_at',
                'p.image_url', 'p.hero_image', 'p.description', 'p.legacy_product_id',
                'p.visible_to_resident', 'p.visible_to_calculator', 'p.is_primary',
                'p.accrual_forecast_months')
            ->where('p.id', $id)
            ->select([
                'p.id', 'p.name', 'p.type', 'p.provider_name', 'p.open_product_url', 'p.active', 'p.created_at',
                'p.image_url', 'p.hero_image', 'p.description', 'p.legacy_product_id',
                'p.visible_to_resident', 'p.visible_to_calculator', 'p.is_primary',
                'p.accrual_forecast_months',
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
            'providerName'   => 'nullable|string|max:255',
            'active'         => 'nullable|boolean',
            'openProductUrl' => 'nullable|string|max:1000',
            'description'    => 'nullable|string|max:4000',
            'imageUrl'       => 'nullable|string|max:1000',
            'heroImage'      => 'nullable|string|max:1000',
            // Основной (true) / дополнительный (false) продукт. По умолчанию
            // основной — витрина ФК выводит такие первыми.
            'isPrimary'      => 'nullable|boolean',
        ]);

        $id = DB::table('products_catalog')->insertGetId([
            'name'             => $payload['name'],
            'type'             => $payload['type'] ?? null,
            'provider_name'    => $payload['providerName'] ?? null,
            'open_product_url' => $payload['openProductUrl'] ?? null,
            'description'      => $payload['description'] ?? null,
            'image_url'        => $payload['imageUrl'] ?? null,
            'hero_image'       => $payload['heroImage'] ?? null,
            'active'           => $payload['active'] ?? true,
            'is_primary'       => $payload['isPrimary'] ?? true,
            'imported_from'    => 'admin-ui',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Новый продукт должен сразу появиться в калькуляторе (матрица кэшируется
        // на 10 мин) — как и при update/toggle. Раньше сброса тут не было.
        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix:v4');

        return $this->showProduct($id);
    }

    /** PUT /admin/products-catalog/{id} */
    public function updateProduct(int $id, Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'type'                => 'nullable|string|max:255',
            'providerName'        => 'nullable|string|max:255',
            'active'              => 'nullable|boolean',
            'openProductUrl'      => 'nullable|string|max:1000',
            'description'         => 'nullable|string|max:4000',
            'imageUrl'            => 'nullable|string|max:1000',
            'heroImage'           => 'nullable|string|max:1000',
            'isPrimary'           => 'nullable|boolean',
            // Видимость продукта-зонтика (migration 2026_05_28_000030).
            // visible_to_calculator=false убирает продукт и ВСЕ его программы
            // из калькулятора без необходимости снимать active.
            'visibleToResident'   => 'nullable|boolean',
            'visibleToCalculator' => 'nullable|boolean',
            // Прогноз начисления: месяцев к месяцу активации (0/1/2…).
            'accrualForecastMonths' => 'nullable|integer|min:0|max:24',
            // Остальные поля формы (productType, educationCourseId, ...) пока
            // не маппятся на catalog-схему и тихо игнорируются.
        ]);

        // null — валидное значение (очистить поле), поэтому через has()
        // отличаем «не прислали» от «прислали null».
        $update = ['updated_at' => now()];
        if ($request->has('name'))                $update['name']                  = $payload['name'];
        if ($request->has('type'))                $update['type']                  = $payload['type'];
        if ($request->has('providerName'))        $update['provider_name']         = $payload['providerName'];
        if ($request->has('active'))              $update['active']                = $payload['active'];
        if ($request->has('openProductUrl'))      $update['open_product_url']      = $payload['openProductUrl'];
        if ($request->has('description'))         $update['description']           = $payload['description'];
        if ($request->has('imageUrl'))            $update['image_url']             = $payload['imageUrl'];
        if ($request->has('heroImage'))           $update['hero_image']            = $payload['heroImage'];
        if ($request->has('isPrimary'))           $update['is_primary']            = (bool) ($payload['isPrimary'] ?? true);
        if ($request->has('visibleToResident'))   $update['visible_to_resident']   = (bool) ($payload['visibleToResident'] ?? true);
        if ($request->has('visibleToCalculator')) $update['visible_to_calculator'] = (bool) ($payload['visibleToCalculator'] ?? true);
        if ($request->has('accrualForecastMonths')) $update['accrual_forecast_months'] = (int) ($payload['accrualForecastMonths'] ?? 0);

        DB::table('products_catalog')->where('id', $id)->update($update);

        // Переименование продукта → каскад в денорм-копии имени, иначе менеджер
        // контрактов/отчёты показывают старое имя (читают contract.productName напрямую).
        if ($request->has('name')) {
            $this->propagateProductName($id, $payload['name']);
        }

        // Поставщик с продукта проставляем всем его программам, чтобы отчёты
        // («Комиссии», «Матрица продаж») его подхватили (они читают legacy
        // program.providerName). Только при непустом значении — иначе очистка
        // поля случайно обнулила бы поставщика у всех программ.
        if ($request->has('providerName')) {
            $provider = trim((string) ($payload['providerName'] ?? ''));
            if ($provider !== '') {
                $this->propagateProviderToPrograms($id, $provider);
            }
        }

        // Смена «месяцев прогноза начисления» → пересчёт contract.accrual_forecast
        // по всем контрактам (иначе прогноз остаётся со старым числом месяцев).
        if ($request->has('accrualForecastMonths')) {
            app(\App\Services\AccrualForecastService::class)->recomputeAll();
        }

        // Калькулятор кэширует матрицу продуктов на 10 минут — без инвалидации
        // снятая галка появится в дропдауне только через эти 10 минут.
        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix:v4');

        return $this->showProduct($id);
    }

    /**
     * Проставить поставщика всем программам продукта.
     *  - programs_catalog.vendor — чтобы редактор программы показывал то же;
     *  - legacy program.providerName — чтобы отчёты/комиссии подхватили.
     */
    /**
     * Каскад нового имени ПРОДУКТА в денорм-копии по legacy-FK:
     *   contract.productName, dsCommission.productName.
     */
    private function propagateProductName(int $catalogProductId, ?string $name): void
    {
        // ⚠ Ключ — id карточки: после слияния каталогов id каталога РАВЕН
        // прежнему legacy id, а у карточек, заведённых позже, legacy_product_id
        // пуст. Проверка на него молча обрывала каскад переименования.
        DB::table('contract')->where('product', $catalogProductId)->update(['productName' => $name]);
        DB::table('dsCommission')->where('product', $catalogProductId)->update(['productName' => $name]);
    }

    /**
     * Каскад нового имени ПРОГРАММЫ в денорм-копии по legacy-FK:
     *   contract.programName, dsCommission.programName.
     */
    private function propagateProgramName(int $catalogProgramId, ?string $name): void
    {
        DB::table('contract')->where('program', $catalogProgramId)->update(['programName' => $name]);
        DB::table('dsCommission')->where('program', $catalogProgramId)->update(['programName' => $name]);
    }

    private function propagateProviderToPrograms(int $catalogProductId, string $provider): void
    {
        $programs = DB::table('programs_catalog')
            ->where('product_id', $catalogProductId)
            ->get(['id', 'legacy_program_id']);

        if ($programs->isEmpty()) return;

        DB::table('programs_catalog')
            ->where('product_id', $catalogProductId)
            ->update(['vendor' => $provider, 'updated_at' => now()]);

        // Поставщик хранится в самом каталоге; отдельная запись в legacy
        // больше не нужна — program стала представлением поверх каталога.
        DB::table('programs_catalog')
            ->where('product_id', $catalogProductId)
            ->update(['provider_name' => $provider]);
    }

    /** DELETE /admin/products-catalog/{id} — soft-delete (active=false) to match page wording. */
    public function destroyProduct(int $id): JsonResponse
    {
        DB::table('products_catalog')->where('id', $id)->update([
            'active'     => false,
            'updated_at' => now(),
        ]);
        // Иначе деактивированный продукт висит в кэше калькулятора до 10 мин,
        // и его программу можно выбрать → «Программа не найдена или неактивна».
        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix:v4');
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
        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix:v4');
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

    /**
     * GET /admin/products-catalog/programs — ВСЕ программы одним списком.
     *
     * Раньше программу можно было увидеть, только открыв карточку её продукта:
     * 690 программ по сотне продуктов, и чтобы сравнить %ДС или способ
     * начисления ЛП у соседних программ, приходилось лазить по карточкам.
     * Здесь они все сразу, со всеми расчётными параметрами и фильтрами.
     */
    public function programsAll(Request $request): JsonResponse
    {
        $q = DB::table('programs_catalog as pg')
            ->leftJoin('products_catalog as pc', 'pc.id', '=', 'pg.product_id');

        if ($request->filled('search')) {
            $like = '%'.$request->input('search').'%';
            $q->where(function ($w) use ($like) {
                $w->where('pg.name', 'ilike', $like)
                    ->orWhere('pc.name', 'ilike', $like)
                    ->orWhere('pg.provider_name', 'ilike', $like);
            });
        }
        if ($request->filled('product_id')) {
            $q->where('pg.product_id', (int) $request->input('product_id'));
        }
        if ($request->filled('active')) {
            $q->where('pg.active', $request->boolean('active'));
        }
        // «Без расчётных параметров» — программы, по которым калькулятор не
        // знает ни способа ЛП, ни %ДС: их и надо дозаполнить в первую очередь.
        if ($request->boolean('needs_setup')) {
            $q->whereNull('pg.points_method')->whereNull('pg.ds_percent');
        }

        $total = $q->count();

        $rows = $q->orderBy('pc.name')->orderBy('pg.name')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get([
                'pg.*',
                'pc.name as product_name',
                'pc.active as product_active',
            ]);

        $data = $rows->map(function ($r) {
            $row = self::programRow($r);
            $row['productName'] = $r->product_name;
            $row['productActive'] = (bool) $r->product_active;
            $row['tariffCount'] = is_array($row['tariffs']) ? count($row['tariffs']) : 0;

            return $row;
        });

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /** POST /admin/products-catalog/{id}/programs */
    public function storeProgram(int $productId, Request $request): JsonResponse
    {
        $payload = self::extractProgramPayload($request);

        // Карточка, legacy-строка и тарифы %ДС — одной транзакцией: иначе
        // программа сохранялась, а тариф до расчёта не доезжал, и узнавали об
        // этом по ошибке «Не найден тариф %ДС» при расчёте комиссий.
        [$newId, $sync] = DB::transaction(function () use ($payload, $productId) {
            $id = DB::table('programs_catalog')->insertGetId(array_merge($payload, [
                'product_id'     => $productId,
                'imported_from'  => 'admin-ui',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]));

            return [$id, $this->pushTariffsToDsCommission($id)];
        });

        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix:v4');

        return $this->withSyncInfo($this->showSingleProgram($newId), $sync);
    }

    /** PUT /admin/products-catalog/{productId}/programs/{programId} */
    public function updateProgram(int $productId, int $programId, Request $request): JsonResponse
    {
        $payload = self::extractProgramPayload($request);
        $payload['updated_at'] = now();

        $sync = DB::transaction(function () use ($payload, $programId, $productId, $request) {
            DB::table('programs_catalog')
                ->where('id', $programId)
                ->where('product_id', $productId)
                ->update($payload);
            $result = $this->pushTariffsToDsCommission($programId);

            // Переименование программы → каскад в contract.programName / dsCommission.programName.
            if ($request->has('name')) {
                $this->propagateProgramName($programId, $request->input('name'));
            }

            return $result;
        });

        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix:v4');

        return $this->withSyncInfo($this->showSingleProgram($programId), $sync);
    }

    /** DELETE /admin/products-catalog/{productId}/programs/{programId} — soft via active=false. */
    public function destroyProgram(int $productId, int $programId): JsonResponse
    {
        DB::table('programs_catalog')
            ->where('id', $programId)
            ->where('product_id', $productId)
            ->update(['active' => false, 'updated_at' => now()]);
        \Illuminate\Support\Facades\Cache::forget('calculator:product-matrix:v4');
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
            // Поставщик на уровне продукта (migration 2026_06_26_000010).
            // На list-запросе колонка может не выбираться → guard.
            'providerName'         => property_exists($r, 'provider_name') ? $r->provider_name : null,
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
            // Основной / дополнительный (migration 2026_06_15_000050).
            'isPrimary'            => property_exists($r, 'is_primary')
                ? (bool) $r->is_primary
                : true,
            'hasProperty'          => false,
            'hasTerm'              => $hasTerm,
            'hasYearKv'            => $hasYearKv,
            'publishStatus'        => $active ? 'published' : 'draft',
            'programCount'         => $programs,
            'programsRed'          => (int) ($r->programs_red ?? 0),
            'programsActive'       => (int) ($r->programs_active ?? 0),
            // «Прогноз начисления» — месяцев к месяцу активации (0/1/2).
            'accrualForecastMonths' => property_exists($r, 'accrual_forecast_months')
                ? (int) $r->accrual_forecast_months
                : 0,
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
            'providerName'         => $r->provider_name ?? $r->vendor,
            'vendorName'           => $r->category,
            'categoryName'         => $r->category_name ?? null,
            'currencyName'         => $r->currency,
            'currency'             => null,
            'formLink'             => $r->form_link ?? null,
            'term'                 => $term,
            'termContract'         => $r->term_contract ?? null,
            'termsSummary'         => $r->terms_summary,
            'yearsSummary'         => $r->years_summary,
            // Приоритет у собственных колонок каталога — они и участвуют в
            // расчёте. Тарифная строка осталась фолбэком для карточек, куда
            // расчётные поля ещё не заполнены: два формата ключей,
            // ds_percent/fixed_cost (sync-from-sheet) и ds_pct/price (аудит).
            'dsPercent'            => $r->ds_percent ?? $first['ds_percent'] ?? $first['ds_pct'] ?? null,
            'fixedCost'            => $r->fixed_cost ?? $first['fixed_cost'] ?? $first['price'] ?? null,
            'pointsMethod'         => $r->points_method ?? null,
            'pointsFormula'        => $r->points_formula ?? $first['formula'] ?? null,
            'pointsMin'            => $r->points_min ?? $first['points'] ?? null,
            'pointsMax'            => $r->points_max ?? $first['points_max'] ?? null,
            'kvPayoutYear'         => $r->kv_payout_year ?? $first['year_kv'] ?? null,
            'commissionCalcProperty' => $r->commission_calc_property ?? null,
            'noCommission'         => (bool) ($r->no_commission ?? false),
            'calcComment'          => $r->comment_snippets ?? $first['comment'] ?? null,
            'active'               => $active,
            'visibleToResident'    => $visibleToResident,
            'visibleToCalculator'  => $visibleToCalculator,
            'hasRed'               => (bool) $r->has_red,
            'dominantColor'        => $r->dominant_color,
            'rateLines'            => (int) $r->rate_lines,
            'tariffs'              => $tariffs,
            'legacyProgramId'      => isset($r->legacy_program_id) ? (int) $r->legacy_program_id : null,
        ];
    }



    /**
     * После сохранения тарифов в каталоге — пробрасываем %ДС в `dsCommission`
     * (по ней считаются комиссии транзакций). Только текущее окно дат, только
     * не-is_red строки; однозначные совпадения обновляются, отсутствующие
     * создаются — см. DsCommissionSync.
     *
     * ⚠ Раньше ошибка синка глушилась report() и сохранение проходило: тариф в
     * карточке есть, в расчёте нет, и расхождение всплывало через недели —
     * ошибкой «Не найден тариф %ДС» или заниженной комиссией. Теперь исключение
     * летит наружу и откатывает всю транзакцию: лучше не сохранить программу,
     * чем сохранить её с тарифом, который не считается.
     *
     * @return array{synced:bool, reason?:string, result?:array<string,mixed>}
     */
    private function pushTariffsToDsCommission(int $catalogProgramId): array
    {
        $row = DB::table('programs_catalog')->where('id', $catalogProgramId)
            ->first(['id', 'legacy_program_id', 'tariffs']);
        if (! $row) {
            return ['synced' => false, 'reason' => 'Программа не найдена'];
        }

        // ⚠ Ключ расчётной таблицы — id программы, а не legacy_program_id: после
        // слияния каталогов (13.08.2026) id каталога РАВЕН прежнему legacy id, а
        // у программ, заведённых после слияния, legacy_program_id пуст. Проверка
        // на него молча оставляла новые программы без %ДС в расчёте.
        $programId = (int) $row->id;

        $tariffs = json_decode((string) $row->tariffs, true);
        if (! is_array($tariffs) || ! $tariffs) {
            return ['synced' => false, 'reason' => 'Тарифы не заданы — обновлять нечего'];
        }

        // fillGaps=true: оператор ЗАВЁЛ тариф руками — значит недостающую строку
        // расчёта надо создать, а не пропустить. Без этого сохранение молча
        // ничего не меняло для комиссий («нет строки — создание только с
        // --fill-gaps»), и тариф жил только в витрине.
        $result = \App\Services\DsCommissionSync::syncFromTariffs($programId, $tariffs, true, true);

        return ['synced' => true, 'result' => $result];
    }

    /**
     * Добавляем к ответу итог синка тарифов, чтобы оператор видел его сразу, а
     * не узнавал о расхождении при расчёте комиссий.
     *
     * @param  array{synced:bool, reason?:string, result?:array<string,mixed>}  $sync
     */
    private function withSyncInfo(JsonResponse $response, array $sync): JsonResponse
    {
        $data = $response->getData(true);
        $data = is_array($data) ? $data : ['data' => $data];
        $data['dsCommissionSync'] = $sync;

        return response()->json($data, $response->getStatusCode());
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
            'categoryName'        => 'nullable|string|max:255',
            // Расчётные поля программы. Раньше правились только в legacy-таблице
            // Directual, куда доступа из интерфейса не было: оператор видел
            // тарифы, но не мог поменять способ начисления ЛП или %ДС
            // программы. После слияния каталогов правится всё здесь.
            'pointsMethod'           => 'nullable|string|max:64',
            'pointsFormula'          => 'nullable|string|max:1000',
            'pointsMin'              => 'nullable|numeric',
            'pointsMax'              => 'nullable|numeric',
            'dsPercent'              => 'nullable|numeric',
            'commissionCalcProperty' => 'nullable|string|max:255',
            'kvPayoutYear'           => 'nullable|string|max:64',
            'fixedCost'              => 'nullable|numeric',
            'termContract'           => 'nullable|string|max:64',
            'noCommission'           => 'nullable|boolean',
            // Тарифные строки — источник «Свойств»/%ДС для калькулятора.
            'tariffs'             => 'nullable|array',
            'tariffs.*.property'  => 'nullable|string|max:255',
            'tariffs.*.term'      => 'nullable',
            'tariffs.*.year_kv'   => 'nullable',
            'tariffs.*.ds_pct'    => 'nullable',
            'tariffs.*.formula'   => 'nullable|string|max:1000',
            'tariffs.*.comment'   => 'nullable|string|max:1000',
            'tariffs.*.currency'  => 'nullable|string|max:32',
            'tariffs.*.is_red'    => 'nullable|boolean',
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

        // Расчётные поля: пишем ТОЛЬКО присланные ключи (has()), иначе
        // частичное сохранение формы обнулило бы способ начисления ЛП или %ДС.
        $calcMap = [
            'pointsMethod'           => 'points_method',
            'pointsFormula'          => 'points_formula',
            'pointsMin'              => 'points_min',
            'pointsMax'              => 'points_max',
            'dsPercent'              => 'ds_percent',
            'commissionCalcProperty' => 'commission_calc_property',
            'kvPayoutYear'           => 'kv_payout_year',
            'fixedCost'              => 'fixed_cost',
            'termContract'           => 'term_contract',
            'categoryName'           => 'category_name',
        ];
        foreach ($calcMap as $in => $col) {
            if ($request->has($in)) {
                $out[$col] = $data[$in] ?? null;
            }
        }
        if ($request->has('noCommission')) {
            $out['no_commission'] = (bool) ($data['noCommission'] ?? false);
        }
        // Поставщик программы: в каталоге это provider_name, а vendor держит
        // прежнее назначение поля из формы.
        if ($request->has('providerName')) {
            $out['provider_name'] = $data['providerName'] ?? null;
        }
        // Тарифы — источник «Свойств»/%ДС для калькулятора. Пишем только если
        // прислали ключ (через has()), чтобы частичный апдейт их не затирал.
        if ($request->has('tariffs')) {
            $tariffs = self::normalizeTariffs($data['tariffs'] ?? []);
            $out['tariffs'] = json_encode($tariffs, JSON_UNESCAPED_UNICODE);
            // Денормализованные сводки, на которые опираются список и калькулятор.
            // Срок/Год КВ/Валюта программы — производные от построчных тарифов
            // (отдельных program-level полей в форме больше нет).
            $terms = [];
            $years = [];
            $currencies = [];
            $anyRed = false;
            foreach ($tariffs as $t) {
                if ($t['term'] !== null)     $terms[(string) $t['term']] = true;
                if ($t['year_kv'] !== null)  $years[(string) $t['year_kv']] = true;
                if ($t['currency'] !== null) $currencies[(string) $t['currency']] = true;
                if ($t['is_red'])            $anyRed = true;
            }
            $out['terms_summary'] = $terms ? implode(',', array_keys($terms)) : null;
            $out['years_summary'] = $years ? implode(',', array_keys($years)) : null;
            $out['currency']      = $currencies ? implode(' / ', array_keys($currencies)) : null;
            $out['rate_lines']    = count($tariffs);
            $out['has_red']       = $anyRed;
        }
        return $out;
    }

    /**
     * Нормализует входящие строки тарифа в канонический JSONB-формат
     * (ключи как в audit-каталоге: property/term/year_kv/ds_pct/formula/
     * comment/currency/is_red). Полностью пустые строки отбрасываются.
     */
    private static function normalizeTariffs($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) continue;
            $property = trim((string) ($row['property'] ?? ''));
            $term     = isset($row['term'])    && $row['term']    !== '' ? (string) $row['term']    : null;
            $yearKv   = isset($row['year_kv']) && $row['year_kv'] !== '' ? (string) $row['year_kv'] : null;
            $dsPct    = $row['ds_pct'] ?? null;
            $formula  = trim((string) ($row['formula'] ?? ''));
            $comment  = trim((string) ($row['comment'] ?? ''));
            $currency = trim((string) ($row['currency'] ?? ''));

            // Пропускаем полностью пустую строку.
            if ($property === '' && $term === null && $yearKv === null
                && ($dsPct === null || $dsPct === '') && $formula === '' && $comment === '') {
                continue;
            }

            $out[] = [
                'property' => $property !== '' ? $property : null,
                'term'     => $term,
                'year_kv'  => $yearKv,
                // Храним %ДС как строку ("72.5") — калькулятор парсит и её, и
                // legacy-формат "72,50%".
                'ds_pct'   => ($dsPct === null || $dsPct === '') ? null : (string) $dsPct,
                'formula'  => $formula !== '' ? $formula : null,
                'comment'  => $comment !== '' ? $comment : null,
                'currency' => $currency !== '' ? $currency : null,
                'is_red'   => (bool) ($row['is_red'] ?? false),
            ];
        }
        return $out;
    }
}
