<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Управление фиче-флагами (только admin). */
class AdminFeatureFlagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['flags' => FeatureFlag::query()->orderBy('label')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $flag = FeatureFlag::create($this->validateData($request));
        FeatureFlag::flush();

        return response()->json(['flag' => $flag], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $flag = FeatureFlag::findOrFail($id);
        $flag->update($this->validateData($request, $id));
        FeatureFlag::flush();

        return response()->json(['flag' => $flag->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        FeatureFlag::findOrFail($id)->delete();
        FeatureFlag::flush();

        return response()->json(['message' => 'Флаг удалён']);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('feature_flags', 'key')->ignore($id)],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'enabled' => ['boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'max:64'],
        ]);
        $data['enabled'] = (bool) ($data['enabled'] ?? false);
        if (empty($data['roles'])) $data['roles'] = null;

        return $data;
    }
}
