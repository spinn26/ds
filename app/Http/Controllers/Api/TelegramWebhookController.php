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
 * Регистрируется один раз через php artisan telegram:setup-webhook.
 * Telegram передаёт секрет в X-Telegram-Bot-Api-Secret-Token —
 * мы его сверяем перед обработкой.
 *
 * Кроме deeplink-команды /start <token> бот общается с пользователем
 * через reply-keyboard (см. Telegram::mainKeyboard) — кнопки «📊 Мой
 * статус», «ℹ️ Справка», «❌ Отвязать аккаунт».
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
        if (! $message) return response()->json(['ok' => true]);

        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        // from.id — Telegram user-id, не меняется (в отличие от chat_id
        // у супергрупп). Сохраняем при привязке для трекинга.
        $fromId = (string) ($message['from']['id'] ?? '');
        $fromUsername = $message['from']['username'] ?? null;
        if (! $chatId || ! $text) return response()->json(['ok' => true]);

        // /start <token> — привязка через deeplink.
        if (preg_match('/^\/start\s+(\S+)/', $text, $m)) {
            return $this->handleStart($chatId, $m[1], $fromId, $fromUsername);
        }

        // Кнопки reply-keyboard + текстовые fallback-команды.
        return match ($text) {
            '/start', '/help', 'ℹ️ Справка' => $this->handleHelp($chatId),
            '/status', '📊 Мой статус' => $this->handleStatus($chatId),
            '/unlink', '❌ Отвязать аккаунт' => $this->handleUnlink($chatId),
            default => $this->handleUnknown($chatId),
        };
    }

    private function handleStart(string $chatId, string $token, string $fromId = '', ?string $fromUsername = null): JsonResponse
    {
        $row = DB::table('telegram_link_tokens')->where('token', $token)->first();
        if (! $row) {
            Telegram::raw($chatId, "❌ Токен не найден. Сгенерируйте новый в кабинете.", Telegram::mainKeyboard());
            return response()->json(['ok' => true]);
        }
        if ($row->used_at) {
            Telegram::raw($chatId, "❌ Этот токен уже использован. Сгенерируйте новый.", Telegram::mainKeyboard());
            return response()->json(['ok' => true]);
        }
        if (now()->greaterThan($row->expires_at)) {
            Telegram::raw($chatId, "❌ Токен просрочен (действует 15 минут). Сгенерируйте новый.", Telegram::mainKeyboard());
            return response()->json(['ok' => true]);
        }

        DB::transaction(function () use ($row, $chatId, $token, $fromId, $fromUsername) {
            DB::table('WebUser')->where('id', $row->user_id)->update([
                'telegram_chat_id' => $chatId,
                'telegram_user_id' => $fromId ?: null,
                'telegram_username' => $fromUsername,
                'telegram_linked_at' => now(),
                'dateChanged' => now(),
            ]);
            DB::table('telegram_link_tokens')->where('token', $token)->update([
                'used_at' => now(),
                'used_chat_id' => $chatId,
                'updated_at' => now(),
            ]);
        });

        Audit::log('telegram_link_done', 'WebUser', $row->user_id, [
            'chat_id' => $chatId, 'user_id' => $fromId, 'username' => $fromUsername,
        ]);
        Telegram::raw(
            $chatId,
            "✅ <b>Аккаунт привязан!</b>\nСюда будут приходить уведомления DS Consulting.",
            Telegram::mainKeyboard()
        );
        return response()->json(['ok' => true]);
    }

    private function handleHelp(string $chatId): JsonResponse
    {
        Telegram::raw(
            $chatId,
            "👋 <b>Бот DS Consulting</b>\n\n"
            . "Я присылаю уведомления платформы:\n"
            . "• Критические инциденты системы\n"
            . "• Важные события вашего кабинета\n\n"
            . "Используйте кнопки внизу:\n"
            . "• 📊 Мой статус — посмотреть текущую привязку\n"
            . "• ❌ Отвязать аккаунт — больше не получать уведомления\n\n"
            . "Если ещё не привязали аккаунт — откройте в кабинете "
            . "<b>Профиль → Безопасность → Telegram</b> и нажмите «Привязать через бота».",
            Telegram::mainKeyboard()
        );
        return response()->json(['ok' => true]);
    }

    private function handleStatus(string $chatId): JsonResponse
    {
        $user = DB::table('WebUser')->where('telegram_chat_id', $chatId)->first(['id', 'email', 'firstName', 'lastName']);
        if (! $user) {
            Telegram::raw(
                $chatId,
                "⚠️ Этот чат пока не привязан к аккаунту.\n\nЧтобы привязать — откройте в кабинете <b>Профиль → Безопасность → Telegram</b> и нажмите «Привязать через бота».",
                Telegram::mainKeyboard()
            );
            return response()->json(['ok' => true]);
        }
        $name = trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')) ?: $user->email;
        Telegram::raw(
            $chatId,
            "✅ <b>Привязка активна</b>\n"
            . "Аккаунт: <b>" . e($name) . "</b>\n"
            . "Email: <code>" . e($user->email) . "</code>",
            Telegram::mainKeyboard()
        );
        return response()->json(['ok' => true]);
    }

    private function handleUnlink(string $chatId): JsonResponse
    {
        $user = DB::table('WebUser')->where('telegram_chat_id', $chatId)->first();
        if (! $user) {
            Telegram::raw($chatId, "Этот чат не привязан ни к одному аккаунту.", Telegram::mainKeyboard());
            return response()->json(['ok' => true]);
        }
        DB::table('WebUser')->where('id', $user->id)->update([
            'telegram_chat_id' => null,
            'telegram_user_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
            'dateChanged' => now(),
        ]);
        Audit::log('telegram_unlink', 'WebUser', $user->id, ['via' => 'bot']);
        // Убираем клавиатуру после отвязки — больше нечего показывать.
        Telegram::raw(
            $chatId,
            "✅ Аккаунт отвязан. Уведомления больше не будут приходить.\n\nЕсли передумаете — снова привяжите через кабинет.",
            ['remove_keyboard' => true]
        );
        return response()->json(['ok' => true]);
    }

    private function handleUnknown(string $chatId): JsonResponse
    {
        Telegram::raw(
            $chatId,
            "Не понял команду. Используйте кнопки внизу 👇",
            Telegram::mainKeyboard()
        );
        return response()->json(['ok' => true]);
    }
}
