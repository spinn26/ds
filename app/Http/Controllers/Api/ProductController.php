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

        $products = $query->orderBy('name')->get()->map(function ($p) use ($consultant) {
            $testPassed = $consultant
                ? $this->isTestPassedForProduct($consultant, $p->id)
                : false;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description ?? null,
                'active' => (bool) $p->active,
                'accessible' => $testPassed && ($this->checkAccess($consultant)['hasAccess'] ?? false),
                'testPassed' => $testPassed,
            ];
        });

        return response()->json([
            'products' => $products,
            'categories' => [],
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
