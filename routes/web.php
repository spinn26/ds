<?php

use App\Http\Controllers\ImpersonateController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/admin/impersonate/{user}', [ImpersonateController::class, 'impersonate'])
        ->name('impersonate');
    Route::get('/admin/impersonate-leave', [ImpersonateController::class, 'leave'])
        ->name('impersonate.leave');
});
