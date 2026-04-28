<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Инструкции (per spec ✅Инструкции.md).
 *
 * Партнёрам показываются только published + audience IN ('partner','both').
 * Staff видит всё.
 *
 * Endpoints:
 *   GET  /instructions              — каталог для партнёра
 *   GET  /instructions/{slug}       — конкретная инструкция
 *   GET  /admin/instructions        — каталог для staff (с draft)
 *   POST /admin/instructions        — создать
 *   PUT  /admin/instructions/{id}   — обновить
 *   DELETE /admin/instructions/{id} — удалить
 */
class InstructionController extends Controller
{
    /** Партнёрский каталог: только published + partner|both. */
    public function partnerList(Request $request): JsonResponse
    {
        $q = DB::table('instructions')
            ->where('publish_status', 'published')
            ->whereIn('audience', ['partner', 'both']);

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $q->where(function ($w) use ($term) {
                $w->where('title', 'ilike', $term)
                  ->orWhere('body_md', 'ilike', $term);
            });
        }
        if ($request->filled('category')) {
            $q->where('category', $request->category);
        }

        $rows = $q->orderBy('category')->orderBy('order_index')->orderBy('title')->get();
        $byCategory = $rows->groupBy('category')->map(fn ($g) => $g->values());

        return response()->json([
            'categories' => $byCategory,
            'total' => $rows->count(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $row = DB::table('instructions')
            ->where('slug', $slug)
            ->where('publish_status', 'published')
            ->first();
        if (! $row) return response()->json(['message' => 'Инструкция не найдена'], 404);

        return response()->json([
            'instruction' => $row,
            'toc' => $this->extractToc($row->body_md ?? ''),
        ]);
    }

    /** Auto-TOC из markdown заголовков H2/H3. */
    private function extractToc(string $md): array
    {
        $toc = [];
        foreach (preg_split("/\r?\n/", $md) as $line) {
            if (preg_match('/^(##+)\s+(.+?)\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                if ($level >= 2 && $level <= 3) {
                    $title = $m[2];
                    $toc[] = [
                        'level' => $level,
                        'title' => $title,
                        'anchor' => Str::slug($title),
                    ];
                }
            }
        }
        return $toc;
    }

    // ===== Admin endpoints =====

    public function adminList(Request $request): JsonResponse
    {
        $q = DB::table('instructions');

        if ($request->filled('search')) {
            $q->where('title', 'ilike', '%' . $request->search . '%');
        }
        if ($request->filled('audience')) {
            $q->where('audience', $request->audience);
        }
        if ($request->filled('publish_status')) {
            $q->where('publish_status', $request->publish_status);
        }

        $rows = $q->orderBy('category')->orderBy('order_index')->get();
        return response()->json(['data' => $rows]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:300',
            'category' => 'required|string|max:100',
            'audience' => 'required|in:partner,staff,both',
            'body_md' => 'nullable|string',
            'video_url' => 'nullable|string|max:500',
            'publish_status' => 'required|in:draft,published',
            'order_index' => 'nullable|integer',
            'slug' => 'nullable|string|max:200',
        ]);

        $slug = $data['slug'] ?? Str::slug($data['title']);
        // Уникальность
        $base = $slug;
        $i = 1;
        while (DB::table('instructions')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }

        $id = DB::table('instructions')->insertGetId([
            'slug' => $slug,
            'title' => $data['title'],
            'category' => $data['category'],
            'audience' => $data['audience'],
            'body_md' => $data['body_md'] ?? null,
            'video_url' => $data['video_url'] ?? null,
            'publish_status' => $data['publish_status'],
            'order_index' => $data['order_index'] ?? 0,
            'author_id' => $request->user()?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'slug' => $slug, 'message' => 'Создано'], 201);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $row = DB::table('instructions')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Не найдена'], 404);

        $data = $request->validate([
            'title' => 'sometimes|string|max:300',
            'category' => 'sometimes|string|max:100',
            'audience' => 'sometimes|in:partner,staff,both',
            'body_md' => 'nullable|string',
            'video_url' => 'nullable|string|max:500',
            'publish_status' => 'sometimes|in:draft,published',
            'order_index' => 'nullable|integer',
        ]);
        $data['updated_at'] = now();

        DB::table('instructions')->where('id', $id)->update($data);
        return response()->json(['message' => 'Обновлено']);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        DB::table('instructions')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
