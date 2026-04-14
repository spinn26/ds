<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureTable();
        $user = $request->user();

        $notifications = DB::table('notifications')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'icon' => $n->icon,
                'color' => $n->color,
                'link' => $n->link,
                'read' => (bool) $n->read,
                'createdAt' => $n->created_at,
            ]);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->ensureTable();
        $count = DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->where('read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->update(['read' => true]);

        return response()->json(['message' => 'OK']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->where('read', false)
            ->update(['read' => true]);

        return response()->json(['message' => 'Все прочитаны']);
    }

    /**
     * Создать уведомление для пользователя.
     * Вызывается из других сервисов/контроллеров.
     */
    public static function create(int $userId, string $type, string $title, ?string $message = null, ?string $link = null): void
    {
        $icons = [
            'ticket' => 'mdi-ticket',
            'status' => 'mdi-account-clock',
            'requisites' => 'mdi-credit-card',
            'payment' => 'mdi-cash',
            'import' => 'mdi-upload',
            'system' => 'mdi-bell',
        ];
        $colors = [
            'ticket' => 'info',
            'status' => 'warning',
            'requisites' => 'success',
            'payment' => 'success',
            'import' => 'primary',
            'system' => 'grey',
        ];

        try {
            DB::table('notifications')->insert([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'icon' => $icons[$type] ?? 'mdi-bell',
                'color' => $colors[$type] ?? 'grey',
                'link' => $link,
                'read' => false,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {}
    }

    private function ensureTable(): void
    {
        if (! Schema::hasTable('notifications')) {
            DB::statement('CREATE TABLE notifications (
                id BIGSERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                type VARCHAR DEFAULT \'system\',
                title TEXT NOT NULL,
                message TEXT,
                icon VARCHAR DEFAULT \'mdi-bell\',
                color VARCHAR DEFAULT \'grey\',
                link VARCHAR,
                read BOOLEAN DEFAULT false,
                created_at TIMESTAMP
            )');
        }
    }
}
