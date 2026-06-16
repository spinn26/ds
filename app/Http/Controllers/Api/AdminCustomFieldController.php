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
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'max:64'],
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
        // пустой список ролей = поле для всех.
        if (empty($data['roles'])) {
            $data['roles'] = null;
        }

        return $data;
    }

    /** GET /admin/users/{userId}/custom-fields — все активные поля + значения юзера. */
    public function userFields(int $userId): JsonResponse
    {
        $fields = CustomField::query()->where('active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        $values = \App\Models\CustomFieldValue::query()
            ->whereIn('field_id', $fields->pluck('id'))
            ->where('user_id', $userId)
            ->pluck('value', 'field_id');

        return response()->json([
            'fields' => $fields->map(fn ($f) => [
                'id' => $f->id, 'key' => $f->key, 'label' => $f->label, 'type' => $f->type,
                'required' => $f->required, 'options' => $f->options, 'description' => $f->description,
                'value' => $this->castOut($values[$f->id] ?? null, $f->type),
            ]),
        ]);
    }

    /** PUT /admin/users/{userId}/custom-fields — админ сохраняет значения юзера
     *  (без жёсткой проверки required — админ может заполнять частично). */
    public function saveUserValues(int $userId, Request $request): JsonResponse
    {
        $input = $request->validate(['values' => ['array']])['values'] ?? [];
        $fields = CustomField::query()->where('active', true)->get()->keyBy('id');

        foreach ($input as $fieldId => $raw) {
            $f = $fields->get((int) $fieldId);
            if (! $f) continue;
            \App\Models\CustomFieldValue::updateOrCreate(
                ['field_id' => $f->id, 'user_id' => $userId],
                ['value' => $f->type === 'checkbox'
                    ? (filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                    : ($raw === null ? null : (string) $raw)]
            );
        }

        return response()->json(['message' => 'Сохранено']);
    }

    private function castOut($value, string $type)
    {
        if ($value === null) return $type === 'checkbox' ? false : null;
        return match ($type) {
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? $value + 0 : $value,
            default => $value,
        };
    }
}
