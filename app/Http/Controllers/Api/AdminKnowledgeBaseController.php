<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Админ-CRUD для базы знаний (Раздел → Подраздел → Материал).
 *
 * Используется новым конструктором /manage/kb (роль education).
 * Партнёрский просмотр идёт через EducationController::kbTree / kbSection /
 * kbArticle — для оператора нужны write-методы, отдельный namespace.
 */
class AdminKnowledgeBaseController extends Controller
{
    public function __construct()
    {
        if (! Schema::hasTable('education_kb_sections')
            || ! Schema::hasTable('education_kb_articles')) {
            abort(503, 'Таблицы базы знаний не созданы — нужна миграция 2026_05_25_000010');
        }
    }

    /** Дерево разделов (для левой панели конструктора). */
    public function tree(): JsonResponse
    {
        $rows = DB::table('education_kb_sections')
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $counts = DB::table('education_kb_articles')
            ->whereNull('deleted_at')
            ->select('section_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('section_id')
            ->pluck('cnt', 'section_id');

        $byParent = [];
        foreach ($rows as $r) {
            $byParent[$r->parent_id ?? 0][] = [
                'id' => $r->id, 'title' => $r->title,
                'parent_id' => $r->parent_id ? (int) $r->parent_id : null,
                'slug' => $r->slug, 'icon' => $r->icon,
                'description' => $r->description, 'coverUrl' => $r->cover_url,
                'sortOrder' => $r->sort_order,
                'articleCount' => (int) ($counts[$r->id] ?? 0),
                'children' => [],
            ];
        }
        $build = function (int $p) use (&$build, &$byParent) {
            $out = $byParent[$p] ?? [];
            foreach ($out as &$n) $n['children'] = $build($n['id']);
            return $out;
        };
        return response()->json(['tree' => $build(0)]);
    }

    /** CRUD разделов */
    public function storeSection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'parent_id' => 'nullable|integer|exists:education_kb_sections,id',
            'icon' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer',
        ]);
        $id = DB::table('education_kb_sections')->insertGetId([
            'title' => $data['title'],
            'parent_id' => $data['parent_id'] ?? null,
            'icon' => $data['icon'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Раздел создан'], 201);
    }

    public function updateSection(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'parent_id' => 'nullable|integer|exists:education_kb_sections,id',
            'icon' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer',
        ]);
        if (! empty($data['parent_id']) && $this->isDescendant((int) $data['parent_id'], $id)) {
            return response()->json(['message' => 'Нельзя поместить раздел в собственное поддерево'], 422);
        }
        DB::table('education_kb_sections')->where('id', $id)->update([
            'title' => $data['title'],
            'parent_id' => $data['parent_id'] ?? null,
            'icon' => $data['icon'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Раздел обновлён']);
    }

    public function destroySection(int $id): JsonResponse
    {
        DB::transaction(function () use ($id) {
            // Каскадно soft-delete все подразделы и материалы.
            $ids = $this->collectDescendants($id);
            $ids[] = $id;
            DB::table('education_kb_articles')
                ->whereIn('section_id', $ids)
                ->update(['deleted_at' => now()]);
            DB::table('education_kb_sections')
                ->whereIn('id', $ids)
                ->update(['deleted_at' => now()]);
        });
        return response()->json(['message' => 'Раздел и его содержимое удалены']);
    }

    public function moveSection(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => 'nullable|integer|exists:education_kb_sections,id',
            'sort_order' => 'required|integer|min:0',
        ]);
        if (! empty($data['parent_id']) && $this->isDescendant((int) $data['parent_id'], $id)) {
            return response()->json(['message' => 'Нельзя переместить раздел в собственное поддерево'], 422);
        }
        DB::table('education_kb_sections')->where('id', $id)->update([
            'parent_id' => $data['parent_id'] ?? null,
            'sort_order' => $data['sort_order'],
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Перемещено']);
    }

    /** Список материалов раздела (для редактора). */
    public function articles(int $sectionId): JsonResponse
    {
        $rows = DB::table('education_kb_articles')
            ->where('section_id', $sectionId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        return response()->json(['articles' => $rows->map(fn ($a) => [
            'id' => $a->id, 'title' => $a->title, 'description' => $a->description,
            'body' => $a->body ? (is_string($a->body) ? json_decode($a->body, true) : $a->body) : [],
            'tags' => $a->tags ? (is_string($a->tags) ? json_decode($a->tags, true) : $a->tags) : [],
            'published' => (bool) $a->published,
            'sortOrder' => $a->sort_order,
        ])]);
    }

    /** Один материал. */
    public function showArticle(int $id): JsonResponse
    {
        $a = DB::table('education_kb_articles')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Не найдено'], 404);
        return response()->json([
            'id' => $a->id, 'sectionId' => $a->section_id,
            'title' => $a->title, 'description' => $a->description,
            'body' => $a->body ? (is_string($a->body) ? json_decode($a->body, true) : $a->body) : [],
            'tags' => $a->tags ? (is_string($a->tags) ? json_decode($a->tags, true) : $a->tags) : [],
            'published' => (bool) $a->published,
        ]);
    }

    public function storeArticle(Request $request): JsonResponse
    {
        $data = $this->articleValidate($request);
        $id = DB::table('education_kb_articles')->insertGetId([
            'section_id' => $data['section_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'body' => isset($data['body']) ? json_encode($data['body'], JSON_UNESCAPED_UNICODE) : null,
            'tags' => isset($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : null,
            'published' => $data['published'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Материал создан'], 201);
    }

    public function updateArticle(Request $request, int $id): JsonResponse
    {
        $data = $this->articleValidate($request);
        DB::table('education_kb_articles')->where('id', $id)->update([
            'section_id' => $data['section_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'body' => isset($data['body']) ? json_encode($data['body'], JSON_UNESCAPED_UNICODE) : null,
            'tags' => isset($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : null,
            'published' => $data['published'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Материал обновлён']);
    }

    public function destroyArticle(int $id): JsonResponse
    {
        DB::table('education_kb_articles')->where('id', $id)->update([
            'deleted_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Удалён']);
    }

    private function articleValidate(Request $request): array
    {
        return $request->validate([
            'section_id' => 'required|integer|exists:education_kb_sections,id',
            'title' => 'required|string|max:300',
            'description' => 'nullable|string|max:2000',
            'body' => 'nullable|array',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:60',
            'published' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);
    }

    private function isDescendant(int $candidateId, int $rootId): bool
    {
        $visited = [];
        $stack = [$rootId];
        while ($stack) {
            $cur = array_pop($stack);
            $kids = DB::table('education_kb_sections')
                ->where('parent_id', $cur)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();
            foreach ($kids as $kid) {
                if ($kid === $candidateId) return true;
                if (! in_array($kid, $visited, true)) {
                    $visited[] = $kid;
                    $stack[] = $kid;
                }
            }
        }
        return false;
    }

    private function collectDescendants(int $rootId): array
    {
        $all = [];
        $stack = [$rootId];
        while ($stack) {
            $cur = array_pop($stack);
            $kids = DB::table('education_kb_sections')
                ->where('parent_id', $cur)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();
            foreach ($kids as $k) { $all[] = $k; $stack[] = $k; }
        }
        return $all;
    }
}
