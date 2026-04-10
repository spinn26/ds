<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/check-duplicates', [AuthController::class, 'checkDuplicates']);
    Route::post('/auth/check-referral', [AuthController::class, 'checkReferral']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/activate', [AuthController::class, 'activate']);

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/status-levels', [DashboardController::class, 'statusLevels']);

        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/contracts/my', [ContractController::class, 'myContracts']);
        Route::get('/contracts/team', [ContractController::class, 'teamContracts']);
    });
});
