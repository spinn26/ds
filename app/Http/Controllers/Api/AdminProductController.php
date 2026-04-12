<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminProductController extends Controller
{
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
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'active' => (bool) $p->active,
                'noComission' => (bool) $p->noComission,
                'visibleToResident' => (bool) $p->visibleToResident,
                'visibleToCalculator' => (bool) $p->visibleToCalculator,
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

        $product = Product::create([
            'name' => $request->name,
            'description' => $request->description,
            'active' => $request->boolean('active', true),
            'noComission' => $request->boolean('noComission', false),
            'visibleToResident' => $request->boolean('visibleToResident', false),
            'visibleToCalculator' => $request->boolean('visibleToCalculator', true),
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
        $product->active = $request->boolean('active');
        $product->noComission = $request->boolean('noComission');
        $product->visibleToResident = $request->boolean('visibleToResident');
        $product->visibleToCalculator = $request->boolean('visibleToCalculator');
        $product->save();

        return response()->json(['message' => 'Продукт обновлён']);
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
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'active' => (bool) $p->active,
                'visibleToResident' => (bool) $p->visibleToResident,
                'visibleToCalculator' => (bool) $p->visibleToCalculator,
                'term' => $p->term,
                'currency' => $p->currency,
                'currencyName' => $p->currency ? DB::table('currency')->where('id', $p->currency)->value('symbol') : null,
            ]);

        return response()->json($programs);
    }

    /** CRUD программы */
    public function storeProgram(Request $request, int $productId): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:255']);

        $program = Program::create([
            'name' => $request->name,
            'product' => $productId,
            'active' => $request->boolean('active', true),
            'visibleToResident' => $request->boolean('visibleToResident', false),
            'visibleToCalculator' => $request->boolean('visibleToCalculator', true),
            'term' => $request->term,
            'currency' => $request->currency,
        ]);

        return response()->json(['message' => 'Программа создана', 'id' => $program->id], 201);
    }

    public function updateProgram(Request $request, int $productId, int $programId): JsonResponse
    {
        $program = Program::findOrFail($programId);

        $program->name = $request->name ?? $program->name;
        $program->active = $request->boolean('active');
        $program->visibleToResident = $request->boolean('visibleToResident');
        $program->visibleToCalculator = $request->boolean('visibleToCalculator');
        $program->term = $request->term;
        $program->currency = $request->currency;
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
}
