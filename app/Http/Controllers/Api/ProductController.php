<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\Requisite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        $accessCheck = $this->checkAccess($consultant);

        $query = DB::table('product')->where('active', true);

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }

        // Preload productType → productCategory mapping
        $typeToCategory = DB::table('productType')
            ->whereNotNull('productTypeCategory')
            ->pluck('productTypeCategory', 'id');

        $allCategories = DB::table('productCategory')
            ->get()
            ->keyBy('id');

        $products = $query->orderBy('name')->get()->map(function ($p) use ($consultant, $typeToCategory, $allCategories) {
            $testPassed = $consultant
                ? $this->isTestPassedForProduct($consultant, $p->id)
                : false;

            // Resolve category via productType
            $categoryId = $p->productType ? ($typeToCategory[$p->productType] ?? null) : null;
            $cat = $categoryId ? ($allCategories[$categoryId] ?? null) : null;

            $available = $testPassed && ($this->checkAccess($consultant)['hasAccess'] ?? false);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description ?? null,
                'typeName' => $p->typeName ?? null,
                'active' => (bool) $p->active,
                'accessible' => $available,
                // Frontend reads .available and .url; keep .accessible for back-compat
                'available' => $available,
                'url' => $p->openProductUrl ?? null,
                'imageUrl' => $p->imageUrl ?? null,
                'educationUrl' => $p->educationUrl ?? null,
                'instructionUrl' => $p->instructionUrl ?? null,
                'testPassed' => $testPassed,
                'category' => $cat ? [
                    'id' => $cat->id,
                    'name' => $cat->productCategoryName,
                ] : null,
            ];
        });

        // Categories from productCategory table
        $categories = DB::table('productCategory')
            ->orderBy('productCategoryName')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->productCategoryName]);

        return response()->json([
            'products' => $products,
            'categories' => $categories,
            'accessCheck' => $accessCheck,
        ]);
    }

    private function checkAccess(?Consultant $consultant): array
    {
        if (! $consultant) {
            return [
                'hasAccess' => false,
                'testsPassed' => false,
                'requisitesVerified' => false,
                'documentsAccepted' => false,
            ];
        }

        $testsPassed = ! empty($consultant->soldProducts);

        // Реквизиты: проверяем через statusRequisites = 3 (verified) на консультанте
        $requisitesVerified = ((int) $consultant->statusRequisites) === 3;

        // Если нет — пробуем через таблицу requisites
        if (! $requisitesVerified && Schema::hasTable('requisites')) {
            $requisitesVerified = Requisite::where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->where('verified', true)
                ->exists();
        }

        $documentsAccepted = (bool) $consultant->acceptance;

        return [
            'hasAccess' => $testsPassed && $requisitesVerified && $documentsAccepted,
            'testsPassed' => $testsPassed,
            'requisitesVerified' => $requisitesVerified,
            'documentsAccepted' => $documentsAccepted,
            'needsRequisites' => $testsPassed && ! $requisitesVerified,
            'needsAcceptance' => $testsPassed && $requisitesVerified && ! $documentsAccepted,
        ];
    }

    private function isTestPassedForProduct(Consultant $consultant, int $productId): bool
    {
        $soldProducts = $consultant->soldProducts ?? '';
        if (empty($soldProducts)) {
            return false;
        }

        $passedIds = array_map('intval', explode(',', (string) $soldProducts));

        return in_array($productId, $passedIds);
    }
}
