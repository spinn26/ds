<?php

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Фолбэк для /storage/{path} — обслуживает файлы из storage/app/public/
 * напрямую через PHP, если `php artisan storage:link` не отработал на
 * деплое (типичная проблема — симлинка нет, картинки продуктов
 * возвращали 404, и CSS background-url молча падал в пустоту).
 *
 * При наличии симлинки веб-сервер (nginx/apache) перехватит запрос
 * раньше Laravel — этот роут просто не сработает. Поэтому overhead
 * нулевой в продакшене с правильно настроенным деплоем.
 */
Route::get('/storage/{path}', function (string $path) {
    if (! Storage::disk('public')->exists($path)) {
        abort(404);
    }
    return Storage::disk('public')->response($path);
})->where('path', '.*');

// All non-API / non-storage routes serve the Vue SPA.
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|sanctum|storage).*$');
