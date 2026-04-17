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
    // Auth routes with rate limiting (5 attempts per minute)
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/register', [AuthController::class, 'register']);
    });
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/auth/check-duplicates', [AuthController::class, 'checkDuplicates']);
        Route::post('/auth/check-referral', [AuthController::class, 'checkReferral']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/activate', [AuthController::class, 'activate']);

        Route::get('/workspace', [\App\Http\Controllers\Api\WorkspaceController::class, 'index']);
        Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);

        // Chat system v2
        Route::get('/chat/tickets', [\App\Http\Controllers\Api\ChatController::class, 'index']);
        Route::post('/chat/tickets', [\App\Http\Controllers\Api\ChatController::class, 'store']);
        Route::get('/chat/tickets/stats', [\App\Http\Controllers\Api\ChatController::class, 'stats']);
        Route::get('/chat/unread-count', [\App\Http\Controllers\Api\ChatController::class, 'unreadCount']);
        Route::get('/chat/tickets/staff', [\App\Http\Controllers\Api\ChatController::class, 'staffList']);
        Route::get('/chat/tickets/{id}', [\App\Http\Controllers\Api\ChatController::class, 'show']);
        Route::post('/chat/tickets/{id}/messages', [\App\Http\Controllers\Api\ChatController::class, 'sendMessage']);
        Route::put('/chat/messages/{messageId}', [\App\Http\Controllers\Api\ChatController::class, 'editMessage']);
        Route::post('/chat/messages/{messageId}/reactions', [\App\Http\Controllers\Api\ChatController::class, 'toggleReaction']);
        Route::post('/chat/tickets/{id}/pin', [\App\Http\Controllers\Api\ChatController::class, 'togglePin']);
        Route::post('/chat/tickets/{id}/status', [\App\Http\Controllers\Api\ChatController::class, 'updateStatus']);
        Route::post('/chat/tickets/{id}/assign', [\App\Http\Controllers\Api\ChatController::class, 'assign']);
        Route::get('/chat/tickets/{id}/notes', [\App\Http\Controllers\Api\ChatController::class, 'notes']);
        Route::post('/chat/tickets/{id}/notes', [\App\Http\Controllers\Api\ChatController::class, 'addNote']);
        Route::get('/chat/quick-replies', [\App\Http\Controllers\Api\ChatController::class, 'quickReplies']);
        Route::get('/chat/knowledge', [\App\Http\Controllers\Api\ChatController::class, 'knowledgeArticles']);
        Route::get('/chat/tickets/{id}/knowledge-suggest', [\App\Http\Controllers\Api\ChatController::class, 'knowledgeSuggest']);
        Route::post('/chat/tickets/{id}/save-to-kb', [\App\Http\Controllers\Api\ChatController::class, 'saveTicketAsArticle']);
        Route::get('/chat/analytics', [\App\Http\Controllers\Api\ChatController::class, 'analytics']);
        Route::get('/chat/my-open', [\App\Http\Controllers\Api\ChatController::class, 'myOpenTickets']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/status-levels', [DashboardController::class, 'statusLevels']);

        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/contracts/my', [ContractController::class, 'myContracts']);
        Route::get('/contracts/team', [ContractController::class, 'teamContracts']);
        Route::get('/contracts/statuses', [ContractController::class, 'statuses']);
        Route::get('/contracts/products', [ContractController::class, 'products']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/questionnaire', [ProfileController::class, 'saveQuestionnaire']);
        Route::post('/profile/password', [ProfileController::class, 'changePassword']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::put('/profile/requisites', [ProfileController::class, 'updateRequisites']);
        Route::put('/profile/bank-requisites', [ProfileController::class, 'updateBankRequisites']);
        Route::get('/profile/agreement-documents', [ProfileController::class, 'agreementDocuments']);

        Route::get('/structure', [StructureController::class, 'index']);
        Route::get('/structure/{consultantId}/children', [StructureController::class, 'children']);
        Route::get('/structure/qualification-levels', [StructureController::class, 'qualificationLevels']);
        Route::get('/structure/activity-statuses', [StructureController::class, 'activityStatuses']);
        Route::get('/structure/cities', [StructureController::class, 'cities']);

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
        Route::get('/education', function () {
            return response()->json(['videos' => [], 'documents' => []]);
        });

        // Admin — all routes require staff role
        Route::middleware('role:admin')->group(function () {
            Route::post('/impersonate/{user}', [ImpersonateController::class, 'impersonate']);
            Route::post('/impersonate/leave', [ImpersonateController::class, 'leave']);
        });

        Route::middleware('role:admin,backoffice,support,finance,head,calculations,corrections')->group(function () {
        Route::get('/admin/dashboard', [\App\Http\Controllers\Api\AdminDashboardController::class, 'index']);
        Route::get('/admin/export/{type}', [\App\Http\Controllers\Api\ExportController::class, 'export']);
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy']);

        Route::get('/admin/partners', [\App\Http\Controllers\Api\AdminDataController::class, 'partners']);
        Route::put('/admin/partners/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'updatePartner']);
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
        Route::post('/admin/charges', [\App\Http\Controllers\Api\AdminFinanceController::class, 'storeCharge']);
        Route::delete('/admin/charges/{id}', [\App\Http\Controllers\Api\AdminFinanceController::class, 'deleteCharge']);
        Route::get('/admin/payments', [\App\Http\Controllers\Api\AdminFinanceController::class, 'payments']);
        Route::get('/admin/reports', [\App\Http\Controllers\Api\AdminFinanceController::class, 'reports']);
        Route::get('/admin/report-availability', [\App\Http\Controllers\Api\AdminFinanceController::class, 'reportAvailability']);
        Route::get('/admin/currencies', [\App\Http\Controllers\Api\AdminFinanceController::class, 'currencies']);
        Route::get('/admin/transaction-import/form-data', [\App\Http\Controllers\Api\TransactionImportController::class, 'formData']);
        Route::get('/admin/transaction-import/sheet-names', [\App\Http\Controllers\Api\TransactionImportController::class, 'sheetNames']);
        Route::post('/admin/transaction-import', [\App\Http\Controllers\Api\TransactionImportController::class, 'import']);
        Route::post('/admin/transaction-import/from-sheets', [\App\Http\Controllers\Api\TransactionImportController::class, 'importFromSheets']);

        // News CRUD (admin)
        Route::get('/admin/news', [\App\Http\Controllers\Api\WorkspaceController::class, 'newsList']);
        Route::post('/admin/news', [\App\Http\Controllers\Api\WorkspaceController::class, 'createNews']);
        Route::put('/admin/news/{id}', [\App\Http\Controllers\Api\WorkspaceController::class, 'updateNews']);
        Route::delete('/admin/news/{id}', [\App\Http\Controllers\Api\WorkspaceController::class, 'deleteNews']);
        Route::get('/admin/transaction-import/history', [\App\Http\Controllers\Api\TransactionImportController::class, 'history']);
        Route::post('/admin/transaction-import/{id}/rollback', [\App\Http\Controllers\Api\TransactionImportController::class, 'rollback']);
        Route::post('/admin/transaction-import/{id}/calculate', [\App\Http\Controllers\Api\TransactionImportController::class, 'calculateCommissions']);
        Route::post('/admin/transactions/{id}/calculate', [\App\Http\Controllers\Api\TransactionImportController::class, 'calculateSingle']);

        // Admin Mail (SMTP settings, broadcast, templates, send log)
        Route::get('/admin/mail/settings', [\App\Http\Controllers\Api\AdminMailController::class, 'settings']);
        Route::put('/admin/mail/settings', [\App\Http\Controllers\Api\AdminMailController::class, 'updateSettings']);
        Route::post('/admin/mail/test', [\App\Http\Controllers\Api\AdminMailController::class, 'test']);
        Route::post('/admin/mail/broadcast', [\App\Http\Controllers\Api\AdminMailController::class, 'broadcast']);
        Route::get('/admin/mail/broadcast/{id}/progress', [\App\Http\Controllers\Api\AdminMailController::class, 'broadcastProgress']);
        Route::post('/admin/mail/audience-preview', [\App\Http\Controllers\Api\AdminMailController::class, 'audiencePreview']);
        Route::get('/admin/mail/log', [\App\Http\Controllers\Api\AdminMailController::class, 'log']);
        Route::get('/admin/mail/templates', [\App\Http\Controllers\Api\AdminMailController::class, 'templates']);
        Route::post('/admin/mail/templates', [\App\Http\Controllers\Api\AdminMailController::class, 'storeTemplate']);
        Route::put('/admin/mail/templates/{id}', [\App\Http\Controllers\Api\AdminMailController::class, 'updateTemplate']);
        Route::delete('/admin/mail/templates/{id}', [\App\Http\Controllers\Api\AdminMailController::class, 'destroyTemplate']);

        // Admin References (generic CRUD for small reference tables)
        Route::get('/admin/references', [\App\Http\Controllers\Api\AdminReferenceController::class, 'catalogs']);
        Route::get('/admin/references/{catalog}', [\App\Http\Controllers\Api\AdminReferenceController::class, 'index']);
        Route::post('/admin/references/{catalog}', [\App\Http\Controllers\Api\AdminReferenceController::class, 'store']);
        Route::put('/admin/references/{catalog}/{id}', [\App\Http\Controllers\Api\AdminReferenceController::class, 'update']);
        Route::delete('/admin/references/{catalog}/{id}', [\App\Http\Controllers\Api\AdminReferenceController::class, 'destroy']);

        // Admin Contests CRUD
        Route::get('/admin/contests', [\App\Http\Controllers\Api\AdminContestController::class, 'index']);
        Route::get('/admin/contests/references', [\App\Http\Controllers\Api\AdminContestController::class, 'references']);
        Route::post('/admin/contests', [\App\Http\Controllers\Api\AdminContestController::class, 'store']);
        Route::put('/admin/contests/{id}', [\App\Http\Controllers\Api\AdminContestController::class, 'update']);
        Route::delete('/admin/contests/{id}', [\App\Http\Controllers\Api\AdminContestController::class, 'destroy']);

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
        }); // end role:staff
    });
});
