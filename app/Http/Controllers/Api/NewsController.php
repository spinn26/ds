<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NewsService;
use App\Services\PartnerPromoProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Новости кабинета: лента, страница новости, отметка прочтения.
 *
 * До 21.09.2026 новости приходили партнёру только внутри /workspace и
 * отдельной страницы у них не было — ссылку на новость нельзя было отправить.
 */
class NewsController extends Controller
{
    public function __construct(
        private readonly NewsService $news,
        private readonly PartnerPromoProgress $promoProgress,
    ) {}

    /** GET /v1/news?kind=promo|update */
    public function index(Request $request): JsonResponse
    {
        $feed = $this->news->feed(
            $request->user()?->id,
            $request->input('kind'),
            min(50, max(1, (int) $request->input('limit', 20))),
        );

        return response()->json([
            'data' => $feed['items'],
            'total' => $feed['total'],
            'unread' => $feed['unread'],
        ]);
    }

    /** GET /v1/news/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $article = $this->news->article($id, $user?->id, $this->promoProgress->current($user));

        if (! $article) {
            return response()->json(['message' => 'Новость не найдена'], 404);
        }

        return response()->json($article);
    }

    /** POST /v1/news/{id}/read */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $this->news->markRead($id, $user->id);
        }

        return response()->json(['unread' => $this->news->unreadCount($user?->id)]);
    }
}
