<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Редактор матрицы квалификаций (таблица status_levels): %, НГП-порог, ОП,
 * порог отрыва по уровням. Только admin. ВЛИЯЕТ НА РАСЧЁТЫ.
 */
class AdminQualificationMatrixController extends Controller
{
    public function index(): JsonResponse
    {
        $levels = DB::table('status_levels')->orderBy('level')->get([
            'id', 'level', 'title', 'percent', 'groupVolumeCumulative',
            'mandatoryGP', 'otrif', 'pool', 'personalVolume',
        ]);

        return response()->json(['levels' => $levels]);
    }

    /** PUT /admin/qualification-matrix — массовое обновление редактируемых полей. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'levels' => ['required', 'array'],
            'levels.*.id' => ['required', 'integer'],
            'levels.*.title' => ['nullable', 'string', 'max:120'],
            'levels.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'levels.*.groupVolumeCumulative' => ['nullable', 'numeric', 'min:0'],
            'levels.*.mandatoryGP' => ['nullable', 'numeric', 'min:0'],
            'levels.*.otrif' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['levels'] as $row) {
                DB::table('status_levels')->where('id', $row['id'])->update([
                    'title' => $row['title'] ?? null,
                    'percent' => $row['percent'] ?? 0,
                    'groupVolumeCumulative' => $row['groupVolumeCumulative'] ?? 0,
                    'mandatoryGP' => $row['mandatoryGP'] ?? 0,
                    'otrif' => $row['otrif'] ?? 0,
                ]);
            }
        });

        // Сбросить кэш матрицы калькулятора (если используется).
        Cache::forget('calculator:product-matrix:v4');

        // Изменение порогов НГП должно сразу разойтись по уровням партнёров —
        // иначе правка «не обновляется везде». Промоут-онли переоценка (после
        // коммита новых порогов): снижение порога → повышает подходящих;
        // повышение порога → грандфазер (демоушенов нет). Уровни/снимки лога
        // обновляются, отчёты/дашборды читают их живьём.
        $promoted = \App\Services\QualificationReeval::run(true)['promoted'];

        return response()->json([
            'message' => 'Матрица квалификаций сохранена'
                . ($promoted > 0 ? " · повышено партнёров по НГП: {$promoted}" : ''),
            'promoted' => $promoted,
        ]);
    }
}
