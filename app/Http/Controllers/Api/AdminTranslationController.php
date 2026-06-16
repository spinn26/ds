<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TranslationOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Управление переопределениями строк интерфейса (только admin). */
class AdminTranslationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'overrides' => TranslationOverride::query()->orderBy('locale')->orderBy('key')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(['ru', 'en'])],
            'key' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9_.\-]+$/'],
            'value' => ['nullable', 'string', 'max:2000'],
        ]);

        $row = TranslationOverride::updateOrCreate(
            ['locale' => $data['locale'], 'key' => $data['key']],
            ['value' => $data['value'] ?? '']
        );

        return response()->json(['override' => $row], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        TranslationOverride::findOrFail($id)->delete();

        return response()->json(['message' => 'Удалено']);
    }
}
