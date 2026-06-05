<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP-based 2FA для админских аккаунтов.
 *
 *  Workflow:
 *    1. POST /2fa/setup → бек генерирует secret, шифрует, сохраняет в
 *       WebUser.two_factor_secret (но not enabled). Возвращает QR/otpauth-uri.
 *    2. POST /2fa/confirm {code} → юзер вводит код из аппа, бек проверяет,
 *       ставит two_factor_enabled=true + two_factor_confirmed_at=now().
 *    3. На login: если у юзера 2fa_enabled=true — login возвращает
 *       requires_2fa=true + временный challenge_token. Фронт показывает
 *       ввод кода → POST /2fa/verify {challenge, code} → реальный Sanctum token.
 *    4. POST /2fa/disable {password} → снять 2FA (требует подтверждения паролем).
 */
class TwoFactorController extends Controller
{
    private function ga(): Google2FA
    {
        return new Google2FA();
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = $this->ga()->generateSecretKey(32);
        $user->two_factor_secret = Crypt::encryptString($secret);
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;
        $user->saveQuietly();

        $issuer = config('app.name', 'DS Consulting');
        $label = $user->email;
        $uri = $this->ga()->getQRCodeUrl($issuer, $label, $secret);

        Audit::log('2fa_setup_started', 'WebUser', $user->id);
        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $uri,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6']);
        $user = $request->user();
        if (! $user->two_factor_secret) {
            return response()->json(['message' => 'Сначала вызови /2fa/setup'], 422);
        }
        $secret = Crypt::decryptString($user->two_factor_secret);
        if (! $this->ga()->verifyKey($secret, $request->input('code'))) {
            return response()->json(['message' => 'Неверный код'], 422);
        }
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = now();
        $user->saveQuietly();
        Audit::log('2fa_enabled', 'WebUser', $user->id);
        return response()->json(['message' => '2FA включён']);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = $request->user();
        if (! $user->validatePassword($request->input('password'))) {
            return response()->json(['message' => 'Неверный пароль'], 422);
        }
        $user->two_factor_secret = null;
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;
        $user->saveQuietly();
        Audit::log('2fa_disabled', 'WebUser', $user->id);
        return response()->json(['message' => '2FA отключён']);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'enabled' => (bool) $user->two_factor_enabled,
            'confirmed_at' => $user->two_factor_confirmed_at,
        ]);
    }

    /**
     * Шаг 2 при login. Принимает временный challenge (короткий signed
     * token из AuthController::login) + code из приложения. На успехе
     * выдаёт реальный Sanctum spa-token.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'challenge' => 'required|string',
            'code' => 'required|string|size:6',
        ]);
        // challenge формат: base64(user_id|expires|signature)
        $parts = explode('|', base64_decode($request->input('challenge'), true) ?: '');
        if (count($parts) !== 3) {
            return response()->json(['message' => 'Неверный challenge'], 422);
        }
        [$uid, $expires, $sig] = $parts;
        $expected = hash_hmac('sha256', "{$uid}|{$expires}", config('app.key'));
        if (! hash_equals($expected, $sig) || (int) $expires < time()) {
            return response()->json(['message' => 'Challenge истёк, повторите вход'], 422);
        }

        $user = \App\Models\User::find((int) $uid);
        if (! $user || ! $user->two_factor_enabled) {
            return response()->json(['message' => 'Пользователь не найден или 2FA отключён'], 422);
        }
        // Та же гарда, что в AuthController::login — иначе 2FA-аккаунт обходит блокировку.
        if ($user->isBlocked || $user->dateDeleted) {
            Audit::log('login_blocked', 'WebUser', $user->id);
            return response()->json(['message' => 'Аккаунт заблокирован. Обратитесь в поддержку.'], 403);
        }
        $secret = Crypt::decryptString($user->two_factor_secret);
        if (! $this->ga()->verifyKey($secret, $request->input('code'))) {
            return response()->json(['message' => 'Неверный код'], 422);
        }

        $token = $user->createToken('spa')->plainTextToken;
        Audit::log('2fa_login_success', 'WebUser', $user->id);
        return response()->json([
            'token' => $token,
            'user' => \App\Http\Resources\UserResource::make($user),
        ]);
    }
}
