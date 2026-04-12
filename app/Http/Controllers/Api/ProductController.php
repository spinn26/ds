<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Models\Requisite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Список продуктов с проверкой доступа.
     * Продукт доступен если: пройден тест + реквизиты верифицированы + акцепт документов.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('person', $user->id)->first();

        // Проверка условий доступа
        $accessCheck = $this->checkAccess($consultant);

        $query = DB::table('product')->where('active', true);

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('category')) {
            $query->where('type', $request->category);
        }

        $products = $query->orderBy('name')->get()->map(function ($p) use ($consultant) {
            // Проверяем, пройден ли тест по этому продукту
            $testPassed = $consultant
                ? $this->isTestPassedForProduct($consultant->id, $p->id)
                : false;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description ?? null,
                'type' => $p->type ?? null,
                'typeName' => $p->type ? DB::table('productType')->where('id', $p->type)->value('name') : null,
                'active' => (bool) $p->active,
                'accessible' => $testPassed,
                'testPassed' => $testPassed,
                'visibleToResident' => (bool) ($p->visibleToResident ?? false),
            ];
        });

        // Категории продуктов
        $categories = DB::table('productType')
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]);

        return response()->json([
            'products' => $products,
            'categories' => $categories,
            'accessCheck' => $accessCheck,
        ]);
    }

    /**
     * Проверка условий доступа к разделу Продукты.
     */
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

        // 1. Пройден ли хотя бы один тест
        $testsPassed = $this->hasAnyTestPassed($consultant->id);

        // 2. Реквизиты верифицированы
        $requisite = Requisite::where('consultant', $consultant->id)
            ->active()
            ->where('verified', true)
            ->first();
        $requisitesVerified = $requisite !== null;

        // 3. Акцепт документов
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

    private function hasAnyTestPassed(int $consultantId): bool
    {
        // Проверяем soldProducts у консультанта (список пройденных продуктов)
        $consultant = Consultant::find($consultantId);
        $soldProducts = $consultant->soldProducts ?? '';

        return ! empty($soldProducts);
    }

    private function isTestPassedForProduct(int $consultantId, int $productId): bool
    {
        $consultant = Consultant::find($consultantId);
        $soldProducts = $consultant->soldProducts ?? '';

        if (empty($soldProducts)) {
            return false;
        }

        $passedIds = array_map('intval', explode(',', $soldProducts));

        return in_array($productId, $passedIds);
    }
}
