<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Ежедневная проверка статусов партнёров
Schedule::command('partners:check-statuses')->dailyAt('02:00');
