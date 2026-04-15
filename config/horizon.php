<?php

use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => 'horizon',
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_horizon:'),
    'middleware' => ['web', 'auth:sanctum'],
    'waiter' => ['enabled' => false, 'events' => ['*'], 'timeout' => 30],
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'silenced' => [],
    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 5,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],
];
