<?php

use App\Http\Controllers\ImpersonateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/admin/impersonate/{user}', [ImpersonateController::class, 'impersonate'])
        ->name('impersonate');
    Route::get('/admin/impersonate-leave', [ImpersonateController::class, 'leave'])
        ->name('impersonate.leave');
});

// Serve React SPA for all non-admin, non-api routes
Route::get('/{any?}', function () {
    $path = public_path('spa/index.html');
    if (file_exists($path)) {
        return response()->file($path, ['Content-Type' => 'text/html']);
    }
    return response('SPA not built yet. Run: cd frontend && npm run build', 404);
})->where('any', '^(?!admin|api|livewire|sanctum|static|spa).*$');
