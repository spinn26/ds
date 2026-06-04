<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Google Sheets — источник данных для TransactionImportController.
    // Получить API key: console.cloud.google.com → APIs & Services →
    //   Credentials → Create credentials → API key. Включи "Google
    //   Sheets API" и ограничь ключ по IP/referrer в проде.
    'google_sheets' => [
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
        'api_key' => env('GOOGLE_SHEETS_API_KEY'),
    ],

    // Insmart integration (per spec ✅Инсмарт.md).
    //   webhook_secret — для проверки X-Insmart-Secret в webhook'ах.
    //   api_base_url / api_key — старый flow (получение токена через их API).
    //   app_id / secret — b2c-frame loader (ID приложения + закрытый ключ
    //     из b2b-кабинета InSmart). secret используется для HS256 JWT-signing
    //     в InsmartController::widgetToken, JWT уходит в виджет как user-auth.
    'insmart' => [
        'webhook_secret' => env('INSMART_WEBHOOK_SECRET'),
        'api_base_url' => env('INSMART_API_BASE_URL', 'https://api.insmart.ru/v1'),
        'api_key' => env('INSMART_API_KEY'),
        'app_id' => env('INSMART_APP_ID'),
        'secret' => env('INSMART_SECRET'),
    ],

    // Внутренний host для socket-server, используется HealthController + SocketService.
    // emit_secret/api_port читаются здесь (а не через env() в SocketService),
    // иначе при закэшированном конфиге (config:cache на проде) env() вне
    // config-файлов возвращает пусто → Laravel шлёт эмиты без Authorization →
    // socket-server отвечает 401 на все события.
    'socket' => [
        'host' => env('SOCKET_HOST', '127.0.0.1'),
        'port' => (int) env('SOCKET_HTTP_PORT', 3002),
        'api_port' => (int) env('SOCKET_API_PORT', 3002),
        'emit_secret' => env('SOCKET_EMIT_SECRET', ''),
    ],

    // Telegram-уведомления. Создать бота через @BotFather, получить токен,
    // положить в TELEGRAM_BOT_TOKEN. Пользователи привязывают аккаунт
    // через deeplink t.me/<bot>?start=<one-time-token>. Webhook
    // регистрируется один раз: php artisan telegram:setup-webhook.
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        // openssl rand -hex 32 — подпись X-Telegram-Bot-Api-Secret-Token,
        // которую Telegram передаёт в каждом update'е.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

];
