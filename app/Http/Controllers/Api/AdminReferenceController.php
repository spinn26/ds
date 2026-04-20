<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminReferenceController extends Controller
{
    /**
     * Registry of small reference tables manageable through the admin panel.
     *
     * Each entry describes: label, physical table, field map and validation rules.
     * The controller only touches whitelisted columns.
     */
    private const CATALOGS = [
        'productCategory' => [
            'label' => 'Категории продуктов',
            'table' => 'productCategory',
            'primaryLabel' => 'productCategoryName',
            'orderBy' => 'productCategoryName',
            'fields' => [
                ['key' => 'productCategoryName', 'label' => 'Название', 'type' => 'string', 'required' => true],
                ['key' => 'visibleToResident', 'label' => 'Видна резидентам', 'type' => 'bool'],
            ],
        ],
        'productType' => [
            'label' => 'Типы продуктов',
            'table' => 'productType',
            'primaryLabel' => 'productTypeName',
            'orderBy' => 'productTypeName',
            'fields' => [
                ['key' => 'productTypeName', 'label' => 'Название', 'type' => 'string', 'required' => true],
                ['key' => 'categoryName', 'label' => 'Категория (текст)', 'type' => 'string'],
                ['key' => 'productTypeCategory', 'label' => 'Категория (ID)', 'type' => 'fkey', 'refTable' => 'productCategory', 'refLabel' => 'productCategoryName'],
                ['key' => 'active', 'label' => 'Активен', 'type' => 'bool'],
                ['key' => 'visibleToResident', 'label' => 'Виден резидентам', 'type' => 'bool'],
            ],
        ],
        'communicationCategory' => [
            'label' => 'Категории обращений',
            'table' => 'communicationCategory',
            'primaryLabel' => 'title',
            'orderBy' => 'title',
            'fields' => [
                ['key' => 'title', 'label' => 'Название', 'type' => 'string', 'required' => true],
            ],
        ],
        'directory_of_activities' => [
            'label' => 'Статусы активности',
            'table' => 'directory_of_activities',
            'primaryLabel' => 'name',
            'orderBy' => 'id',
            'fields' => [
                ['key' => 'name', 'label' => 'Название', 'type' => 'string', 'required' => true],
                ['key' => 'comment', 'label' => 'Комментарий', 'type' => 'text'],
            ],
        ],
        'type_contest' => [
            'label' => 'Типы конкурсов',
            'table' => 'type_contest',
            'primaryLabel' => 'type',
            'orderBy' => 'id',
            'fields' => [
                ['key' => 'type', 'label' => 'Название', 'type' => 'string', 'required' => true],
            ],
        ],
        'status_contest' => [
            'label' => 'Статусы конкурсов',
            'table' => 'status_contest',
            'primaryLabel' => 'name',
            'orderBy' => 'id',
            'fields' => [
                ['key' => 'name', 'label' => 'Название', 'type' => 'string', 'required' => true],
            ],
        ],
        'title' => [
            'label' => 'Титулы / звания',
            'table' => 'title',
            'primaryLabel' => 'title',
            'orderBy' => 'title',
            'fields' => [
                ['key' => 'title', 'label' => 'Название', 'type' => 'string', 'required' => true],
            ],
        ],
        'occupation' => [
            'label' => 'Род деятельности',
            'table' => 'occupation',
            'primaryLabel' => 'title',
            'orderBy' => 'title',
            'fields' => [
                ['key' => 'title', 'label' => 'Название', 'type' => 'string', 'required' => true],
            ],
        ],
        'meetingType' => [
            'label' => 'Типы встреч',
            'table' => 'meetingType',
            'primaryLabel' => 'title',
            'orderBy' => 'title',
            'fields' => [
                ['key' => 'title', 'label' => 'Название', 'type' => 'string', 'required' => true],
            ],
        ],
        'currency' => [
            'label' => 'Валюты',
            'table' => 'currency',
            // Legacy schema: nameRu / nameEn / currencyName — no plain `name`.
            'primaryLabel' => 'nameRu',
            'orderBy' => 'nameRu',
            'fields' => [
                ['key' => 'nameRu', 'label' => 'Название (RUB, USD, EUR…)', 'type' => 'string', 'required' => true],
                ['key' => 'nameEn', 'label' => 'Название (en)', 'type' => 'string'],
                ['key' => 'symbol', 'label' => 'Символ (₽, $, €…)', 'type' => 'string', 'required' => true],
            ],
        ],
        'contractStatus' => [
            'label' => 'Статусы контрактов',
            'table' => 'contractStatus',
            'primaryLabel' => 'name',
            'orderBy' => 'name',
            'fields' => [
                ['key' => 'name', 'label' => 'Название', 'type' => 'string', 'required' => true],
            ],
        ],
        'criterion' => [
            'label' => 'Критерии конкурсов',
            'table' => 'criterion',
            'primaryLabel' => 'name',
            'orderBy' => 'name',
            'fields' => [
                ['key' => 'name', 'label' => 'Название', 'type' => 'string', 'required' => true],
            ],
        ],
        'status' => [
            'label' => 'Статусы партнёров',
            'table' => 'status',
            'primaryLabel' => 'title',
            'orderBy' => 'title',
            'fields' => [
                ['key' => 'title', 'label' => 'Название', 'type' => 'string', 'required' => true],
            ],
        ],
    ];

    /**
     * List of catalogs (for sidebar / tabs).
     */
    public function catalogs(): JsonResponse
    {
        $out = [];
        foreach (self::CATALOGS as $key => $cfg) {
            $out[] = [
                'key' => $key,
                'label' => $cfg['label'],
                'fields' => $cfg['fields'],
            ];
        }
        return response()->json($out);
    }

    public function index(string $catalog): JsonResponse
    {
        $cfg = $this->catalog($catalog);

        $cols = array_merge(['id'], array_column($cfg['fields'], 'key'));
        $rows = DB::table($cfg['table'])->orderBy($cfg['orderBy'])->get($cols);

        // Resolve FK labels for convenience
        foreach ($cfg['fields'] as $f) {
            if (($f['type'] ?? null) === 'fkey' && ! empty($f['refTable'])) {
                $ids = $rows->pluck($f['key'])->filter()->unique();
                if ($ids->isNotEmpty()) {
                    $map = DB::table($f['refTable'])->whereIn('id', $ids)->pluck($f['refLabel'], 'id');
                    $rows = $rows->map(function ($r) use ($f, $map) {
                        $r->{$f['key'] . 'Label'} = $map[$r->{$f['key']}] ?? null;
                        return $r;
                    });
                }
            }
        }

        return response()->json([
            'items' => $rows,
            'total' => $rows->count(),
        ]);
    }

    public function store(Request $request, string $catalog): JsonResponse
    {
        $cfg = $this->catalog($catalog);
        $payload = $this->validated($request, $cfg);

        $id = DB::table($cfg['table'])->insertGetId($payload);

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, string $catalog, int $id): JsonResponse
    {
        $cfg = $this->catalog($catalog);
        $exists = DB::table($cfg['table'])->where('id', $id)->exists();
        if (! $exists) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $payload = $this->validated($request, $cfg);
        DB::table($cfg['table'])->where('id', $id)->update($payload);

        return response()->json(['id' => $id]);
    }

    public function destroy(string $catalog, int $id): JsonResponse
    {
        $cfg = $this->catalog($catalog);
        $deleted = DB::table($cfg['table'])->where('id', $id)->delete();
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['ok' => true]);
    }

    private function catalog(string $key): array
    {
        if (! array_key_exists($key, self::CATALOGS)) {
            abort(404, "Unknown catalog: {$key}");
        }
        return self::CATALOGS[$key];
    }

    /**
     * Build validation rules from the catalog field map and return validated data,
     * skipping unchanged nullable fields.
     */
    private function validated(Request $request, array $cfg): array
    {
        $rules = [];
        foreach ($cfg['fields'] as $f) {
            $r = [];
            $r[] = ! empty($f['required']) ? 'required' : 'nullable';
            switch ($f['type']) {
                case 'bool':
                    $r[] = 'boolean';
                    break;
                case 'fkey':
                    $r[] = 'integer';
                    if (! empty($f['refTable'])) {
                        $r[] = "exists:{$f['refTable']},id";
                    }
                    break;
                case 'text':
                    $r[] = 'string';
                    break;
                case 'string':
                default:
                    $r[] = 'string';
                    $r[] = 'max:255';
                    break;
            }
            $rules[$f['key']] = $r;
        }

        $data = Validator::make($request->all(), $rules)->validate();

        // Keep only known columns
        $allowed = array_column($cfg['fields'], 'key');
        return array_intersect_key($data, array_flip($allowed));
    }
}
