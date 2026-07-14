<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\SocketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * Persist a notification for a user AND push it over the socket channel,
     * so offline users see it on next login and online users get it in real time.
     *
     * Call this from services/controllers on any user-facing event.
     */
    public static function create(int $userId, string $type, string $title, ?string $message = null, ?string $link = null): void
    {
        $icons = [
            'ticket' => 'mdi-ticket',
            'status' => 'mdi-account-clock',
            'requisites' => 'mdi-credit-card',
            'payment' => 'mdi-cash',
            'import' => 'mdi-upload',
            'mail' => 'mdi-email-fast',
            'chat' => 'mdi-message-text',
            'system' => 'mdi-bell',
        ];
        $colors = [
            'ticket' => 'info',
            'status' => 'warning',
            'requisites' => 'success',
            'payment' => 'success',
            'import' => 'primary',
            'mail' => 'info',
            'chat' => 'info',
            'system' => 'grey',
        ];

        $icon = $icons[$type] ?? 'mdi-bell';
        $color = $colors[$type] ?? 'grey';

        try {
            DB::table('notifications')->insert([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'icon' => $icon,
                'color' => $color,
                'link' => $link,
                'read' => false,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('notification insert failed', ['error' => $e->getMessage(), 'user' => $userId]);
        }

        try {
            app(SocketService::class)->notifyUser($userId, 'notification', [
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'icon' => $icon,
                'color' => $color,
                'link' => $link,
            ]);
        } catch (\Throwable) {
            // Socket is best-effort — the DB row is the source of truth.
        }

        // Mirror the personal notification to Telegram. No-op unless the user
        // linked their account (telegram_chat_id) and the bot token is set.
        try {
            $tg = '🔔 <b>' . htmlspecialchars($title) . '</b>';
            if ($message) {
                $tg .= "\n" . htmlspecialchars($message);
            }
            \App\Support\Telegram::send($userId, $tg);
        } catch (\Throwable) {
            // Telegram is best-effort — never let it break the notification flow.
        }
    }

    /**
     * Разослать уведомление всем пользователям с указанными ролями.
     * Роли в WebUser хранятся как CSV в колонке `role`, поэтому матчим через LIKE.
     */
    public static function notifyRoles(array $roles, string $type, string $title, ?string $message = null, ?string $link = null): int
    {
        if (! $roles) return 0;

        $query = DB::table('WebUser')->whereNull('dateDeleted');
        $query->where(function ($q) use ($roles) {
            foreach ($roles as $r) {
                $q->orWhere('role', 'ilike', '%' . $r . '%');
            }
        });
        $userIds = $query->pluck('id')->all();

        foreach ($userIds as $uid) {
            self::create((int) $uid, $type, $title, $message, $link);
        }
        return count($userIds);
    }

    /**
     * POST /admin/notifications/broadcast — ручная рассылка уведомления
     * (всем / по ролям). Bulk-insert чанками; socket не дёргаем поштучно
     * (получат при следующем опросе/обновлении) — иначе массовая отправка
     * подвисает на сотнях socket-запросов.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'link' => ['nullable', 'string', 'max:500'],
            'target' => ['required', 'in:all,roles'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'max:64'],
        ]);

        $q = DB::table('WebUser')->whereNull('dateDeleted');
        if ($data['target'] === 'roles') {
            $roles = $data['roles'] ?? [];
            if (! $roles) {
                return response()->json(['message' => 'Не выбраны роли'], 422);
            }
            $q->where(function ($x) use ($roles) {
                foreach ($roles as $r) $x->orWhere('role', 'ilike', '%' . $r . '%');
            });
        }

        $ids = $q->pluck('id');
        $now = now();
        $count = 0;
        foreach ($ids->chunk(500) as $chunk) {
            $rows = $chunk->map(fn ($id) => [
                'user_id' => $id, 'type' => 'system',
                'title' => $data['title'], 'message' => $data['message'] ?? null,
                'icon' => 'mdi-bell', 'color' => 'grey',
                'link' => $data['link'] ?? null, 'read' => false, 'created_at' => $now,
            ])->all();
            DB::table('notifications')->insert($rows);
            $count += count($rows);
        }

        return response()->json(['message' => "Отправлено: {$count}", 'count' => $count]);
    }

    /** Shortcut: разослать всем staff-ролям. */
    public static function notifyStaff(string $type, string $title, ?string $message = null, ?string $link = null): int
    {
        return self::notifyRoles(
            ['admin', 'backoffice', 'finance', 'support', 'head', 'calculations', 'corrections', 'invest'],
            $type, $title, $message, $link
        );
    }

    private function ensureTable(): void
    {
        // Kept for legacy bootstrap — the 2026_04_18_000001 migration is the source of truth.
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
