<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Ежедневная проверка статусов партнёров
Schedule::command('partners:check-statuses')->dailyAt('02:00');

// 1-го числа каждого месяца в 00:00 — копирование курсов валют с прошлого
// месяца как заглушка, чтобы расчёты платформы не падали (per spec
// ✅Справочники для расчёта транзакций §2.1 шаг 1).
Schedule::command('currencies:copy-monthly-rates')
    ->monthlyOn(1, '00:00');

// Health-check платформы (БД/Cache/Socket.IO) — каждые 5 минут.
// Алерт в Telegram шлётся только при переходе up↔down, чтобы не спамить.
Schedule::command('platform:health-check')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();
