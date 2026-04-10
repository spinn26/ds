<?php

use Illuminate\Support\Facades\Route;

// Serve React SPA for all non-API routes
Route::get('/{any?}', function () {
    $path = public_path('spa/index.html');
    if (file_exists($path)) {
        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/html',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
    return response('SPA not built yet', 404);
})->where('any', '^(?!api|sanctum|spa).*$');
