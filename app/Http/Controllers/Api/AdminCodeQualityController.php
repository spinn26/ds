<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CodeFinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Реестр находок аудита кода (страница «Качество кода»).
 *
 * Смысл контроллера — снять с реестра цену релиза. Раньше данные лежали
 * в самом CodeQuality.vue, и чтобы отметить находку исправленной, нужен
 * был коммит и полный деплой; поэтому реестр и протух на два месяца.
 * Теперь это обычный CRUD, доступ — admin (роут-группа role:admin).
 */
class AdminCodeQualityController extends Controller
{
    /** Весь реестр разом: записей меньше сотни, пагинация избыточна. */
    public function index(): JsonResponse
    {
        $rows = CodeFinding::query()
            ->orderByRaw($this->severityOrderSql())
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (CodeFinding $f) => $f->toRegistryArray())->values(),
            'categories' => $rows->pluck('category')->unique()->sort()->values(),
            'counts' => [
                'open' => $rows->where('status', 'open')->count(),
                'fixed' => $rows->where('status', 'fixed')->count(),
                'openBySeverity' => $rows->where('status', 'open')
                    ->groupBy('severity')
                    ->map->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $finding = new CodeFinding();
        $this->fill($finding, $data);
        $finding->save();

        return response()->json($finding->toRegistryArray(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $finding = CodeFinding::findOrFail($id);
        $data = $this->validated($request, $finding->id);

        $this->fill($finding, $data);
        $finding->save();

        return response()->json($finding->toRegistryArray());
    }

    public function destroy(int $id): JsonResponse
    {
        CodeFinding::findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Быстрое переключение статуса — основной сценарий страницы.
     * Отдельным маршрутом, чтобы не гонять всю запись ради одного поля.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $finding = CodeFinding::findOrFail($id);

        $finding->status = $finding->status === 'fixed' ? 'open' : 'fixed';
        $finding->closed_at = $finding->status === 'fixed' ? now() : null;
        $finding->closed_by = $finding->status === 'fixed' ? Auth::id() : null;
        $finding->save();

        return response()->json($finding->toRegistryArray());
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('code_findings', 'code')->ignore($ignoreId),
            ],
            'severity' => ['required', Rule::in(CodeFinding::SEVERITY_ORDER)],
            'category' => ['required', 'string', 'max:160'],
            'title' => ['required', 'string', 'max:500'],
            'file' => ['nullable', 'string', 'max:500'],
            'problem' => ['required', 'string'],
            'recommendation' => ['required', 'string'],
            'status' => ['required', Rule::in(CodeFinding::STATUSES)],
            'sortOrder' => ['nullable', 'integer'],
        ]);
    }

    private function fill(CodeFinding $finding, array $data): void
    {
        $wasFixed = $finding->status === 'fixed';

        $finding->code = $data['code'];
        $finding->severity = $data['severity'];
        $finding->category = $data['category'];
        $finding->title = $data['title'];
        $finding->file = $data['file'] ?? null;
        $finding->problem = $data['problem'];
        $finding->recommendation = $data['recommendation'];
        $finding->status = $data['status'];
        $finding->sort_order = (int) ($data['sortOrder'] ?? $finding->sort_order ?? 0);

        // Дату закрытия ведём сами: проставляем при переходе в fixed и
        // снимаем при возврате в open, чтобы у переоткрытой находки не
        // осталась дата от прошлого закрытия.
        if ($finding->status === 'fixed' && ! $wasFixed) {
            $finding->closed_at = now();
            $finding->closed_by = Auth::id();
        } elseif ($finding->status === 'open') {
            $finding->closed_at = null;
            $finding->closed_by = null;
        }
    }

    /**
     * Сортировка по важности. Postgres не знает порядка наших строковых
     * severity, поэтому раскладываем их в CASE. Значения — константы
     * класса, пользовательский ввод сюда не попадает.
     */
    private function severityOrderSql(): string
    {
        $cases = [];
        foreach (CodeFinding::SEVERITY_ORDER as $i => $sev) {
            $cases[] = "WHEN '{$sev}' THEN {$i}";
        }

        return 'CASE severity ' . implode(' ', $cases) . ' ELSE 99 END';
    }
}
