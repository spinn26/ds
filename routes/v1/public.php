<?php

/**
 * v1 — публичные роуты: без auth:sanctum.
 *
 * Health-check, регистрация/логин, восстановление пароля, вебхуки внешних
 * систем и настройки, которые SPA читает до авторизации (дизайн, i18n,
 * статус обслуживания).
 *
 * Файл подключается через require из routes/api.php внутри
 * Route::prefix('v1')->group(...) — префикс v1 наследуется автоматически.
 */

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// Health check — публичный, без Sanctum (чтобы можно было пинговать
// даже если БД/auth сломан). Используется uptime-monitoring.
Route::get('/health', [\App\Http\Controllers\Api\HealthController::class, 'check']);

// Публичный роадмап — без auth, читается /roadmap-страницей.
Route::get('/roadmap', [\App\Http\Controllers\Api\RoadmapController::class, 'publicIndex']);

// Login: троттлинг по УЧЁТКЕ (email), не по IP — см. named limiter
// 'login' в AppServiceProvider::boot (5/мин на аккаунт + бэкстоп 30/мин
// на IP). Общий NAT/VPN больше не выедает лимит между пользователями.
Route::middleware('throttle:login')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
});

// Остальные auth-эндпоинты — прежний per-IP лимит (5 попыток/мин)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    // Восстановление пароля: ссылка на email + сброс по токену.
    // Password broker внутри сам троттлит повторные отправки
    // (300с / 5 мин между письмами на один email — config/auth.php).
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/check-duplicates', [AuthController::class, 'checkDuplicates']);
    Route::post('/auth/check-referral', [AuthController::class, 'checkReferral']);
});

// Telegram webhook — без auth, валидация через
// X-Telegram-Bot-Api-Secret-Token (config services.telegram.webhook_secret).
Route::post('/webhooks/telegram', [\App\Http\Controllers\Api\TelegramWebhookController::class, 'handle']);

// Insmart webhook — без auth:sanctum (внешний источник),
// защищён shared-secret в заголовке X-Insmart-Secret + throttle.
Route::middleware('throttle:60,1')->group(function () {
    // Insmart-вебхук (per spec ✅Инсмарт.md). Авторизация —
    // HMAC X-Insmart-Signature (или fallback X-Insmart-Secret).
    // Сервис пишет person+client+contract+transaction в одной транзакции,
    // id для product/program/contract/transaction берутся через LegacyId::next.
    Route::post('/webhooks/insmart/paid', [\App\Http\Controllers\Api\InsmartWebhookController::class, 'paid']);
});

// 2FA verify — БЕЗ auth (это шаг 2 логина: юзер прошёл email+пароль,
// получил challenge, теперь подтверждает TOTP-код). Throttled.
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/2fa/verify', [\App\Http\Controllers\Api\TwoFactorController::class, 'verify']);
});

// Активный дизайн (логотип/палитры/CSS) — ПУБЛИЧНО: применяется SPA в
// рантайме, в т.ч. на странице входа (до авторизации). Это только брендинг.
Route::get('/design/active', [\App\Http\Controllers\Api\DesignController::class, 'active'])
    ->middleware('throttle:60,1');

// Переопределения строк i18n — ПУБЛИЧНО (применяются SPA на старте).
Route::get('/i18n/overrides', [\App\Http\Controllers\Api\TranslationController::class, 'overrides'])
    ->middleware('throttle:60,1');

// Статус режима обслуживания — ПУБЛИЧНО (страница /maintenance читает отсчёт
// и сама впускает обратно, когда админ выключит). Без auth и без maintenance-гарда.
Route::get('/maintenance', [\App\Http\Controllers\Api\MaintenanceController::class, 'status'])
    ->middleware('throttle:60,1');

