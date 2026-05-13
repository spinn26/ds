<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/webhooks/telegram — приёмник update'ов от Telegram Bot API.
 *
 * Регистрируется один раз через Telegram::setWebhook($url, $secret):
 *   - url = https://dev.dsconsult.ru/api/v1/webhooks/telegram
 *   - secret = config('services.telegram.webhook_secret')
 *
 * Telegram передаёт секрет в заголовке X-Telegram-Bot-Api-Secret-Token —
 * мы его сверяем перед обработкой.
 *
 * Поддерживаемые команды:
 *   /start <token>  — привязка аккаунта (TelegramController::startLink выдаёт token).
 *   /unlink         — отвязать chat_id.
 *   /help           — короткая справка.
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $expected = (string) config('services.telegram.webhook_secret', '');
        if ($expected !== '') {
            $got = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
            if (! hash_equals($expected, $got)) {
                Log::warning('telegram webhook bad secret', ['ip' => $request->ip()]);
                return response()->json(['ok' => false], 401);
            }
        }

        $update = $request->all();
        $message = $update['message'] ?? null;
        if (! $message) return response()->json(['ok' => true]); // ignore non-message updates

        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        if (! $chatId || ! $text) return response()->json(['ok' => true]);

        // /start <token>
        if (preg_match('/^\/start\s+(\S+)/', $text, $m)) {
            return $this->handleStart($chatId, $m[1]);
        }
        // /start без аргумента — приветствие.
        if ($text === '/start') {
            Telegram::raw($chatId, "Привет! Чтобы получать уведомления DS Consulting, откройте в кабинете «Профиль → Безопасность → Telegram» и нажмите «Привязать».");
            return response()->json(['ok' => true]);
        }
        // /unlink
        if ($text === '/unlink') {
            return $this->handleUnlink($chatId);
        }
        // /help или что-то ещё
        Telegram::raw($chatId, "Доступные команды:\n/start &lt;token&gt; — привязать аккаунт\n/unlink — отвязать\n/help — эта подсказка");
        return response()->json(['ok' => true]);
    }

    private function handleStart(string $chatId, string $token): JsonResponse
    {
        $row = DB::table('telegram_link_tokens')->where('token', $token)->first();
        if (! $row) {
            Telegram::raw($chatId, "❌ Токен не найден. Сгенерируйте новый в кабинете.");
            return response()->json(['ok' => true]);
        }
        if ($row->used_at) {
            Telegram::raw($chatId, "❌ Этот токен уже использован. Сгенерируйте новый.");
            return response()->json(['ok' => true]);
        }
        if (now()->greaterThan($row->expires_at)) {
            Telegram::raw($chatId, "❌ Токен просрочен (действует 15 минут). Сгенерируйте новый в кабинете.");
            return response()->json(['ok' => true]);
        }

        DB::transaction(function () use ($row, $chatId, $token) {
            DB::table('WebUser')->where('id', $row->user_id)->update([
                'telegram_chat_id' => $chatId,
                'dateChanged' => now(),
            ]);
            DB::table('telegram_link_tokens')->where('token', $token)->update([
                'used_at' => now(),
                'used_chat_id' => $chatId,
                'updated_at' => now(),
            ]);
        });

        Audit::log('telegram_link_done', 'WebUser', $row->user_id, ['chat_id' => $chatId]);
        Telegram::raw($chatId, "✅ Аккаунт привязан! Сюда будут приходить уведомления DS Consulting.\n\nКоманды:\n/unlink — отвязать аккаунт");
        return response()->json(['ok' => true]);
    }

    private function handleUnlink(string $chatId): JsonResponse
    {
        $user = DB::table('WebUser')->where('telegram_chat_id', $chatId)->first();
        if (! $user) {
            Telegram::raw($chatId, "Аккаунт не привязан к этому чату.");
            return response()->json(['ok' => true]);
        }
        DB::table('WebUser')->where('id', $user->id)->update([
            'telegram_chat_id' => null,
            'dateChanged' => now(),
        ]);
        Audit::log('telegram_unlink', 'WebUser', $user->id, ['via' => 'bot']);
        Telegram::raw($chatId, "✅ Аккаунт отвязан. Уведомления больше не будут приходить.");
        return response()->json(['ok' => true]);
    }
}
