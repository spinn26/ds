<?php

use Laravel\Sanctum\Sanctum;

return [
    /*
     * Этот файл переопределяет дефолты Laravel Sanctum (vendor/laravel/sanctum/config/sanctum.php).
     * Главное отличие — `expiration` читается из env: SANCTUM_TOKEN_EXPIRATION
     * в МИНУТАХ. Дефолт: 30 дней (43200 минут). Без явного значения
     * токены жили бы вечно — украденный = пожизненный доступ.
     */

    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        sprintf(
            '%s%s',
            'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
            Sanctum::currentApplicationUrlWithPort(),
        ),
    )),

    'guard' => ['web'],

    // ВАЖНО: на проде ставь 43200 (30 дней) или меньше через .env.
    // null = без срока жизни, использовать только для разработки.
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 43200),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
