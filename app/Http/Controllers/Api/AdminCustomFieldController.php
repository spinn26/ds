<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Управление кастомными полями пользователей (только admin).
 * Типы: text|textarea|number|date|select|checkbox.
 */
class AdminCustomFieldController extends Controller
{
    private const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];

    public function index(): JsonResponse
    {
        $fields = CustomField::query()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['fields' => $fields, 'types' => self::TYPES]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $field = CustomField::create($data);

        return response()->json(['field' => $field], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $field = CustomField::findOrFail($id);
        $data = $this->validateData($request, $id);
        $field->update($data);

        return response()->json(['field' => $field->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        CustomField::findOrFail($id)->delete(); // values каскадно удалятся

        return response()->json(['message' => 'Поле удалено']);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'key' => [
                'required', 'string', 'max:64', 'alpha_dash',
                Rule::unique('custom_fields', 'key')->ignore($id),
            ],
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'required' => ['boolean'],
            'active' => ['boolean'],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $data['required'] = (bool) ($data['required'] ?? false);
        $data['active'] = (bool) ($data['active'] ?? true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        // options нужны только для select.
        if ($data['type'] !== 'select') {
            $data['options'] = null;
        }

        return $data;
    }
}
