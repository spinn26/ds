<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint для b2c-frame loader'а InSmart.
 *
 * Виджет подгружается на /insmart-widget. Loader дёргает наш callback
 * (window.InssmartEventListener.auth(cb)) → cb идёт сюда → мы возвращаем
 * подписанный HS256 JWT с user-info партнёра, ключом подписи служит
 * «Закрытый ключ» приложения из b2b-кабинета InSmart (services.insmart.secret).
 *
 * Виджет InSmart проверяет подпись JWT на своей стороне (у него есть
 * тот же закрытый ключ привязанный к app_id), извлекает user-data,
 * авторизует партнёра «бесшовно» — без формы регистрации в их системе.
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

        // Берём актуальную запись WebUser — за время сессии могла обновиться
        // (например, партнёр добавил телефон в профиле).
        $webUser = DB::table('WebUser')->where('id', $user->id)->first();

        $now = time();
        $payload = [
            // Стандартные JWT-клеймы.
            'iss' => $appId,
            'sub' => (string) $consultant->id,
            'iat' => $now,
            'exp' => $now + 3600, // 1 час; виджет дёргает callback при необходимости.
            // User-data — формат основан на типичных полях b2c-виджетов
            // (id + ФИО + контакты). Если их виджет ждёт другие имена/структуру —
            // правим по фидбэку 400-ответа.
            'id' => (string) $consultant->id,
            'clientId' => (string) $consultant->id,
            'firstName' => $webUser->firstName ?? null,
            'lastName' => $webUser->lastName ?? null,
            'middleName' => $webUser->patronymic ?? null,
            'email' => $webUser->email ?? null,
            'phone' => $webUser->phone ?? null,
            'birthDate' => $webUser->birthDate ?? null,
            'gender' => $webUser->gender ?? null,
        ];

        $jwt = self::signJwtHs256($payload, $secret);

        return response()->json([
            'token' => $jwt,
            'consultant_id' => (int) $consultant->id,
        ]);
    }

    /**
     * HS256 JWT-подпись. Без зависимостей — короче и проще, чем тянуть
     * firebase/php-jwt ради одной функции.
     */
    private static function signJwtHs256(array $payload, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $headerB64 = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $payloadB64 = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sig = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
        $sigB64 = self::base64UrlEncode($sig);
        return $headerB64 . '.' . $payloadB64 . '.' . $sigB64;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
