<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoadmapController extends Controller
{
    /**
     * Публичный список (без auth) — только published=true.
     * Сортировка: сначала ручной sort_order, потом по released_at
     * (shipped в самом верху), потом по created_at desc.
     */
    public function publicIndex(): JsonResponse
    {
        $rows = DB::table('roadmap_entries')
            ->where('published', true)
            // Сначала группируем по статусу (in_progress → planned → shipped),
            // потом по sort_order. Для shipped дополнительно — по released_at
            // DESC, чтобы самые свежие релизы были сверху раздела «Выпущено».
            ->orderByRaw('CASE status WHEN \'in_progress\' THEN 0 WHEN \'planned\' THEN 1 WHEN \'shipped\' THEN 2 ELSE 3 END')
            ->orderByDesc('released_at')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get([
                'id', 'title', 'description', 'status',
                'category', 'icon', 'released_at', 'created_at',
            ]);

        return response()->json([
            'items' => $rows,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** GET /api/v1/admin/roadmap — список для админки (включая скрытые). */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $items = DB::table('roadmap_entries')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['items' => $items, 'total' => $items->count()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $this->validateData($request);

        $now = now();
        $id = DB::table('roadmap_entries')->insertGetId(array_merge($data, [
            'created_by' => $request->user()?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        Audit::log('roadmap_create', 'roadmap_entry', $id, ['title' => $data['title']]);
        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $this->validateData($request, partial: true);
        $data['updated_at'] = now();

        DB::table('roadmap_entries')->where('id', $id)->update($data);
        Audit::log('roadmap_update', 'roadmap_entry', $id, $data);
        return response()->json(['message' => 'Обновлено']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);
        DB::table('roadmap_entries')->where('id', $id)->delete();
        Audit::log('roadmap_delete', 'roadmap_entry', $id);
        return response()->json(['message' => 'Удалено']);
    }

    /** Общая валидация — partial=true для PUT (sometimes). */
    private function validateData(Request $request, bool $partial = false): array
    {
        $rules = [
            'title'       => ($partial ? 'sometimes|required' : 'required') . '|string|max:200',
            'description' => 'sometimes|nullable|string|max:10000',
            'status'      => 'sometimes|in:planned,in_progress,shipped',
            'category'    => 'sometimes|nullable|string|max:60',
            'icon'        => 'sometimes|nullable|string|max:60',
            'released_at' => 'sometimes|nullable|date',
            'sort_order'  => 'sometimes|integer',
            'published'   => 'sometimes|boolean',
        ];
        $data = $request->validate($rules);

        // Дефолты при создании.
        if (! $partial) {
            $data['status']     = $data['status']     ?? 'planned';
            $data['published']  = $data['published']  ?? true;
            $data['sort_order'] = $data['sort_order'] ?? 0;
        }
        // Auto released_at — если shipped и поле не передано.
        if (($data['status'] ?? null) === 'shipped' && empty($data['released_at'])) {
            $current = $partial
                ? DB::table('roadmap_entries')->where('id', $request->route('id'))->value('released_at')
                : null;
            if (! $current) $data['released_at'] = now();
        }
        return $data;
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user() || ! $request->user()->hasAnyRole(['admin'])) {
            abort(403, 'Только администратор может управлять роадмапом.');
        }
    }
}
