<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Партнёрский endpoint для отрисовки виджета InSmart.
 *
 * GET /api/v1/insmart/widget-token — получает у Insmart временный
 * токен на текущего консультанта (per spec ✅Инсмарт.md / Интеграция
 * Инсмарт.md). Дальше фронт подставляет токен в iframe виджета.
 *
 * Если INSMART_API_KEY/URL не настроены — отдаём заглушку, чтобы
 * страница не падала в dev-окружении без реальных кредов.
 */
class InsmartController extends Controller
{
    public function widgetToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();
        if (! $consultant) {
            return response()->json(['message' => 'Партнёр не найден'], 404);
        }

        $base = rtrim((string) config('services.insmart.api_base_url'), '/');
        $apiKey = (string) config('services.insmart.api_key');

        if (! $base || ! $apiKey) {
            return response()->json([
                'token' => null,
                'widget_url' => null,
                'consultant_id' => (int) $consultant->id,
                'message' => 'Insmart API не настроен (INSMART_API_KEY / INSMART_API_BASE_URL).',
            ], 200);
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                ])
                ->post($base . '/widget/clients/token', [
                    'clientId' => (string) $consultant->id,
                    'fio' => $consultant->personName,
                    'email' => $user->email,
                    'phone' => $consultant->phoneNumber ?? null,
                ]);

            if (! $response->successful()) {
                Log::warning('Insmart widget token: bad response', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return response()->json([
                    'message' => 'Insmart вернул ошибку: ' . $response->status(),
                ], 502);
            }

            $data = $response->json();
            return response()->json([
                'token' => $data['token'] ?? null,
                'widget_url' => $data['widgetUrl'] ?? $data['widget_url'] ?? null,
                'expires_at' => $data['expiresAt'] ?? null,
                'consultant_id' => (int) $consultant->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Insmart widget token: exception', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Не удалось получить токен Insmart'], 502);
        }
    }
}
