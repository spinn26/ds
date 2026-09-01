<?php

/**
 * API v1 — точка сборки.
 *
 * Определения роутов вынесены в routes/v1/* по уровню доступа и
 * подключаются через require внутри соответствующих групп: префикс и
 * middleware наследуются, порядок регистрации совпадает с прежним
 * единым файлом.
 *
 *   v1/public.php   — без авторизации: логин, вебхуки, публичные настройки
 *   v1/cabinet.php  — любой авторизованный: партнёр и сотрудник
 *   v1/admin.php    — бэкофис, требует staff-роль
 *   v1/signed.php   — скачивание файлов по временной подписи
 *
 * Порядок require менять нельзя: роуты матчатся в порядке регистрации,
 * и статические сегменты должны идти раньше параметрических.
 */

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__.'/v1/public.php';

    Route::middleware(['auth:sanctum', 'maintenance'])->group(function () {
        require __DIR__.'/v1/cabinet.php';

        require __DIR__.'/v1/admin.php';
    });

    require __DIR__.'/v1/signed.php';
});
