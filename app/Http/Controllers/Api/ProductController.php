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

        $hasAccess = $this->checkAccess($consultant)['hasAccess'] ?? false;
        $productRows = $query->orderBy('name')->get();

        // Gate products by education-course completion:
        // if a product has an active linked course, it's only "available" when
        // the user has a completion record for EVERY such course.
        // Products with no linked course fall back to the legacy gate ($hasAccess).
        $coursesByProduct = collect();
        $completedCourseIds = [];
        if (Schema::hasTable('education_courses') && $productRows->isNotEmpty()) {
            $coursesByProduct = DB::table('education_courses')
                ->where('active', true)
                ->whereNotNull('product_id')
                ->whereIn('product_id', $productRows->pluck('id'))
                ->get()
                ->groupBy('product_id');

            if (Schema::hasTable('education_course_completions') && $coursesByProduct->isNotEmpty()) {
                $completedCourseIds = DB::table('education_course_completions')
                    ->where('user_id', $user->id)
                    ->whereIn('course_id', $coursesByProduct->flatten(1)->pluck('id'))
                    ->pluck('course_id')
                    ->all();
            }
        }
        $completedSet = array_flip($completedCourseIds);

        $products = $productRows->map(function ($p) use ($consultant, $typeToCategory, $allCategories, $hasAccess, $coursesByProduct, $completedSet) {
            $testPassed = $consultant
                ? $this->isTestPassedForProduct($consultant, $p->id)
                : false;

            // Resolve category via productType
            $categoryId = $p->productType ? ($typeToCategory[$p->productType] ?? null) : null;
            $cat = $categoryId ? ($allCategories[$categoryId] ?? null) : null;

            $linkedCourses = $coursesByProduct[$p->id] ?? collect();
            if ($linkedCourses->isNotEmpty()) {
                $allPassed = $linkedCourses->every(fn ($c) => isset($completedSet[$c->id]));
                $available = $allPassed;
            } else {
                $available = $hasAccess;
            }

            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description ?? null,
                'typeName' => $p->typeName ?? null,
                'active' => (bool) $p->active,
                'accessible' => $available,
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
                'requiredCourses' => $linkedCourses->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'completed' => isset($completedSet[$c->id]),
                ])->values(),
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

        // Любому активному ФК открываем продукты — тестов/реквизитов/акцепта не требуем.
        // testsPassed/requisitesVerified/documentsAccepted считаем справочно — фронт
        // их может показывать в подсказках, но гейт теперь только по активности.
        $isActive = (bool) $consultant->active;

        $testsPassed = ! empty($consultant->soldProducts);

        $requisitesVerified = ((int) $consultant->statusRequisites) === 3;
        if (! $requisitesVerified && Schema::hasTable('requisites')) {
            $requisitesVerified = Requisite::where('consultant', $consultant->id)
                ->whereNull('deletedAt')
                ->where('verified', true)
                ->exists();
        }

        $documentsAccepted = (bool) $consultant->acceptance;

        return [
            'hasAccess' => $isActive,
            'testsPassed' => $testsPassed,
            'requisitesVerified' => $requisitesVerified,
            'documentsAccepted' => $documentsAccepted,
            'needsRequisites' => false,
            'needsAcceptance' => false,
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
