<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiSettingsService;
use App\Services\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin UI для правки ключей внешних интеграций.
 * Значения шифруются на уровне модели ApiSetting (encrypted cast).
 */
class AdminApiSettingsController extends Controller
{
    public function __construct(
        private readonly ApiSettingsService $settings,
        private readonly TelegramNotifier $telegram,
    ) {}

    /** GET /admin/api-settings — список ключей с метаданными (без секретов). */
    public function index(): JsonResponse
    {
        return response()->json(['items' => $this->settings->listForUi()]);
    }

    /** PUT /admin/api-settings — bulk-сохранение (только передаваемые ключи). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable|string|max:4000',
        ]);

        $allowed = array_keys(ApiSettingsService::CATALOG);
        $changed = 0;
        foreach ($data['settings'] as $row) {
            if (! in_array($row['key'], $allowed, true)) continue;
            // Не перезаписываем "••••••••" — это placeholder для secret-полей при отображении
            $value = $row['value'] ?? null;
            if ($value === '••••••••') continue;
            $this->settings->set($row['key'], $value, $request->user()?->id);
            $changed++;
        }

        return response()->json(['message' => "Обновлено параметров: $changed"]);
    }

    /** POST /admin/api-settings/telegram-test — отправить тестовое сообщение. */
    public function testTelegram(Request $request): JsonResponse
    {
        $data = $request->validate(['chatId' => 'nullable|string']);
        $text = sprintf(
            "🟢 <b>Проверка Telegram-интеграции</b>\n\nПлатформа: %s\nВремя: %s\nПользователь: %s",
            config('app.url'),
            now()->format('d.m.Y H:i:s'),
            $request->user()?->email ?? '—',
        );
        $ok = $this->telegram->send($text, $data['chatId'] ?? null);

        return response()->json([
            'ok' => $ok,
            'message' => $ok ? 'Сообщение отправлено' : 'Не удалось отправить (проверь token и chat_id)',
        ]);
    }
}
