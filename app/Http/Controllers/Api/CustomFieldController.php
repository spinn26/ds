<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Кастомные поля для текущего пользователя: получить активные поля + свои
 * значения, сохранить значения с проверкой обязательных.
 */
class CustomFieldController extends Controller
{
    /** GET /custom-fields — активные поля + значения текущего пользователя. */
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('custom_fields')) {
            return response()->json(['fields' => [], 'values' => []]);
        }

        $userId = $request->user()->id;
        $userRoles = array_filter(array_map('trim', explode(',', (string) ($request->user()->role ?? ''))));

        $fields = CustomField::query()->where('active', true)
            ->orderBy('sort_order')->orderBy('id')->get()
            // Привязка к ролям: пустой roles = всем; иначе — пересечение с ролями юзера.
            ->filter(function ($f) use ($userRoles) {
                $roles = $f->roles ?? [];
                return empty($roles) || count(array_intersect($roles, $userRoles)) > 0;
            })
            ->values();

        $values = CustomFieldValue::query()
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

    /** PUT /custom-fields/values — сохранить значения, проверив обязательные. */
    public function updateValues(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $input = $request->validate(['values' => ['array']])['values'] ?? [];

        $fields = CustomField::query()->where('active', true)->get();
        $errors = [];

        foreach ($fields as $f) {
            $raw = $input[$f->id] ?? ($input[$f->key] ?? null);
            $normalized = $this->normalize($raw, $f->type);

            if ($f->required && $this->isEmpty($normalized, $f->type)) {
                $errors["values.{$f->id}"] = ["Поле «{$f->label}» обязательно для заполнения"];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($fields, $input, $userId) {
            foreach ($fields as $f) {
                if (! array_key_exists($f->id, $input) && ! array_key_exists($f->key, $input)) {
                    continue;
                }
                $raw = $input[$f->id] ?? ($input[$f->key] ?? null);
                CustomFieldValue::updateOrCreate(
                    ['field_id' => $f->id, 'user_id' => $userId],
                    ['value' => $this->normalize($raw, $f->type)]
                );
            }
        });

        return response()->json(['message' => 'Сохранено']);
    }

    private function isEmpty($value, string $type): bool
    {
        if ($type === 'checkbox') {
            return ! filter_var($value, FILTER_VALIDATE_BOOLEAN); // обязательный чекбокс = должен быть отмечен
        }
        return $value === null || $value === '';
    }

    private function normalize($value, string $type): ?string
    {
        if ($value === null) return null;
        return match ($type) {
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            default => (string) $value,
        };
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
