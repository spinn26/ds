<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use App\Models\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Публичные чтения: контент-страница по slug + активные фиче-флаги. */
class ContentPageController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        if (! Schema::hasTable('content_pages')) {
            return response()->json(['message' => 'Страница не найдена'], 404);
        }
        $page = ContentPage::query()->where('slug', $slug)->where('active', true)->first();
        if (! $page) {
            return response()->json(['message' => 'Страница не найдена'], 404);
        }

        return response()->json(['page' => ['slug' => $page->slug, 'title' => $page->title, 'body' => $page->body]]);
    }

    /** Включённые для текущего пользователя фиче-флаги (массив ключей). */
    public function features(Request $request): JsonResponse
    {
        if (! Schema::hasTable('feature_flags')) {
            return response()->json(['features' => []]);
        }
        $user = $request->user();
        $keys = collect(FeatureFlag::map())->keys()
            ->filter(fn ($k) => FeatureFlag::enabled($k, $user))
            ->values();

        return response()->json(['features' => $keys]);
    }
}
