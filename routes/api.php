<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\CommunicationController;
use App\Http\Controllers\Api\ContestController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\ProductController;
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
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::put('/profile/requisites', [ProfileController::class, 'updateRequisites']);
        Route::put('/profile/bank-requisites', [ProfileController::class, 'updateBankRequisites']);
        Route::get('/profile/agreement-documents', [ProfileController::class, 'agreementDocuments']);

        Route::get('/structure', [StructureController::class, 'index']);
        Route::get('/structure/{consultantId}/children', [StructureController::class, 'children']);
        Route::get('/structure/qualification-levels', [StructureController::class, 'qualificationLevels']);
        Route::get('/structure/activity-statuses', [StructureController::class, 'activityStatuses']);

        Route::get('/communication', [CommunicationController::class, 'index']);
        Route::get('/communication/unread-count', [CommunicationController::class, 'unreadCount']);
        Route::post('/communication', [CommunicationController::class, 'send']);
        Route::post('/communication/{id}/read', [CommunicationController::class, 'markRead']);
        Route::get('/communication/categories', [CommunicationController::class, 'categories']);

        Route::post('/documents/upload', [\App\Http\Controllers\Api\DocumentController::class, 'upload']);
        Route::get('/documents', [\App\Http\Controllers\Api\DocumentController::class, 'list']);

        Route::get('/finance/report', [FinanceController::class, 'report']);
        Route::get('/finance/calculator', [FinanceController::class, 'calculator']);
        Route::get('/calculator/product-matrix', [\App\Http\Controllers\Api\CalculatorController::class, 'productMatrix']);
        Route::post('/calculator/calculate', [\App\Http\Controllers\Api\CalculatorController::class, 'calculate']);
        Route::get('/calculator/history', [\App\Http\Controllers\Api\CalculatorController::class, 'history']);
        Route::delete('/calculator/history', [\App\Http\Controllers\Api\CalculatorController::class, 'clearHistory']);

        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/contests', [ContestController::class, 'index']);

        // Admin
        Route::post('/impersonate/{user}', [ImpersonateController::class, 'impersonate']);
        Route::post('/impersonate/leave', [ImpersonateController::class, 'leave']);

        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy']);

        Route::get('/admin/partners', [\App\Http\Controllers\Api\AdminDataController::class, 'partners']);
        Route::post('/admin/partners/{id}/status', [\App\Http\Controllers\Api\AdminDataController::class, 'changePartnerStatus']);
        Route::get('/admin/partner-statuses', [\App\Http\Controllers\Api\AdminDataController::class, 'partnerStatuses']);
        Route::get('/admin/clients', [\App\Http\Controllers\Api\AdminDataController::class, 'clients']);
        Route::get('/admin/requisites', [\App\Http\Controllers\Api\AdminDataController::class, 'requisites']);
        Route::post('/admin/requisites/{id}/verify', [\App\Http\Controllers\Api\AdminDataController::class, 'verifyRequisites']);
        Route::get('/admin/acceptance', [\App\Http\Controllers\Api\AdminDataController::class, 'acceptance']);
        Route::get('/admin/contracts', [\App\Http\Controllers\Api\AdminDataController::class, 'contracts']);
        Route::get('/admin/transfers', [\App\Http\Controllers\Api\AdminDataController::class, 'transfers']);

        Route::get('/admin/transactions', [\App\Http\Controllers\Api\AdminFinanceController::class, 'transactions']);
        Route::get('/admin/commissions', [\App\Http\Controllers\Api\AdminFinanceController::class, 'commissions']);
        Route::get('/admin/pool', [\App\Http\Controllers\Api\AdminFinanceController::class, 'pool']);
        Route::get('/admin/qualifications', [\App\Http\Controllers\Api\AdminFinanceController::class, 'qualifications']);
        Route::get('/admin/charges', [\App\Http\Controllers\Api\AdminFinanceController::class, 'charges']);
        Route::get('/admin/payments', [\App\Http\Controllers\Api\AdminFinanceController::class, 'payments']);
        Route::get('/admin/reports', [\App\Http\Controllers\Api\AdminFinanceController::class, 'reports']);
        Route::get('/admin/report-availability', [\App\Http\Controllers\Api\AdminFinanceController::class, 'reportAvailability']);
        Route::get('/admin/currencies', [\App\Http\Controllers\Api\AdminFinanceController::class, 'currencies']);
        Route::get('/admin/transaction-import', [\App\Http\Controllers\Api\AdminFinanceController::class, 'transactionImport']);

        // Admin Products CRUD
        Route::get('/admin/products', [\App\Http\Controllers\Api\AdminProductController::class, 'index']);
        Route::post('/admin/products', [\App\Http\Controllers\Api\AdminProductController::class, 'store']);
        Route::put('/admin/products/{id}', [\App\Http\Controllers\Api\AdminProductController::class, 'update']);
        Route::delete('/admin/products/{id}', [\App\Http\Controllers\Api\AdminProductController::class, 'destroy']);
        Route::get('/admin/products/{id}/programs', [\App\Http\Controllers\Api\AdminProductController::class, 'programs']);
        Route::post('/admin/products/{id}/programs', [\App\Http\Controllers\Api\AdminProductController::class, 'storeProgram']);
        Route::put('/admin/products/{id}/programs/{programId}', [\App\Http\Controllers\Api\AdminProductController::class, 'updateProgram']);
        Route::delete('/admin/products/{id}/programs/{programId}', [\App\Http\Controllers\Api\AdminProductController::class, 'destroyProgram']);

        // Admin Education CRUD
        Route::get('/admin/education/courses', [\App\Http\Controllers\Api\AdminEducationController::class, 'courses']);
        Route::post('/admin/education/courses', [\App\Http\Controllers\Api\AdminEducationController::class, 'storeCourse']);
        Route::put('/admin/education/courses/{id}', [\App\Http\Controllers\Api\AdminEducationController::class, 'updateCourse']);
        Route::delete('/admin/education/courses/{id}', [\App\Http\Controllers\Api\AdminEducationController::class, 'destroyCourse']);
        Route::get('/admin/education/courses/{id}/lessons', [\App\Http\Controllers\Api\AdminEducationController::class, 'lessons']);
        Route::post('/admin/education/courses/{id}/lessons', [\App\Http\Controllers\Api\AdminEducationController::class, 'storeLesson']);
        Route::put('/admin/education/courses/{id}/lessons/{lessonId}', [\App\Http\Controllers\Api\AdminEducationController::class, 'updateLesson']);
        Route::delete('/admin/education/courses/{id}/lessons/{lessonId}', [\App\Http\Controllers\Api\AdminEducationController::class, 'destroyLesson']);
        Route::get('/admin/education/courses/{id}/tests', [\App\Http\Controllers\Api\AdminEducationController::class, 'tests']);
        Route::post('/admin/education/courses/{id}/tests', [\App\Http\Controllers\Api\AdminEducationController::class, 'storeTest']);
        Route::put('/admin/education/courses/{id}/tests/{testId}', [\App\Http\Controllers\Api\AdminEducationController::class, 'updateTest']);
        Route::delete('/admin/education/courses/{id}/tests/{testId}', [\App\Http\Controllers\Api\AdminEducationController::class, 'destroyTest']);
    });
});
