<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Program;
use App\Support\LegacyId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminProductController extends Controller
{
    use PaginatesRequests;

    /**
     * Справочники для формы редактирования продукта — productType с
     * предзаполненной категорией, productCategory списком, и активные
     * education_courses для селекта «Привязанное обучение».
     */
    public function references(): JsonResponse
    {
        $categories = DB::table('productCategory')
            ->orderBy('productCategoryName')
            ->get(['id', 'productCategoryName as name']);

        $types = DB::table('productType')
            ->orderBy('productTypeName')
            ->get(['id', 'productTypeName as name', 'productTypeCategory as categoryId']);

        $courses = DB::table('education_courses')
            ->where('active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'product_id']);

        return response()->json([
            'categories' => $categories,
            'types' => $types,
            'courses' => $courses,
        ]);
    }

    /** Список продуктов */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query();

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('active')) {
            $query->where('active', $request->active === 'true');
        }

        $total = $query->count();
        $rows = $query->orderBy('name')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get();

        // Batch: привязанные education_courses (FK на стороне курса)
        // и количество программ — чтобы не плодить N+1 в .map().
        $productIds = $rows->pluck('id');
        $courseByProduct = DB::table('education_courses')
            ->whereIn('product_id', $productIds)
            ->pluck('id', 'product_id');
        $programCountByProduct = DB::table('program')
            ->whereIn('product', $productIds)
            ->select('product', DB::raw('count(*) as cnt'))
            ->groupBy('product')
            ->pluck('cnt', 'product');

        $products = $rows->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'imageUrl' => $p->imageUrl,
                'heroImage' => $p->hero_image ?? null,
                'productType' => $p->productType,
                'educationUrl' => $p->educationUrl,
                'educationCourseId' => $courseByProduct[$p->id] ?? null,
                'instructionUrl' => $p->instructionUrl,
                'openProductUrl' => $p->openProductUrl,
                'active' => (bool) $p->active,
                'noComission' => (bool) $p->noComission,
                'visibleToResident' => (bool) $p->visibleToResident,
                'visibleToCalculator' => (bool) $p->visibleToCalculator,
                // Config-флаги: какие параметры релевантны при создании
                // транзакции / в калькуляторе. Раньше показывались все
                // поля у каждого продукта, что путало оператора.
                'hasProperty' => (bool) ($p->has_property ?? false),
                'hasTerm' => (bool) ($p->has_term ?? false),
                'hasYearKv' => (bool) ($p->has_year_kv ?? false),
                'publishStatus' => $p->publish_status ?? 'published',
                'priority' => $p->priority ?? null,
                'publishedAt' => $p->published_at,
                'programCount' => (int) ($programCountByProduct[$p->id] ?? 0),
            ]);

        return response()->json(['data' => $products, 'total' => $total]);
    }

    /** Создать продукт */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'productType' => 'nullable|integer|exists:productType,id',
            'educationCourseId' => 'nullable|integer|exists:education_courses,id',
        ]);

        $status = $request->input('publishStatus', 'draft');

        // Legacy Directual-таблица `product`: колонка id — integer NOT NULL
        // без sequence/default, поэтому id генерим вручную через
        // LegacyId::next под advisory_xact_lock внутри транзакции.
        $product = DB::transaction(function () use ($request, $status) {
            $attrs = [
                'id' => LegacyId::next('product'),
                'name' => $request->name,
                'description' => $request->description,
                'imageUrl' => $request->imageUrl,
                'hero_image' => $request->input('heroImage'),
                'productType' => $request->input('productType'),
                'educationUrl' => $request->educationUrl,
                'instructionUrl' => $request->instructionUrl,
                'openProductUrl' => $request->openProductUrl,
                'active' => $request->boolean('active', true),
                'noComission' => $request->boolean('noComission', false),
                'visibleToResident' => $request->boolean('visibleToResident', false),
                'visibleToCalculator' => $request->boolean('visibleToCalculator', true),
                'has_property' => $request->boolean('hasProperty', false),
                'has_term' => $request->boolean('hasTerm', false),
                'has_year_kv' => $request->boolean('hasYearKv', false),
                'publish_status' => $status,
                'published_at' => $status === 'published' ? now() : null,
                'published_by' => $status === 'published' ? $request->user()?->id : null,
            ];
            return Product::create($attrs);
        });

        $this->syncEducationCourse($product->id, $request->input('educationCourseId'));

        return response()->json(['message' => 'Продукт создан', 'id' => $product->id], 201);
    }

    /**
     * Привязать education_course к продукту через product_id.
     * Снимает product_id со старого курса (если был) и проставляет
     * на новый — связь 1:1 на стороне курса.
     */
    private function syncEducationCourse(int $productId, $courseId): void
    {
        // Снять product_id со всех курсов, привязанных к этому продукту.
        DB::table('education_courses')->where('product_id', $productId)->update(['product_id' => null]);
        if ($courseId) {
            DB::table('education_courses')->where('id', (int) $courseId)->update(['product_id' => $productId]);
        }

        // Зеркалим в pivot education_course_product, чтобы конструктор курсов
        // видел привязку, сделанную со стороны продукта (связь 1:1 на стороне
        // продукта: один продукт → один курс).
        if (Schema::hasTable('education_course_product')) {
            DB::table('education_course_product')->where('product_id', $productId)->delete();
            if ($courseId) {
                DB::table('education_course_product')->insertOrIgnore([
                    'course_id' => (int) $courseId,
                    'product_id' => $productId,
                    'created_at' => now(),
                ]);
            }
        }
    }

    /** Обновить продукт */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'productType' => 'nullable|integer|exists:productType,id',
            'educationCourseId' => 'nullable|integer|exists:education_courses,id',
        ]);

        $product->name = $request->name;
        $product->description = $request->description;
        $product->imageUrl = $request->imageUrl;
        $product->hero_image = $request->input('heroImage');
        $product->productType = $request->input('productType');
        $product->educationUrl = $request->educationUrl;
        $product->instructionUrl = $request->instructionUrl;
        $product->openProductUrl = $request->openProductUrl;
        $product->active = $request->boolean('active');
        $product->noComission = $request->boolean('noComission');
        $product->visibleToResident = $request->boolean('visibleToResident');
        $product->visibleToCalculator = $request->boolean('visibleToCalculator');
        if ($request->has('hasProperty')) $product->has_property = $request->boolean('hasProperty');
        if ($request->has('hasTerm')) $product->has_term = $request->boolean('hasTerm');
        if ($request->has('hasYearKv')) $product->has_year_kv = $request->boolean('hasYearKv');

        if ($request->has('publishStatus')) {
            $newStatus = $request->input('publishStatus');
            $wasPublished = $product->publish_status === 'published';
            $product->publish_status = $newStatus;
            if ($newStatus === 'published' && ! $wasPublished) {
                $product->published_at = now();
                $product->published_by = $request->user()?->id;
            }
        }
        $product->save();

        if ($request->has('educationCourseId')) {
            $this->syncEducationCourse($product->id, $request->input('educationCourseId'));
        }

        return response()->json(['message' => 'Продукт обновлён']);
    }

    /**
     * Загрузка логотипа / hero-баннера.
     *
     * Принимает multipart/form-data (поле "file"), параметр "kind"="image"
     * для маленького логотипа или "kind"="hero" для широкого баннера.
     * Файл сохраняется в storage/app/public/products/ и возвращается URL
     * через `/storage/products/...` (требует `php artisan storage:link`).
     *
     * Возвращает обновлённый URL, который потом можно записать в product
     * через PUT /admin/products/{id} (фронт делает это автоматически).
     */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|image|max:4096', // 4MB
            'kind' => 'required|in:image,hero',
        ]);

        $product = Product::findOrFail($id);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = sprintf('%d-%s-%s.%s', $product->id, $request->kind, substr(md5(uniqid('', true)), 0, 8), $ext);
        $path = $file->storeAs('products', $name, 'public');
        $url = '/storage/' . $path;

        if ($request->kind === 'image') {
            $product->imageUrl = $url;
        } else {
            $product->hero_image = $url;
        }
        $product->save();

        return response()->json(['url' => $url]);
    }

    /** Быстрая публикация/снятие с публикации без открытия формы. */
    public function togglePublish(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $toPublished = $product->publish_status !== 'published';
        $product->publish_status = $toPublished ? 'published' : 'draft';
        if ($toPublished) {
            $product->published_at = now();
            $product->published_by = $request->user()?->id;
        }
        $product->save();

        return response()->json([
            'message' => $toPublished ? 'Продукт опубликован' : 'Продукт снят с публикации',
            'publishStatus' => $product->publish_status,
        ]);
    }

    /** Удалить продукт */
    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->active = false;
        $product->save();

        return response()->json(['message' => 'Продукт деактивирован']);
    }

    /** Программы продукта */
    public function programs(int $productId): JsonResponse
    {
        $programs = Program::where('product', $productId)
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => $this->programResource($p));

        return response()->json($programs);
    }

    /** CRUD программы */
    public function storeProgram(Request $request, int $productId): JsonResponse
    {
        $request->validate($this->programRules());

        // Legacy `program.id` — integer NOT NULL без default, см. store().
        $program = DB::transaction(function () use ($request, $productId) {
            return Program::create(array_merge(
                ['id' => LegacyId::next('program'), 'product' => $productId],
                $this->extractProgramPayload($request)
            ));
        });

        return response()->json(['message' => 'Программа создана', 'id' => $program->id], 201);
    }

    public function updateProgram(Request $request, int $productId, int $programId): JsonResponse
    {
        $program = Program::findOrFail($programId);

        $request->validate($this->programRules(patch: true));

        foreach ($this->extractProgramPayload($request, patch: true) as $k => $v) {
            $program->{$k} = $v;
        }
        $program->save();

        return response()->json(['message' => 'Программа обновлена']);
    }

    public function destroyProgram(int $productId, int $programId): JsonResponse
    {
        $program = Program::findOrFail($programId);
        $program->active = false;
        $program->save();

        return response()->json(['message' => 'Программа деактивирована']);
    }

    /** Shared response shape for GET /admin/products/{id}/programs. */
    private function programResource(Program $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'active' => (bool) $p->active,
            'visibleToResident' => (bool) $p->visibleToResident,
            'visibleToCalculator' => (bool) $p->visibleToCalculator,

            // New calculator-driving fields
            'fixedCost' => $p->fixedCost !== null ? (float) $p->fixedCost : null,
            'dsPercent' => $p->dsPercent !== null ? (float) $p->dsPercent : null,
            'pointsMethod' => $p->pointsMethod,
            'pointsFormula' => $p->pointsFormula,
            'pointsMin' => $p->pointsMin !== null ? (float) $p->pointsMin : null,
            'pointsMax' => $p->pointsMax !== null ? (float) $p->pointsMax : null,
            'kvPayoutYear' => $p->kvPayoutYear,

            // Legacy-schema fields
            'term' => $p->term,
            'termContract' => $p->termContract,
            'currency' => $p->currency,
            'currencyName' => $p->currency
                ? DB::table('currency')->where('id', $p->currency)->value('symbol')
                : null,
            'commissionCalcProperty' => $p->commissionCalcProperty,
            'dsCommission' => $p->dsCommission,
            'provider' => $p->provider,
            'vendor' => $p->vendor,
            'providerName' => $p->providerName,
            'vendorName' => $p->vendorName,
            'formLink' => $p->formLink,
            'calcComment' => $p->calcComment,
            'category' => $p->category,
            'productType' => $p->productType,
        ];
    }

    /** Validation rules — shared by store/update (patch drops `required` on name). */
    private function programRules(bool $patch = false): array
    {
        $req = $patch ? 'sometimes' : 'required';
        return [
            'name' => "$req|string|max:255",
            'active' => 'sometimes|boolean',
            'visibleToResident' => 'sometimes|boolean',
            'visibleToCalculator' => 'sometimes|boolean',
            'fixedCost' => 'nullable|numeric|min:0',
            'dsPercent' => 'nullable|numeric|min:0|max:100',
            'pointsMethod' => 'nullable|string|in:cost_div_100,amount_div_100,amount_times_ds,fixed',
            'pointsFormula' => 'nullable|string|max:500',
            'pointsMin' => 'nullable|numeric|min:0',
            'pointsMax' => 'nullable|numeric|min:0',
            'kvPayoutYear' => 'nullable|integer|min:0|max:50',
            'term' => 'nullable|integer',
            'termContract' => 'nullable|integer|exists:termContract,id',
            'currency' => 'nullable|integer|exists:currency,id',
            'commissionCalcProperty' => 'nullable|integer|exists:commissionCalcProperty,id',
            'calcComment' => 'nullable|string|max:1000',
            'category' => 'nullable|integer',
            'productType' => 'nullable|integer',
            'provider' => 'nullable|integer',
            'vendor' => 'nullable|integer',
            // Per spec ✅Продукты §2: «Поставщик» (free-text counterparty)
            // и «Свойство продукта» — опциональные строки, если в legacy
            // справочниках нужного варианта нет.
            'providerName' => 'nullable|string|max:255',
            'vendorName' => 'nullable|string|max:255',
            'formLink' => 'nullable|string|max:2000|url',
        ];
    }

    /** Select the writable keys off the request. */
    private function extractProgramPayload(Request $request, bool $patch = false): array
    {
        $keys = [
            'name', 'fixedCost', 'dsPercent', 'pointsMethod', 'pointsFormula',
            'pointsMin', 'pointsMax', 'kvPayoutYear', 'term', 'termContract',
            'currency', 'commissionCalcProperty', 'calcComment', 'category',
            'productType', 'provider', 'vendor',
            'providerName', 'vendorName', 'formLink',
        ];
        $out = [];
        foreach ($keys as $k) {
            if ($request->has($k) || ! $patch) {
                $out[$k] = $request->input($k);
            }
        }
        foreach (['active', 'visibleToResident', 'visibleToCalculator'] as $bk) {
            if ($request->has($bk)) {
                $out[$bk] = $request->boolean($bk);
            } elseif (! $patch) {
                $out[$bk] = ['active' => true, 'visibleToResident' => false, 'visibleToCalculator' => true][$bk];
            }
        }
        return $out;
    }
}
