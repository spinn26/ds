<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminProductController extends Controller
{
    use PaginatesRequests;

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
        $products = $query->orderBy('name')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'imageUrl' => $p->imageUrl,
                'heroImage' => $p->hero_image ?? null,
                'educationUrl' => $p->educationUrl,
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
                'publishedAt' => $p->published_at,
                'programCount' => Program::where('product', $p->id)->count(),
            ]);

        return response()->json(['data' => $products, 'total' => $total]);
    }

    /** Создать продукт */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $status = $request->input('publishStatus', 'draft');
        $product = Product::create([
            'name' => $request->name,
            'description' => $request->description,
            'imageUrl' => $request->imageUrl,
            'hero_image' => $request->input('heroImage'),
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
        ]);

        return response()->json(['message' => 'Продукт создан', 'id' => $product->id], 201);
    }

    /** Обновить продукт */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $request->validate(['name' => 'required|string|max:255']);

        $product->name = $request->name;
        $product->description = $request->description;
        $product->imageUrl = $request->imageUrl;
        $product->hero_image = $request->input('heroImage');
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

        return response()->json(['message' => 'Продукт обновлён']);
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

        $program = Program::create(array_merge(
            ['product' => $productId],
            $this->extractProgramPayload($request)
        ));

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
            'providerName', 'vendorName',
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
