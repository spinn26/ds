<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint для b2c-frame loader'а InSmart.
 *
 * Поток (per InSmart docs):
 *   1. Viewer заходит на /insmart-widget
 *   2. Frontend loader дёргает callback InssmartEventListener.auth
 *   3. Callback идёт сюда (GET /api/v1/insmart/widget-token)
 *   4. Мы POST'им на api.inssmart.ru/v1/widget/clients/token с
 *      {appGuid, privateKey, partnerId} — закрытый ключ остаётся на бэке,
 *      «прямое обращение со страницы виджета недопустимо» (доки InSmart)
 *   5. InSmart возвращает {accessToken, refreshToken} — JWT, подписанные
 *      их секретом — это и есть user-токен для бесшовной авторизации
 *   6. Мы передаём этот объект фронту, фронт отдаёт его виджету как есть
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

        $appId = (string) config('services.insmart.app_id');
        $secret = (string) config('services.insmart.secret');

        if (! $appId || ! $secret) {
            return response()->json([
                'message' => 'InSmart не настроен: INSMART_APP_ID / INSMART_SECRET.',
            ], 503);
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->asJson()
                ->post('https://api.inssmart.ru/v1/widget/clients/token', [
                    'appGuid' => $appId,
                    'privateKey' => $secret,
                    'partnerId' => (string) $consultant->id,
                    // description — опциональное поле, помогает в их аналитике
                    // понять, кто из наших партнёров заходил.
                    'description' => trim(($consultant->personName ?? '') . ' (consultant #' . $consultant->id . ')'),
                ]);

            if (! $response->successful()) {
                Log::warning('Insmart widget token: API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'partner_id' => $consultant->id,
                ]);
                return response()->json([
                    'message' => 'InSmart API вернул ошибку',
                    'status' => $response->status(),
                    'detail' => $response->json() ?? $response->body(),
                ], 502);
            }

            // Передаём фронту весь ответ как есть — виджет ждёт ровно
            // {accessToken, refreshToken} (см. документацию InSmart).
            return response()->json($response->json());
        } catch (\Throwable $e) {
            Log::error('Insmart widget token: exception', [
                'error' => $e->getMessage(),
                'partner_id' => $consultant->id,
            ]);
            return response()->json([
                'message' => 'Не удалось получить токен InSmart',
                'detail' => $e->getMessage(),
            ], 502);
        }
    }
}
