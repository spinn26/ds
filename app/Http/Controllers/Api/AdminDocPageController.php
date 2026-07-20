<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Редактируемые из админки markdown-документы (doc_pages).
 * Доступ — только admin (роут-группа role:admin). Первое обращение к
 * известному slug засевает контент из файла-шаблона в resources/markdown,
 * дальше правки живут в БД.
 */
class AdminDocPageController extends Controller
{
    /**
     * Известные документы: slug => [заголовок, файл-шаблон в resources/markdown].
     * Незнакомый slug создаётся пустым (можно наполнить через редактор).
     */
    private const KNOWN = [
        'partner-cabinet' => [
            'title' => 'Инструкция партнёра — кабинет',
            'seed' => 'partner-cabinet-guide.md',
        ],
    ];

    public function show(string $slug): JsonResponse
    {
        $page = DocPage::firstWhere('slug', $slug);

        if (! $page) {
            $known = self::KNOWN[$slug] ?? null;
            $page = DocPage::create([
                'slug' => $slug,
                'title' => $known['title'] ?? $slug,
                'content' => $known ? $this->seed($known['seed']) : '',
            ]);
        }

        return response()->json([
            'slug' => $page->slug,
            'title' => $page->title,
            'content' => $page->content ?? '',
            'updatedAt' => $page->updated_at,
        ]);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $page = DocPage::firstOrNew(['slug' => $slug]);
        $page->content = $data['content'];
        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $page->title = $data['title'];
        } elseif (! $page->exists) {
            $page->title = self::KNOWN[$slug]['title'] ?? $slug;
        }
        $page->updated_by = Auth::id();
        $page->save();

        return response()->json([
            'ok' => true,
            'updatedAt' => $page->updated_at,
        ]);
    }

    /** Прочитать файл-шаблон из resources/markdown (или пусто, если нет). */
    private function seed(string $file): string
    {
        $path = resource_path('markdown/' . $file);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
