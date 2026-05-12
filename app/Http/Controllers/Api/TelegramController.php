<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $chatId = DB::table('WebUser')->where('id', $user->id)->value('telegram_chat_id');
        return response()->json([
            'enabled' => Telegram::enabled(),
            'bot_username' => config('services.telegram.bot_username'),
            'linked' => ! empty($chatId),
            'chat_id' => $chatId,
        ]);
    }

    public function link(Request $request): JsonResponse
    {
        $data = $request->validate(['chat_id' => 'required|string|max:64']);
        $user = $request->user();
        DB::table('WebUser')->where('id', $user->id)->update([
            'telegram_chat_id' => trim($data['chat_id']),
            'dateChanged' => now(),
        ]);
        Audit::log('telegram_link', 'WebUser', $user->id, ['chat_id' => $data['chat_id']]);
        // Отправим welcome-сообщение чтобы проверить chat_id сразу.
        $ok = Telegram::raw($data['chat_id'], "✅ DS Consulting: привязка успешна. Тут будут приходить уведомления.");
        return response()->json([
            'message' => $ok ? 'Привязано и проверено' : 'Привязано, но тестовое сообщение не доставлено (проверьте, что начали диалог с ботом)',
            'test_ok' => $ok,
        ]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $user = $request->user();
        DB::table('WebUser')->where('id', $user->id)->update([
            'telegram_chat_id' => null,
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
