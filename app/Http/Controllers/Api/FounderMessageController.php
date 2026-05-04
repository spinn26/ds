<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiSettingsService;
use App\Services\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per spec ✅Написать собственику.
 *
 * Партнёр пишет короткое сообщение основателю; оно публикуется в
 * закрытую Telegram-группу. Чекбокс «анонимно» — если включён, ФИО/email
 * не передаются.
 *
 * Используется `telegram.staff.chat_id` (приоритет) или `status.chat_id`
 * (fallback) как назначение.
 */
class FounderMessageController extends Controller
{
    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly ApiSettingsService $settings,
    ) {}

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:5000'],
            'anonymous' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $isAnon = (bool) ($data['anonymous'] ?? false);

        $text = "<b>Сообщение собственику</b>\n\n";
        $text .= htmlspecialchars($data['message']);
        $text .= "\n\n—\n";
        if ($isAnon) {
            $text .= '<i>Отправлено анонимно</i>';
        } else {
            $name = trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '')) ?: ('user#' . $user->id);
            $text .= 'От: ' . htmlspecialchars($name);
            if (! empty($user->email)) {
                $text .= ' &lt;' . htmlspecialchars($user->email) . '&gt;';
            }
        }
        $text .= "\n" . now()->format('d.m.Y H:i');

        $chatId = $this->settings->get('telegram.staff.chat_id')
            ?: $this->settings->get('telegram.status.chat_id');

        $ok = $this->telegram->send($text, $chatId);

        if (! $ok) {
            return response()->json([
                'message' => 'Telegram не настроен или недоступен. Свяжитесь с поддержкой.',
            ], 503);
        }

        return response()->json(['message' => 'Сообщение отправлено собственику']);
    }
}
