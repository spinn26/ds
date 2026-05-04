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

    // Insmart webhook — без auth:sanctum (внешний источник),
    // защищён shared-secret в заголовке X-Insmart-Secret + throttle.
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/webhooks/insmart/paid', [\App\Http\Controllers\Api\InsmartWebhookController::class, 'paid']);
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
        Route::get('/chat/tickets/{id}/can-access', [\App\Http\Controllers\Api\ChatController::class, 'canAccess']);
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
        Route::get('/profile/cities', [ProfileController::class, 'cities']);

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
        Route::post('/products/accept-documents', [ProductController::class, 'acceptDocuments']);
        Route::post('/requisites/check-inn', [ProductController::class, 'checkInn']);
        Route::post('/requisites', [ProductController::class, 'setupRequisites']);
        Route::get('/contests', [ContestController::class, 'index']);
        Route::get('/instructions', [\App\Http\Controllers\Api\InstructionController::class, 'partnerList']);
        Route::get('/instructions/{slug}', [\App\Http\Controllers\Api\InstructionController::class, 'show']);
        Route::get('/education/courses', [\App\Http\Controllers\Api\EducationController::class, 'courses']);
        Route::get('/education/courses/{id}', [\App\Http\Controllers\Api\EducationController::class, 'show'])->whereNumber('id');
        Route::post('/education/courses/{id}/test', [\App\Http\Controllers\Api\EducationController::class, 'submitTest'])->whereNumber('id');
        Route::post('/education/lessons/{id}/view', [\App\Http\Controllers\Api\EducationController::class, 'markLessonViewed'])->whereNumber('id');

        // Admin — all routes require staff role
        Route::middleware('role:admin')->group(function () {
            Route::post('/impersonate/{user}', [ImpersonateController::class, 'impersonate']);
            Route::post('/impersonate/leave', [ImpersonateController::class, 'leave']);
        });

        Route::middleware(['role:admin,backoffice,support,finance,head,calculations,corrections', 'throttle:600,1'])->group(function () {
        Route::get('/admin/dashboard', [\App\Http\Controllers\Api\AdminDashboardController::class, 'index']);
        Route::get('/admin/export/{type}', [\App\Http\Controllers\Api\ExportController::class, 'export'])->middleware('throttle:10,1');
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy']);

        Route::get('/admin/partners', [\App\Http\Controllers\Api\AdminDataController::class, 'partners']);
        Route::post('/admin/partners', [\App\Http\Controllers\Api\AdminDataController::class, 'storePartner']);
        Route::post('/admin/partners/bulk', [\App\Http\Controllers\Api\AdminDataController::class, 'bulkPartners'])->middleware('throttle:10,1');
        Route::get('/admin/partners/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'showPartner'])->whereNumber('id');
        Route::put('/admin/partners/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'updatePartner'])->whereNumber('id');
        Route::post('/admin/partners/{id}/status', [\App\Http\Controllers\Api\AdminDataController::class, 'changePartnerStatus'])->whereNumber('id');
        Route::post('/admin/partners/{id}/status-override', [\App\Http\Controllers\Api\AdminDataController::class, 'overridePartnerStatus'])->whereNumber('id');
        Route::delete('/admin/partners/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'deletePartner'])->whereNumber('id');
        Route::get('/admin/partner-statuses', [\App\Http\Controllers\Api\AdminDataController::class, 'partnerStatuses']);
        Route::get('/admin/clients', [\App\Http\Controllers\Api\AdminDataController::class, 'clients']);
        Route::post('/admin/clients', [\App\Http\Controllers\Api\AdminDataController::class, 'storeClient']);
        Route::delete('/admin/clients/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'deleteClient'])->whereNumber('id');
        Route::get('/admin/requisites', [\App\Http\Controllers\Api\AdminDataController::class, 'requisites']);
        Route::post('/admin/requisites/bulk', [\App\Http\Controllers\Api\AdminDataController::class, 'bulkRequisites'])->middleware('throttle:10,1');
        Route::get('/admin/requisites/{id}/documents', [\App\Http\Controllers\Api\AdminDataController::class, 'requisiteDocuments'])->whereNumber('id');
        Route::get('/admin/requisites/{id}/partner', [\App\Http\Controllers\Api\AdminDataController::class, 'requisitePartner'])->whereNumber('id');
        Route::post('/admin/requisites/{id}/check-inn', [\App\Http\Controllers\Api\AdminDataController::class, 'checkRequisiteInn'])->whereNumber('id')->middleware('throttle:60,1');
        Route::post('/admin/requisites/{id}/verify', [\App\Http\Controllers\Api\AdminDataController::class, 'verifyRequisites']);
        Route::get('/admin/acceptance', [\App\Http\Controllers\Api\AdminDataController::class, 'acceptance']);
        Route::get('/admin/contracts', [\App\Http\Controllers\Api\AdminDataController::class, 'contracts']);
        Route::get('/admin/contracts/form-data', [\App\Http\Controllers\Api\AdminDataController::class, 'contractFormData']);
        Route::get('/admin/contracts/upload-history', fn () => response()->json([]));
        Route::post('/admin/contracts', [\App\Http\Controllers\Api\AdminDataController::class, 'storeContract']);
        Route::get('/admin/contracts/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'contractDetails'])->whereNumber('id');
        Route::put('/admin/contracts/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'updateContract'])->whereNumber('id');
        Route::delete('/admin/contracts/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'deleteContract'])->whereNumber('id');
        Route::get('/admin/contracts/{id}/history', [\App\Http\Controllers\Api\AdminDataController::class, 'contractHistory'])->whereNumber('id');

        // Shared import progress polling — frontend генерирует tracker id,
        // бэк пишет в cache перед каждой пачкой строк, фронт читает каждые
        // 500ms пока идёт POST-импорт. Не требует очереди Laravel.
        Route::get('/admin/import-progress', function (\Illuminate\Http\Request $r) {
            $tracker = $r->query('tracker');
            if (! $tracker) return response()->json(['total' => 0, 'processed' => 0]);
            $state = \Illuminate\Support\Facades\Cache::get("import:tracker:{$tracker}")
                ?? ['total' => 0, 'processed' => 0, 'success' => 0, 'errors' => 0, 'status' => 'starting'];
            return response()->json($state);
        });

        // Admin — Contracts import from Google Sheets
        Route::get('/admin/contract-import/sheet-names', [\App\Http\Controllers\Api\ContractImportController::class, 'sheetNames']);
        Route::get('/admin/contract-import/history', [\App\Http\Controllers\Api\ContractImportController::class, 'history']);
        Route::post('/admin/contract-import/from-sheets', [\App\Http\Controllers\Api\ContractImportController::class, 'importFromSheets'])->middleware('throttle:30,1');
        Route::post('/admin/contract-import/preview/from-sheets', [\App\Http\Controllers\Api\ContractImportController::class, 'previewFromSheets'])->middleware('throttle:30,1');
        Route::get('/admin/contract-import/preview/{sessionId}', [\App\Http\Controllers\Api\ContractImportController::class, 'previewList']);
        Route::patch('/admin/contract-import/preview/row/{id}', [\App\Http\Controllers\Api\ContractImportController::class, 'previewUpdate'])->whereNumber('id');
        Route::delete('/admin/contract-import/preview/row/{id}', [\App\Http\Controllers\Api\ContractImportController::class, 'previewDelete'])->whereNumber('id');
        Route::delete('/admin/contract-import/preview/{sessionId}', [\App\Http\Controllers\Api\ContractImportController::class, 'previewClear']);
        Route::post('/admin/contract-import/preview/{sessionId}/finalize', [\App\Http\Controllers\Api\ContractImportController::class, 'previewFinalize'])->middleware('throttle:30,1');
        Route::post('/admin/contract-import/{id}/rollback', [\App\Http\Controllers\Api\ContractImportController::class, 'rollback'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('/admin/transfers', [\App\Http\Controllers\Api\AdminDataController::class, 'transfers']);

        Route::get('/admin/transactions', [\App\Http\Controllers\Api\AdminFinanceController::class, 'transactions']);
        Route::post('/admin/finalize-month', [\App\Http\Controllers\Api\AdminFinanceController::class, 'finalizeMonth']);
        Route::get('/admin/commissions', [\App\Http\Controllers\Api\AdminFinanceController::class, 'commissions']);
        Route::get('/admin/commissions/chain/{transactionId}', [\App\Http\Controllers\Api\AdminFinanceController::class, 'commissionChain'])->whereNumber('transactionId');
        Route::get('/admin/pool', [\App\Http\Controllers\Api\AdminFinanceController::class, 'pool']);
        Route::get('/admin/qualifications', [\App\Http\Controllers\Api\AdminFinanceController::class, 'qualifications']);
        Route::get('/admin/qualifications/history/{id}', [\App\Http\Controllers\Api\AdminFinanceController::class, 'qualificationHistory'])->whereNumber('id');
        Route::get('/admin/charges', [\App\Http\Controllers\Api\AdminFinanceController::class, 'charges']);
        Route::post('/admin/charges', [\App\Http\Controllers\Api\AdminFinanceController::class, 'storeCharge']);
        Route::put('/admin/charges/{id}', [\App\Http\Controllers\Api\AdminFinanceController::class, 'updateCharge'])->whereNumber('id');
        Route::delete('/admin/charges/{id}', [\App\Http\Controllers\Api\AdminFinanceController::class, 'deleteCharge'])->whereNumber('id');
        Route::get('/admin/payments', [\App\Http\Controllers\Api\AdminFinanceController::class, 'payments']);
        Route::get('/admin/reports', [\App\Http\Controllers\Api\AdminFinanceController::class, 'reports']);
        Route::get('/admin/reports/archive', [\App\Http\Controllers\Api\AdminFinanceController::class, 'reportArchive']);
        Route::post('/admin/reports/generate', [\App\Http\Controllers\Api\AdminFinanceController::class, 'generateReport'])->middleware('throttle:30,1');
        Route::get('/admin/reports/{id}/download', [\App\Http\Controllers\Api\AdminFinanceController::class, 'downloadReport'])->whereNumber('id');
        Route::get('/admin/report-availability', [\App\Http\Controllers\Api\AdminFinanceController::class, 'reportAvailability']);
        Route::get('/admin/currencies', [\App\Http\Controllers\Api\AdminFinanceController::class, 'currencies']);
        Route::patch('/admin/currencies/rates/{id}', [\App\Http\Controllers\Api\AdminFinanceController::class, 'updateCurrencyRate'])->whereNumber('id');
        Route::post('/admin/currencies/vat', [\App\Http\Controllers\Api\AdminFinanceController::class, 'addVatRate']);
        Route::get('/admin/transaction-import/form-data', [\App\Http\Controllers\Api\TransactionImportController::class, 'formData']);
        Route::get('/admin/transaction-import/sheet-names', [\App\Http\Controllers\Api\TransactionImportController::class, 'sheetNames']);
        Route::post('/admin/transaction-import', [\App\Http\Controllers\Api\TransactionImportController::class, 'import'])->middleware('throttle:30,1');
        Route::post('/admin/transaction-import/from-sheets', [\App\Http\Controllers\Api\TransactionImportController::class, 'importFromSheets'])->middleware('throttle:30,1');

        // News CRUD (admin)
        Route::get('/admin/news', [\App\Http\Controllers\Api\WorkspaceController::class, 'newsList']);
        Route::post('/admin/news', [\App\Http\Controllers\Api\WorkspaceController::class, 'createNews']);
        Route::put('/admin/news/{id}', [\App\Http\Controllers\Api\WorkspaceController::class, 'updateNews']);
        Route::delete('/admin/news/{id}', [\App\Http\Controllers\Api\WorkspaceController::class, 'deleteNews']);
        Route::get('/admin/transaction-import/history', [\App\Http\Controllers\Api\TransactionImportController::class, 'history']);
        Route::post('/admin/transaction-import/{id}/rollback', [\App\Http\Controllers\Api\TransactionImportController::class, 'rollback']);
        Route::post('/admin/transaction-import/{id}/calculate', [\App\Http\Controllers\Api\TransactionImportController::class, 'calculateCommissions']);
        Route::post('/admin/transactions/{id}/calculate', [\App\Http\Controllers\Api\TransactionImportController::class, 'calculateSingle']);

        // Admin — Manual transaction entry (✅Транзакции.md)
        Route::get('/admin/manual-tx/contracts', [\App\Http\Controllers\Api\ManualTransactionController::class, 'searchContracts']);
        Route::get('/admin/manual-tx/lookups', [\App\Http\Controllers\Api\ManualTransactionController::class, 'suppliersAndProviders']);
        Route::post('/admin/manual-tx/drafts', [\App\Http\Controllers\Api\ManualTransactionController::class, 'createDrafts']);
        Route::get('/admin/manual-tx/drafts', [\App\Http\Controllers\Api\ManualTransactionController::class, 'listDrafts']);
        Route::patch('/admin/manual-tx/drafts/{id}', [\App\Http\Controllers\Api\ManualTransactionController::class, 'updateDraft'])->whereNumber('id');
        Route::delete('/admin/manual-tx/drafts/{id}', [\App\Http\Controllers\Api\ManualTransactionController::class, 'deleteDraft'])->whereNumber('id');
        Route::delete('/admin/manual-tx/drafts', [\App\Http\Controllers\Api\ManualTransactionController::class, 'clearDrafts']);
        Route::post('/admin/manual-tx/calc', [\App\Http\Controllers\Api\ManualTransactionController::class, 'calculateDrafts']);
        Route::post('/admin/manual-tx/fix', [\App\Http\Controllers\Api\ManualTransactionController::class, 'fixDrafts']);
        Route::get('/admin/manual-tx/products/{id}/rates', [\App\Http\Controllers\Api\ManualTransactionController::class, 'productRates'])->whereNumber('id');

        // Admin Monitoring (site status, error feed, queue control)
        Route::get('/admin/monitoring/status', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'status']);
        Route::get('/admin/monitoring/errors', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'errors']);
        Route::post('/admin/monitoring/jobs/{id}/retry', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'retryJob']);
        Route::delete('/admin/monitoring/jobs/{id}', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'forgetJob']);
        Route::post('/admin/monitoring/jobs/flush', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'flushJobs']);

        // Admin Mail (SMTP settings, broadcast, templates, send log)
        Route::get('/admin/mail/settings', [\App\Http\Controllers\Api\AdminMailController::class, 'settings']);
        Route::put('/admin/mail/settings', [\App\Http\Controllers\Api\AdminMailController::class, 'updateSettings']);
        Route::post('/admin/mail/test', [\App\Http\Controllers\Api\AdminMailController::class, 'test']);
        Route::post('/admin/mail/broadcast', [\App\Http\Controllers\Api\AdminMailController::class, 'broadcast'])->middleware('throttle:10,1');
        Route::get('/admin/mail/broadcast/{id}/progress', [\App\Http\Controllers\Api\AdminMailController::class, 'broadcastProgress']);
        Route::post('/admin/mail/audience-preview', [\App\Http\Controllers\Api\AdminMailController::class, 'audiencePreview']);
        Route::get('/admin/mail/log', [\App\Http\Controllers\Api\AdminMailController::class, 'log']);
        Route::get('/admin/mail/templates', [\App\Http\Controllers\Api\AdminMailController::class, 'templates']);
        Route::post('/admin/mail/templates', [\App\Http\Controllers\Api\AdminMailController::class, 'storeTemplate']);
        Route::put('/admin/mail/templates/{id}', [\App\Http\Controllers\Api\AdminMailController::class, 'updateTemplate']);
        Route::delete('/admin/mail/templates/{id}', [\App\Http\Controllers\Api\AdminMailController::class, 'destroyTemplate']);

        // Admin — Monthly finalisation (detachment / OP penalties on commissions)
        Route::post('/admin/finalize/preview', [\App\Http\Controllers\Api\AdminFinalizeController::class, 'preview']);
        Route::post('/admin/finalize/apply', [\App\Http\Controllers\Api\AdminFinalizeController::class, 'apply'])->middleware('throttle:5,1');

        // Admin — Pool (leader pool calc with manual «Участвует» moderation)
        Route::get('/admin/pool/participants', [\App\Http\Controllers\Api\AdminPoolController::class, 'participants']);
        Route::put('/admin/pool/participants', [\App\Http\Controllers\Api\AdminPoolController::class, 'toggleParticipant']);
        Route::post('/admin/pool/preview', [\App\Http\Controllers\Api\AdminPoolController::class, 'preview']);
        Route::post('/admin/pool/apply', [\App\Http\Controllers\Api\AdminPoolController::class, 'apply'])->middleware('throttle:10,1');

        // Admin — Analytics (reconciliation, anomalies, funnel, cohorts, owner)
        Route::get('/admin/analytics/reconciliation', [\App\Http\Controllers\Api\AdminAnalyticsController::class, 'reconciliation']);
        Route::get('/admin/analytics/anomalies', [\App\Http\Controllers\Api\AdminAnalyticsController::class, 'anomalies']);
        Route::get('/admin/analytics/funnel', [\App\Http\Controllers\Api\AdminAnalyticsController::class, 'funnel']);
        Route::get('/admin/analytics/cohorts', [\App\Http\Controllers\Api\AdminAnalyticsController::class, 'cohorts']);
        Route::get('/admin/analytics/owner-dashboard', [\App\Http\Controllers\Api\AdminAnalyticsController::class, 'ownerDashboard']);

        // Подбор валют для селекторов (4 рабочие: RUB/USD/EUR/GBP).
        Route::get('/currencies/selectable', function () {
            return response()->json([
                'items' => \Illuminate\Support\Facades\DB::table('currency')
                    ->where('selectable', true)
                    ->orderBy('id')
                    ->get(['id', 'nameRu', 'nameEn', 'symbol'])
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->nameRu,
                        'nameEn' => $c->nameEn,
                        'symbol' => $c->symbol,
                        'label' => $c->symbol ? "{$c->nameRu} ({$c->symbol})" : $c->nameRu,
                    ]),
            ]);
        });

        // Admin — API settings (Google Sheets key, Telegram bot, etc.)
        Route::get('/admin/api-settings', [\App\Http\Controllers\Api\AdminApiSettingsController::class, 'index']);
        Route::put('/admin/api-settings', [\App\Http\Controllers\Api\AdminApiSettingsController::class, 'update']);
        Route::post('/admin/api-settings/telegram-test', [\App\Http\Controllers\Api\AdminApiSettingsController::class, 'testTelegram'])->middleware('throttle:20,1');

        // Admin — Ops tools
        Route::get('/admin/ops/calendar', [\App\Http\Controllers\Api\AdminOpsController::class, 'calendar']);
        Route::get('/admin/ops/bulk', [\App\Http\Controllers\Api\AdminOpsController::class, 'bulkList']);
        Route::post('/admin/ops/bulk/{action}', [\App\Http\Controllers\Api\AdminOpsController::class, 'bulkRun'])->middleware('throttle:10,1');
        Route::get('/admin/ops/triggers', [\App\Http\Controllers\Api\AdminOpsController::class, 'triggers']);
        Route::get('/admin/ops/integrations', [\App\Http\Controllers\Api\AdminOpsController::class, 'integrations']);
        Route::get('/admin/ops/settings', [\App\Http\Controllers\Api\AdminOpsController::class, 'settingsShow']);

        // Admin — Payment registry (spec ✅Реестр выплат.md)
        Route::get('/admin/payment-registry', [\App\Http\Controllers\Api\AdminPaymentRegistryController::class, 'index']);
        Route::get('/admin/payment-registry/{id}/requisites', [\App\Http\Controllers\Api\AdminPaymentRegistryController::class, 'requisites'])->whereNumber('id');
        Route::post('/admin/payment-registry/{id}/payments', [\App\Http\Controllers\Api\AdminPaymentRegistryController::class, 'addPayment'])->whereNumber('id');

        // Admin — Period freeze (close/reopen reporting months)
        Route::get('/admin/periods', [\App\Http\Controllers\Api\AdminPeriodController::class, 'index']);
        Route::post('/admin/periods/close', [\App\Http\Controllers\Api\AdminPeriodController::class, 'close'])->middleware('throttle:10,1');
        Route::post('/admin/periods/reopen', [\App\Http\Controllers\Api\AdminPeriodController::class, 'reopen'])->middleware('throttle:10,1');
        Route::get('/admin/periods/{year}/{month}', [\App\Http\Controllers\Api\AdminPeriodController::class, 'check'])->whereNumber(['year', 'month']);

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
        Route::post('/admin/products/{id}/toggle-publish', [\App\Http\Controllers\Api\AdminProductController::class, 'togglePublish'])->whereNumber('id');
        Route::get('/admin/products/{id}/programs', [\App\Http\Controllers\Api\AdminProductController::class, 'programs']);
        Route::post('/admin/products/{id}/programs', [\App\Http\Controllers\Api\AdminProductController::class, 'storeProgram']);
        Route::put('/admin/products/{id}/programs/{programId}', [\App\Http\Controllers\Api\AdminProductController::class, 'updateProgram']);
        Route::delete('/admin/products/{id}/programs/{programId}', [\App\Http\Controllers\Api\AdminProductController::class, 'destroyProgram']);

        // Admin Education CRUD
        Route::get('/admin/instructions', [\App\Http\Controllers\Api\InstructionController::class, 'adminList']);
        Route::post('/admin/instructions', [\App\Http\Controllers\Api\InstructionController::class, 'adminStore']);
        Route::put('/admin/instructions/{id}', [\App\Http\Controllers\Api\InstructionController::class, 'adminUpdate'])->whereNumber('id');
        Route::delete('/admin/instructions/{id}', [\App\Http\Controllers\Api\InstructionController::class, 'adminDestroy'])->whereNumber('id');

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
