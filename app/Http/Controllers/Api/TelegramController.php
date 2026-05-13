<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramController extends Controller
{
    /** Текущий статус привязки + конфиг для UI. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $row = DB::table('WebUser')->where('id', $user->id)
            ->first(['telegram_chat_id', 'telegram_user_id', 'telegram_username', 'telegram_linked_at']);
        return response()->json([
            'enabled' => Telegram::enabled(),
            'bot_username' => config('services.telegram.bot_username'),
            'linked' => ! empty($row?->telegram_chat_id),
            'chat_id' => $row?->telegram_chat_id,
            'user_id' => $row?->telegram_user_id,
            'username' => $row?->telegram_username,
            'linked_at' => $row?->telegram_linked_at,
        ]);
    }

    /**
     * Сгенерировать одноразовый токен и вернуть deeplink t.me/<bot>?start=<token>.
     * Пользователь жмёт ссылку → попадает в Telegram → жмёт Start →
     * бот ловит /start <token> через webhook и привязывает chat_id.
     */
    public function startLink(Request $request): JsonResponse
    {
        if (! Telegram::enabled()) {
            return response()->json(['message' => 'Telegram-бот не настроен (TELEGRAM_BOT_TOKEN отсутствует)'], 422);
        }
        $bot = config('services.telegram.bot_username');
        if (! $bot) {
            return response()->json(['message' => 'TELEGRAM_BOT_USERNAME не задан'], 422);
        }

        $token = Str::random(48);
        DB::table('telegram_link_tokens')->insert([
            'token' => $token,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Audit::log('telegram_link_started', 'WebUser', $request->user()->id);

        return response()->json([
            'token' => $token,
            'deeplink' => "https://t.me/{$bot}?start={$token}",
            'expires_in' => 15 * 60,
        ]);
    }

    /** Опрос «привязалось ли»: фронт периодически дёргает после открытия deeplink. */
    public function checkLink(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        if (! $token) return response()->json(['linked' => false]);
        $row = DB::table('telegram_link_tokens')
            ->where('token', $token)
            ->where('user_id', $request->user()->id)
            ->first();
        return response()->json([
            'linked' => $row && ! empty($row->used_at),
            'expired' => $row ? now()->greaterThan($row->expires_at) : true,
        ]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $user = $request->user();
        DB::table('WebUser')->where('id', $user->id)->update([
            'telegram_chat_id' => null,
            'telegram_user_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
            'dateChanged' => now(),
        ]);
        Audit::log('telegram_unlink', 'WebUser', $user->id);
        return response()->json(['message' => 'Отвязано']);
    }

    public function test(Request $request): JsonResponse
    {
        $ok = Telegram::send($request->user()->id, "🔔 Тестовое сообщение от DS Consulting (" . now()->format('H:i') . ")");
        return response()->json(['sent' => $ok]);
    }
}
