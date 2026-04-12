<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunicationCategory;
use App\Models\Consultant;
use App\Models\PlatformCommunication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunicationController extends Controller
{
    /**
     * Список сообщений партнёра (хронологический, последние сверху).
     */
    public function index(Request $request): JsonResponse
    {
        $consultant = $this->getConsultant($request);
        if (! $consultant) {
            return response()->json(['data' => [], 'total' => 0, 'unreadCount' => 0]);
        }

        $query = PlatformCommunication::forConsultant($consultant->id);

        // Фильтр по категории
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $total = $query->count();

        $messages = $query
            ->orderByDesc('date')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get()
            ->map(fn ($m) => $this->formatMessage($m));

        $unreadCount = PlatformCommunication::forConsultant($consultant->id)
            ->unread()
            ->count();

        return response()->json([
            'data' => $messages,
            'total' => $total,
            'unreadCount' => $unreadCount,
        ]);
    }

    /**
     * Счётчик непрочитанных сообщений (для бейджа в меню).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $consultant = $this->getConsultant($request);
        if (! $consultant) {
            return response()->json(['count' => 0]);
        }

        $count = PlatformCommunication::forConsultant($consultant->id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Отправка сообщения партнёром.
     */
    public function send(Request $request): JsonResponse
    {
        $consultant = $this->getConsultant($request);
        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $request->validate([
            'category' => 'required|integer|exists:communicationCategory,id',
            'message' => 'required|string|max:5000',
        ]);

        $msg = PlatformCommunication::create([
            'consultant' => $consultant->id,
            'category' => $request->input('category'),
            'message' => $request->input('message'),
            'date' => now(),
            'author' => $consultant->person,
            'direction' => 'p2ds',
            'read' => false,
            'WebUser' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Сообщение отправлено',
            'data' => $this->formatMessage($msg),
        ]);
    }

    /**
     * Отметить сообщение прочитанным.
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $consultant = $this->getConsultant($request);
        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $msg = PlatformCommunication::where('id', $id)
            ->forConsultant($consultant->id)
            ->incoming()
            ->first();

        if (! $msg) {
            return response()->json(['message' => 'Сообщение не найдено'], 404);
        }

        $msg->read = true;
        $msg->save();

        return response()->json(['message' => 'Отмечено прочитанным']);
    }

    /**
     * Категории сообщений (для выбора при отправке).
     */
    public function categories(): JsonResponse
    {
        $categories = CommunicationCategory::orderBy('id')->get()
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title]);

        return response()->json($categories);
    }

    private function getConsultant(Request $request): ?Consultant
    {
        return Consultant::where('webUser', $request->user()->id)->first();
    }

    private function formatMessage(PlatformCommunication $m): array
    {
        $categoryTitle = $m->category
            ? CommunicationCategory::where('id', $m->category)->value('title')
            : null;

        return [
            'id' => $m->id,
            'category' => $m->category,
            'categoryTitle' => $categoryTitle,
            'message' => $m->message,
            'date' => $m->date?->toIso8601String(),
            'direction' => $m->direction,
            'read' => $m->read,
            'isIncoming' => $m->direction === 'ds2p',
        ];
    }
}
