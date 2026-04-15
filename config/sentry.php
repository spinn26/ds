<?php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),
    'release' => env('SENTRY_RELEASE'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
    'breadcrumbs' => [
        'logs' => true,
        'sql_queries' => true,
        'sql_bindings' => true,
        'queue_info' => true,
        'command_info' => true,
    ],
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
];
