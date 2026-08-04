<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminKnowledgeBaseController extends Controller
{
    public function __construct()
    {
        if (! Schema::connection('pgsql_v2')->hasTable('education_kb_sections')
            || ! Schema::connection('pgsql_v2')->hasTable('education_kb_articles')) {
            abort(503, 'Таблицы базы знаний ds_v2 не созданы');
        }
    }

    private function db(): ConnectionInterface
    {
        return DB::connection('pgsql_v2');
    }

    public function tree(): JsonResponse
    {
        $rows = $this->db()->table('education_kb_sections')
            ->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->get();
        $counts = $this->db()->table('education_kb_articles')
            ->whereNull('deleted_at')->select('section_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('section_id')->pluck('cnt', 'section_id');

        $byParent = [];
        foreach ($rows as $row) {
            $byParent[$row->parent_id ?? 0][] = [
                'id' => $row->id,
                'title' => $row->title,
                'parent_id' => $row->parent_id ? (int) $row->parent_id : null,
                'slug' => $row->slug,
                'icon' => $row->icon,
                'description' => $row->description,
                'coverUrl' => $row->cover_url,
                'sortOrder' => (int) $row->sort_order,
                'articleCount' => (int) ($counts[$row->id] ?? 0),
                'children' => [],
            ];
        }
        $build = function (int $parentId) use (&$build, &$byParent): array {
            $nodes = $byParent[$parentId] ?? [];
            foreach ($nodes as &$node) {
                $node['children'] = $build((int) $node['id']);
            }
            return $nodes;
        };

        return response()->json(['tree' => $build(0)]);
    }

    public function storeSection(Request $request): JsonResponse
    {
        $data = $this->sectionValidate($request);
        $id = $this->db()->table('education_kb_sections')->insertGetId([
            'title' => $data['title'],
            'slug' => $this->uniqueSlug('education_kb_sections', $data['title']),
            'parent_id' => $data['parent_id'] ?? null,
            'icon' => $data['icon'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Раздел создан'], 201);
    }

    public function updateSection(Request $request, int $id): JsonResponse
    {
        $data = $this->sectionValidate($request);
        if (! empty($data['parent_id'])
            && ((int) $data['parent_id'] === $id || $this->isDescendant((int) $data['parent_id'], $id))) {
            return response()->json(['message' => 'Нельзя поместить раздел в собственное поддерево'], 422);
        }
        $this->db()->table('education_kb_sections')->where('id', $id)->whereNull('deleted_at')->update([
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
        $this->db()->transaction(function () use ($id): void {
            $ids = [...$this->collectDescendants($id), $id];
            $now = now();
            $this->db()->table('education_kb_articles')->whereIn('section_id', $ids)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
            $this->db()->table('education_kb_sections')->whereIn('id', $ids)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
        });

        return response()->json(['message' => 'Раздел и его содержимое удалены']);
    }

    public function moveSection(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => 'nullable|integer|exists:pgsql_v2.education_kb_sections,id',
            'sort_order' => 'required|integer|min:0',
        ]);
        if (! empty($data['parent_id'])
            && ((int) $data['parent_id'] === $id || $this->isDescendant((int) $data['parent_id'], $id))) {
            return response()->json(['message' => 'Нельзя переместить раздел в собственное поддерево'], 422);
        }
        $this->db()->table('education_kb_sections')->where('id', $id)->whereNull('deleted_at')->update([
            'parent_id' => $data['parent_id'] ?? null,
            'sort_order' => $data['sort_order'],
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Перемещено']);
    }

    public function articles(int $sectionId): JsonResponse
    {
        $rows = $this->db()->table('education_kb_articles')->where('section_id', $sectionId)
            ->whereNull('deleted_at')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['articles' => $rows->map(fn ($article) => $this->articleShape($article))]);
    }

    public function showArticle(int $id): JsonResponse
    {
        $article = $this->db()->table('education_kb_articles')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $article) {
            return response()->json(['message' => 'Не найдено'], 404);
        }

        return response()->json($this->articleShape($article) + ['sectionId' => $article->section_id]);
    }

    public function storeArticle(Request $request): JsonResponse
    {
        $data = $this->articleValidate($request);
        $published = (bool) ($data['published'] ?? true);
        $now = now();
        $id = $this->db()->table('education_kb_articles')->insertGetId([
            'section_id' => $data['section_id'],
            'title' => $data['title'],
            'slug' => $this->uniqueSlug('education_kb_articles', $data['title']),
            'summary' => $data['description'] ?? null,
            'body' => json_encode($data['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'tags' => isset($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? $now : null,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(['id' => $id, 'message' => 'Материал создан'], 201);
    }

    public function updateArticle(Request $request, int $id): JsonResponse
    {
        $data = $this->articleValidate($request);
        $article = $this->db()->table('education_kb_articles')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $article) {
            return response()->json(['message' => 'Не найдено'], 404);
        }
        $published = (bool) ($data['published'] ?? true);
        $this->db()->table('education_kb_articles')->where('id', $id)->update([
            'section_id' => $data['section_id'],
            'title' => $data['title'],
            'summary' => $data['description'] ?? null,
            'body' => json_encode($data['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'tags' => isset($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? ($article->published_at ?? now()) : null,
            'sort_order' => $data['sort_order'] ?? 0,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Материал обновлён']);
    }

    public function destroyArticle(int $id): JsonResponse
    {
        $this->db()->table('education_kb_articles')->where('id', $id)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Удалён']);
    }

    private function sectionValidate(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:200',
            'parent_id' => 'nullable|integer|exists:pgsql_v2.education_kb_sections,id',
            'icon' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0',
        ]);
    }

    private function articleValidate(Request $request): array
    {
        return $request->validate([
            'section_id' => 'required|integer|exists:pgsql_v2.education_kb_sections,id',
            'title' => 'required|string|max:300',
            'description' => 'nullable|string|max:2000',
            'body' => 'nullable|array',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:60',
            'published' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);
    }

    private function articleShape(object $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'description' => $article->summary,
            'body' => $this->jsonArray($article->body),
            'tags' => $this->jsonArray($article->tags),
            'published' => $article->status === 'published',
            'sortOrder' => (int) $article->sort_order,
        ];
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = $value === null ? null : json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function uniqueSlug(string $table, string $title): string
    {
        $base = Str::slug($title) ?: 'item';
        do {
            $slug = $base.'-'.Str::lower(Str::random(8));
        } while ($this->db()->table($table)->where('slug', $slug)->exists());

        return $slug;
    }

    private function isDescendant(int $candidateId, int $rootId): bool
    {
        $visited = [];
        $stack = [$rootId];
        while ($stack !== []) {
            $current = array_pop($stack);
            $children = $this->db()->table('education_kb_sections')->where('parent_id', $current)
                ->whereNull('deleted_at')->pluck('id')->all();
            foreach ($children as $child) {
                $child = (int) $child;
                if ($child === $candidateId) {
                    return true;
                }
                if (! in_array($child, $visited, true)) {
                    $visited[] = $child;
                    $stack[] = $child;
                }
            }
        }

        return false;
    }

    private function collectDescendants(int $rootId): array
    {
        $all = [];
        $stack = [$rootId];
        while ($stack !== []) {
            $current = array_pop($stack);
            $children = $this->db()->table('education_kb_sections')->where('parent_id', $current)
                ->whereNull('deleted_at')->pluck('id')->all();
            foreach ($children as $child) {
                $child = (int) $child;
                $all[] = $child;
                $stack[] = $child;
            }
        }

        return $all;
    }
}
