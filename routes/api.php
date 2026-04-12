<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\StructureController;
use App\Http\Controllers\ImpersonateController;
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
        Route::get('/contracts/statuses', [ContractController::class, 'statuses']);
        Route::get('/contracts/products', [ContractController::class, 'products']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/password', [ProfileController::class, 'changePassword']);
        Route::put('/profile/requisites', [ProfileController::class, 'updateRequisites']);
        Route::put('/profile/bank-requisites', [ProfileController::class, 'updateBankRequisites']);
        Route::get('/profile/agreement-documents', [ProfileController::class, 'agreementDocuments']);

        Route::get('/structure', [StructureController::class, 'index']);
        Route::get('/structure/{consultantId}/children', [StructureController::class, 'children']);
        Route::get('/structure/qualification-levels', [StructureController::class, 'qualificationLevels']);
        Route::get('/structure/activity-statuses', [StructureController::class, 'activityStatuses']);

        // Admin
        Route::post('/impersonate/{user}', [ImpersonateController::class, 'impersonate']);
        Route::post('/impersonate/leave', [ImpersonateController::class, 'leave']);

        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy']);
    });
});
