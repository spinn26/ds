<?php

use Illuminate\Support\Facades\Route;

// Serve React SPA for all routes except /api
Route::get('/{any?}', function () {
    $path = public_path('spa/index.html');
    if (file_exists($path)) {
        return response()->file($path, ['Content-Type' => 'text/html']);
    }
    return response('SPA not built yet. Run: cd frontend && npm run build', 404);
})->where('any', '^(?!api|spa).*$');
