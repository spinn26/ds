<?php

use App\Http\Controllers\MailTrackController;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

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

// Публичный роадмап продукта — standalone Blade страница (не SPA),
// чтобы открывалась без Vuetify-бутстрапа и индексировалась поисковиками.
Route::get('/roadmap', function () {
    return view('roadmap');
})->name('public.roadmap');

// Mail tracking pixel + click-wrapper для исходящих писем (Уровень 3
// журнала отправки). См. MailTrackController. Пути короткие и без
// `/api/v1/` — чтобы tracking-URL в письме выглядел компактно и не
// требовал Sanctum-токена.
Route::get('/mt/o/{tid}.gif', [MailTrackController::class, 'open'])
    ->where('tid', '[0-9a-fA-F-]{36}')
    ->name('mail.track.open');
Route::get('/mt/c/{tid}', [MailTrackController::class, 'click'])
    ->where('tid', '[0-9a-fA-F-]{36}')
    ->name('mail.track.click');

// Spatie Health dashboard (HTML) + JSON-ручка для мониторинга / uptime-check.
// Защищены `auth:sanctum` middleware'ом не делаем — нужен внешний пинг от
// uptime-monitoring сервиса. Если в будущем понадобится — добавить
// signed-URL или basic-auth на nginx-уровне.
Route::get('/admin/health', HealthCheckResultsController::class)->name('health');
Route::get('/admin/health/json', HealthCheckJsonResultsController::class)->name('health.json');

// All non-API / non-storage routes serve the Vue SPA.
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|sanctum|storage|roadmap|mt|admin/health).*$');
