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
    // Health check — публичный, без Sanctum (чтобы можно было пинговать
    // даже если БД/auth сломан). Используется uptime-monitoring.
    Route::get('/health', [\App\Http\Controllers\Api\HealthController::class, 'check']);

    // Публичный роадмап — без auth, читается /roadmap-страницей.
    Route::get('/roadmap', [\App\Http\Controllers\Api\RoadmapController::class, 'publicIndex']);

    // Auth routes with rate limiting (5 attempts per minute)
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/register', [AuthController::class, 'register']);
        // Восстановление пароля: ссылка на email + сброс по токену.
        // Password broker внутри сам троттлит повторные отправки
        // (300с / 5 мин между письмами на один email — config/auth.php).
        Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    });
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/auth/check-duplicates', [AuthController::class, 'checkDuplicates']);
        Route::post('/auth/check-referral', [AuthController::class, 'checkReferral']);
    });

    // Telegram webhook — без auth, валидация через
    // X-Telegram-Bot-Api-Secret-Token (config services.telegram.webhook_secret).
    Route::post('/webhooks/telegram', [\App\Http\Controllers\Api\TelegramWebhookController::class, 'handle']);

    // Insmart webhook — без auth:sanctum (внешний источник),
    // защищён shared-secret в заголовке X-Insmart-Secret + throttle.
    Route::middleware('throttle:60,1')->group(function () {
        // Insmart-вебхук (per spec ✅Инсмарт.md). Авторизация —
        // HMAC X-Insmart-Signature (или fallback X-Insmart-Secret).
        // Сервис пишет person+client+contract+transaction в одной транзакции,
        // id для product/program/contract/transaction берутся через LegacyId::next.
        Route::post('/webhooks/insmart/paid', [\App\Http\Controllers\Api\InsmartWebhookController::class, 'paid']);
        // Zammad-вебхук закомментирован — интеграция не используется
        // (по запросу 2026-05-12). Включить обратно — добавить
        // shared-secret через api_settings, раскомментировать.
        // Route::post('/webhooks/zammad', [\App\Http\Controllers\Api\ZammadWebhookController::class, 'handle']);
    });

    // 2FA verify — БЕЗ auth (это шаг 2 логина: юзер прошёл email+пароль,
    // получил challenge, теперь подтверждает TOTP-код). Throttled.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/2fa/verify', [\App\Http\Controllers\Api\TwoFactorController::class, 'verify']);
    });

    // Активный дизайн (логотип/палитры/CSS) — ПУБЛИЧНО: применяется SPA в
    // рантайме, в т.ч. на странице входа (до авторизации). Это только брендинг.
    Route::get('/design/active', [\App\Http\Controllers\Api\DesignController::class, 'active'])
        ->middleware('throttle:60,1');

    // Переопределения строк i18n — ПУБЛИЧНО (применяются SPA на старте).
    Route::get('/i18n/overrides', [\App\Http\Controllers\Api\TranslationController::class, 'overrides'])
        ->middleware('throttle:60,1');

    Route::middleware(['auth:sanctum', 'maintenance'])->group(function () {
        // Контент-страница по slug + активные фиче-флаги (доступны всем auth).
        Route::get('/page/{slug}', [\App\Http\Controllers\Api\ContentPageController::class, 'show']);
        Route::get('/features', [\App\Http\Controllers\Api\ContentPageController::class, 'features']);

        // 2FA setup/confirm/disable/status — под авторизацией.
        Route::get('/2fa/status', [\App\Http\Controllers\Api\TwoFactorController::class, 'status']);
        Route::post('/2fa/setup', [\App\Http\Controllers\Api\TwoFactorController::class, 'setup']);
        Route::post('/2fa/confirm', [\App\Http\Controllers\Api\TwoFactorController::class, 'confirm']);
        Route::post('/2fa/disable', [\App\Http\Controllers\Api\TwoFactorController::class, 'disable']);

        // Кастомные поля текущего пользователя (заполнение в профиле).
        Route::get('/custom-fields', [\App\Http\Controllers\Api\CustomFieldController::class, 'index']);
        Route::put('/custom-fields/values', [\App\Http\Controllers\Api\CustomFieldController::class, 'updateValues']);

        // Активные объявления для баннера в шапке.
        Route::get('/announcements/active', [\App\Http\Controllers\Api\AnnouncementController::class, 'active']);

        // Глобальный поиск (Ctrl+K) — все auth.
        Route::get('/search', [\App\Http\Controllers\Api\SearchController::class, 'index']);

        // Telegram-привязка через бота.
        Route::get('/telegram/status', [\App\Http\Controllers\Api\TelegramController::class, 'status']);
        Route::post('/telegram/start-link', [\App\Http\Controllers\Api\TelegramController::class, 'startLink']);
        Route::get('/telegram/check-link', [\App\Http\Controllers\Api\TelegramController::class, 'checkLink']);
        Route::post('/telegram/unlink', [\App\Http\Controllers\Api\TelegramController::class, 'unlink']);
        Route::post('/telegram/test', [\App\Http\Controllers\Api\TelegramController::class, 'test']);

        // Аудит — только admin (проверка внутри).
        Route::get('/audit-log', [\App\Http\Controllers\Api\AuditLogController::class, 'index']);

        // Status page — read для всех auth, write только для admin (внутри контроллера).
        Route::get('/system-status', [\App\Http\Controllers\Api\SystemStatusController::class, 'index']);
        Route::post('/system-status/components', [\App\Http\Controllers\Api\SystemStatusController::class, 'storeComponent']);
        Route::put('/system-status/components/{id}', [\App\Http\Controllers\Api\SystemStatusController::class, 'updateComponent'])->whereNumber('id');
        Route::delete('/system-status/components/{id}', [\App\Http\Controllers\Api\SystemStatusController::class, 'destroyComponent'])->whereNumber('id');
        Route::post('/system-status/incidents', [\App\Http\Controllers\Api\SystemStatusController::class, 'storeIncident']);
        Route::put('/system-status/incidents/{id}', [\App\Http\Controllers\Api\SystemStatusController::class, 'updateIncident'])->whereNumber('id');
        Route::delete('/system-status/incidents/{id}', [\App\Http\Controllers\Api\SystemStatusController::class, 'destroyIncident'])->whereNumber('id');
        Route::post('/system-status/incidents/{id}/updates', [\App\Http\Controllers\Api\SystemStatusController::class, 'storeIncidentUpdate'])->whereNumber('id');

        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/auth/me/permissions', [AuthController::class, 'permissions']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/activate', [AuthController::class, 'activate']);

        Route::get('/workspace', [\App\Http\Controllers\Api\WorkspaceController::class, 'index']);

        // Личные виджеты Workspace: TODO-задачи и заметка-scratchpad.
        Route::get('/my-tasks', [\App\Http\Controllers\Api\UserDashboardController::class, 'listTasks']);
        Route::post('/my-tasks', [\App\Http\Controllers\Api\UserDashboardController::class, 'storeTask']);
        Route::put('/my-tasks/{id}', [\App\Http\Controllers\Api\UserDashboardController::class, 'updateTask'])->whereNumber('id');
        Route::delete('/my-tasks/{id}', [\App\Http\Controllers\Api\UserDashboardController::class, 'destroyTask'])->whereNumber('id');
        Route::get('/my-note', [\App\Http\Controllers\Api\UserDashboardController::class, 'getNote']);
        Route::put('/my-note', [\App\Http\Controllers\Api\UserDashboardController::class, 'saveNote']);

        // Presence для «Кто онлайн» + метрики «Мой день».
        Route::put('/me/heartbeat', [\App\Http\Controllers\Api\UserDashboardController::class, 'heartbeat']);
        Route::get('/staff/online', [\App\Http\Controllers\Api\UserDashboardController::class, 'whoOnline']);
        Route::get('/my-day', [\App\Http\Controllers\Api\UserDashboardController::class, 'myDay']);

        // Per spec ✅Написать собственику — отправка в Telegram-группу
        Route::post('/founder-message', [\App\Http\Controllers\Api\FounderMessageController::class, 'send'])
            ->middleware('throttle:5,1'); // антиспам: 5 сообщений в минуту с пользователя
        Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);

        // Chat system v2
        Route::get('/chat/tickets', [\App\Http\Controllers\Api\ChatController::class, 'index']);
        Route::post('/chat/tickets', [\App\Http\Controllers\Api\ChatController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/chat/tickets/stats', [\App\Http\Controllers\Api\ChatController::class, 'stats']);
        Route::get('/chat/unread-count', [\App\Http\Controllers\Api\ChatController::class, 'unreadCount']);
        Route::get('/chat/tickets/staff', [\App\Http\Controllers\Api\ChatController::class, 'staffList']);
        Route::get('/chat/tickets/{id}', [\App\Http\Controllers\Api\ChatController::class, 'show']);
        Route::delete('/chat/tickets/{id}', [\App\Http\Controllers\Api\ChatController::class, 'destroy'])->whereNumber('id');
        Route::get('/chat/tickets/{id}/can-access', [\App\Http\Controllers\Api\ChatController::class, 'canAccess']);
        Route::get('/chat/tickets/{id}/changes', [\App\Http\Controllers\Api\ChatController::class, 'changes']);
        Route::get('/chat/tickets/{id}/partner-context', [\App\Http\Controllers\Api\ChatController::class, 'partnerContext']);
        Route::post('/chat/tickets/{id}/messages', [\App\Http\Controllers\Api\ChatController::class, 'sendMessage'])->middleware('throttle:60,1');
        Route::put('/chat/messages/{messageId}', [\App\Http\Controllers\Api\ChatController::class, 'editMessage'])->middleware('throttle:30,1');
        Route::post('/chat/messages/{messageId}/reactions', [\App\Http\Controllers\Api\ChatController::class, 'toggleReaction'])->middleware('throttle:60,1');
        // Attachment route вынесен из auth:sanctum в публичный signed-блок
        // ниже — иначе при клике по ссылке (открытие в новой вкладке)
        // браузер не передаёт Bearer и получает 401 Unauthenticated.
        Route::post('/chat/tickets/{id}/pin', [\App\Http\Controllers\Api\ChatController::class, 'togglePin']);
        Route::post('/chat/tickets/{id}/status', [\App\Http\Controllers\Api\ChatController::class, 'updateStatus']);
        Route::post('/chat/tickets/{id}/subject', [\App\Http\Controllers\Api\ChatController::class, 'updateSubject']);
        Route::post('/chat/tickets/{id}/assign', [\App\Http\Controllers\Api\ChatController::class, 'assign']);
        Route::post('/chat/tickets/{id}/csat', [\App\Http\Controllers\Api\ChatController::class, 'submitCsat'])->middleware('throttle:5,1');
        Route::get('/chat/tickets/{id}/notes', [\App\Http\Controllers\Api\ChatController::class, 'notes']);
        Route::post('/chat/tickets/{id}/notes', [\App\Http\Controllers\Api\ChatController::class, 'addNote']);
        // Дополнительные участники чата (сотрудники и ФК-партнёры)
        Route::get('/chat/partner-lookup', [\App\Http\Controllers\Api\ChatController::class, 'partnerLookup']);
        Route::get('/chat/tickets/{id}/participants', [\App\Http\Controllers\Api\ChatController::class, 'listParticipants'])->whereNumber('id');
        Route::post('/chat/tickets/{id}/participants', [\App\Http\Controllers\Api\ChatController::class, 'addParticipant'])->whereNumber('id');
        Route::delete('/chat/tickets/{id}/participants/{userId}', [\App\Http\Controllers\Api\ChatController::class, 'removeParticipant'])->whereNumber('id')->whereNumber('userId');
        Route::get('/chat/quick-replies', [\App\Http\Controllers\Api\ChatController::class, 'quickReplies']);
        Route::post('/chat/quick-replies', [\App\Http\Controllers\Api\ChatController::class, 'storeQuickReply']);
        Route::put('/chat/quick-replies/{id}', [\App\Http\Controllers\Api\ChatController::class, 'updateQuickReply'])->whereNumber('id');
        Route::delete('/chat/quick-replies/{id}', [\App\Http\Controllers\Api\ChatController::class, 'destroyQuickReply'])->whereNumber('id');
        Route::get('/chat/knowledge', [\App\Http\Controllers\Api\ChatController::class, 'knowledgeArticles']);
        Route::get('/chat/tickets/{id}/knowledge-suggest', [\App\Http\Controllers\Api\ChatController::class, 'knowledgeSuggest']);
        Route::post('/chat/tickets/{id}/save-to-kb', [\App\Http\Controllers\Api\ChatController::class, 'saveTicketAsArticle']);
        Route::get('/chat/analytics', [\App\Http\Controllers\Api\ChatController::class, 'analytics']);
        Route::get('/chat/my-open', [\App\Http\Controllers\Api\ChatController::class, 'myOpenTickets']);
        // Инциденты + рабочий стол техподдержки
        Route::post('/chat/tickets/{id}/incident', [\App\Http\Controllers\Api\ChatController::class, 'markIncident'])->whereNumber('id');
        Route::post('/chat/tickets/{id}/incident/resolve', [\App\Http\Controllers\Api\ChatController::class, 'resolveIncident'])->whereNumber('id');
        Route::get('/support/desk', [\App\Http\Controllers\Api\ChatController::class, 'supportDesk']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/status-levels', [DashboardController::class, 'statusLevels']);

        Route::get('/clients', [ClientController::class, 'index']);

        // Комментарии к карточке партнёра (Structure.vue)
        Route::get('/partner-comments/{consultantId}', [\App\Http\Controllers\Api\PartnerCommentsController::class, 'index'])->whereNumber('consultantId');
        Route::post('/partner-comments', [\App\Http\Controllers\Api\PartnerCommentsController::class, 'store']);
        Route::delete('/partner-comments/{id}', [\App\Http\Controllers\Api\PartnerCommentsController::class, 'destroy'])->whereNumber('id');

        Route::get('/contracts/my', [ContractController::class, 'myContracts']);
        Route::get('/contracts/team', [ContractController::class, 'teamContracts']);
        Route::get('/contracts/team/{id}/chain', [ContractController::class, 'teamConsultantChain'])->whereNumber('id');
        Route::get('/contracts/statuses', [ContractController::class, 'statuses']);
        Route::get('/contracts/products', [ContractController::class, 'products']);
        Route::get('/contracts/programs', [ContractController::class, 'programs']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/questionnaire', [ProfileController::class, 'saveQuestionnaire']);
        Route::post('/profile/password', [ProfileController::class, 'changePassword']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::put('/profile/requisites', [ProfileController::class, 'updateRequisites']);
        Route::put('/profile/bank-requisites', [ProfileController::class, 'updateBankRequisites']);
        Route::get('/profile/agreement-documents', [ProfileController::class, 'agreementDocuments']);
        Route::post('/profile/accept-offer', [ProfileController::class, 'acceptOffer']);
        Route::get('/profile/cities', [ProfileController::class, 'cities']);
        Route::get('/profile/countries', [ProfileController::class, 'countries']);

        Route::get('/structure', [StructureController::class, 'index']);
        Route::get('/structure/export', [StructureController::class, 'exportFiltered']);
        Route::get('/structure/{consultantId}/children', [StructureController::class, 'children']);
        Route::get('/structure/{consultantId}/export', [StructureController::class, 'exportSubtree'])->whereNumber('consultantId');
        Route::get('/structure/qualification-levels', [StructureController::class, 'qualificationLevels']);
        Route::get('/structure/activity-statuses', [StructureController::class, 'activityStatuses']);
        Route::get('/structure/cities', [StructureController::class, 'cities']);

        Route::get('/my-payments', [\App\Http\Controllers\Api\MyPaymentsController::class, 'index']);

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
        Route::post('/requisites/check-inn', [ProductController::class, 'checkInn']);
        Route::post('/requisites', [ProductController::class, 'setupRequisites']);

        // InSmart-виджет: партнёр получает временный токен для встраивания.
        Route::get('/insmart/widget-token', [\App\Http\Controllers\Api\InsmartController::class, 'widgetToken']);
        Route::get('/contests', [ContestController::class, 'index']);
        Route::get('/instructions', [\App\Http\Controllers\Api\InstructionController::class, 'partnerList']);
        Route::get('/instructions/{slug}', [\App\Http\Controllers\Api\InstructionController::class, 'show']);
        Route::get('/education/courses', [\App\Http\Controllers\Api\EducationController::class, 'courses']);
        Route::get('/education/courses/{id}', [\App\Http\Controllers\Api\EducationController::class, 'show'])->whereNumber('id');
        Route::post('/education/courses/{id}/test', [\App\Http\Controllers\Api\EducationController::class, 'submitTest'])->whereNumber('id');
        Route::post('/education/lessons/{id}/view', [\App\Http\Controllers\Api\EducationController::class, 'markLessonViewed'])->whereNumber('id');
        // Домашние задания (партнёр)
        Route::post('/education/upload', [\App\Http\Controllers\Api\EducationUploadController::class, 'upload'])->middleware('throttle:30,1');
        Route::post('/education/lessons/{id}/homework', [\App\Http\Controllers\Api\HomeworkController::class, 'submit'])->whereNumber('id');
        Route::get('/education/homework/my', [\App\Http\Controllers\Api\HomeworkController::class, 'my']);
        // Сертификат курса (HTML с print-стилями → PDF через Ctrl+P)
        Route::get('/education/courses/{id}/certificate', [\App\Http\Controllers\Api\CertificateController::class, 'show'])->whereNumber('id');
        // LMS этап 1 (per ТЗ Жосан 25.05.2026): рекурсивное дерево
        // курсов + конструктор-body + база знаний + поиск.
        Route::get('/education/tree', [\App\Http\Controllers\Api\EducationController::class, 'tree']);
        Route::get('/education/courses/{id}/full', [\App\Http\Controllers\Api\EducationController::class, 'courseFull'])->whereNumber('id');
        Route::get('/education/search', [\App\Http\Controllers\Api\EducationController::class, 'search']);
        Route::get('/education/kb', [\App\Http\Controllers\Api\EducationController::class, 'kbTree']);
        Route::get('/education/kb/sections/{id}', [\App\Http\Controllers\Api\EducationController::class, 'kbSection'])->whereNumber('id');
        Route::get('/education/kb/articles/{id}', [\App\Http\Controllers\Api\EducationController::class, 'kbArticle'])->whereNumber('id');

        // Admin — all routes require staff role
        Route::middleware('role:admin')->group(function () {
            Route::post('/impersonate/{user}', [ImpersonateController::class, 'impersonate']);
            Route::post('/impersonate/leave', [ImpersonateController::class, 'leave']);

            // Раздел «Настройки» (system_settings) — только admin.
            Route::get('/admin/settings', [\App\Http\Controllers\Api\AdminSettingsController::class, 'index']);
            Route::put('/admin/settings', [\App\Http\Controllers\Api\AdminSettingsController::class, 'update']);

            // Раздел «Дизайн» (шаблоны логотипа/палитр/CSS) — только admin.
            Route::get('/admin/design/themes', [\App\Http\Controllers\Api\AdminDesignController::class, 'index']);
            Route::post('/admin/design/themes', [\App\Http\Controllers\Api\AdminDesignController::class, 'store']);
            Route::put('/admin/design/themes/{id}', [\App\Http\Controllers\Api\AdminDesignController::class, 'update'])->whereNumber('id');
            Route::delete('/admin/design/themes/{id}', [\App\Http\Controllers\Api\AdminDesignController::class, 'destroy'])->whereNumber('id');
            Route::post('/admin/design/themes/{id}/activate', [\App\Http\Controllers\Api\AdminDesignController::class, 'activate'])->whereNumber('id');
            Route::post('/admin/design/upload', [\App\Http\Controllers\Api\AdminDesignController::class, 'upload'])->middleware('throttle:30,1');

            // Кастомные поля пользователей (определения) — только admin.
            Route::get('/admin/custom-fields', [\App\Http\Controllers\Api\AdminCustomFieldController::class, 'index']);
            Route::post('/admin/custom-fields', [\App\Http\Controllers\Api\AdminCustomFieldController::class, 'store']);
            Route::put('/admin/custom-fields/{id}', [\App\Http\Controllers\Api\AdminCustomFieldController::class, 'update'])->whereNumber('id');
            Route::delete('/admin/custom-fields/{id}', [\App\Http\Controllers\Api\AdminCustomFieldController::class, 'destroy'])->whereNumber('id');
            // Значения кастомных полей конкретного пользователя (по WebUser.id).
            Route::get('/admin/users/{userId}/custom-fields', [\App\Http\Controllers\Api\AdminCustomFieldController::class, 'userFields'])->whereNumber('userId');
            Route::put('/admin/users/{userId}/custom-fields', [\App\Http\Controllers\Api\AdminCustomFieldController::class, 'saveUserValues'])->whereNumber('userId');
            // Обязательность стандартных полей профиля.
            Route::get('/admin/builtin-fields', [\App\Http\Controllers\Api\AdminCustomFieldController::class, 'builtinFields']);
            Route::put('/admin/builtin-fields', [\App\Http\Controllers\Api\AdminCustomFieldController::class, 'saveBuiltin']);

            // Системные объявления (баннеры).
            Route::get('/admin/announcements', [\App\Http\Controllers\Api\AdminAnnouncementController::class, 'index']);
            Route::post('/admin/announcements', [\App\Http\Controllers\Api\AdminAnnouncementController::class, 'store']);
            Route::put('/admin/announcements/{id}', [\App\Http\Controllers\Api\AdminAnnouncementController::class, 'update'])->whereNumber('id');
            Route::delete('/admin/announcements/{id}', [\App\Http\Controllers\Api\AdminAnnouncementController::class, 'destroy'])->whereNumber('id');

            // Фиче-флаги.
            Route::get('/admin/feature-flags', [\App\Http\Controllers\Api\AdminFeatureFlagController::class, 'index']);
            Route::post('/admin/feature-flags', [\App\Http\Controllers\Api\AdminFeatureFlagController::class, 'store']);
            Route::put('/admin/feature-flags/{id}', [\App\Http\Controllers\Api\AdminFeatureFlagController::class, 'update'])->whereNumber('id');
            Route::delete('/admin/feature-flags/{id}', [\App\Http\Controllers\Api\AdminFeatureFlagController::class, 'destroy'])->whereNumber('id');

            // Контент-страницы.
            Route::get('/admin/content-pages', [\App\Http\Controllers\Api\AdminContentPageController::class, 'index']);
            Route::post('/admin/content-pages', [\App\Http\Controllers\Api\AdminContentPageController::class, 'store']);
            Route::put('/admin/content-pages/{id}', [\App\Http\Controllers\Api\AdminContentPageController::class, 'update'])->whereNumber('id');
            Route::delete('/admin/content-pages/{id}', [\App\Http\Controllers\Api\AdminContentPageController::class, 'destroy'])->whereNumber('id');

            // Рассылка in-app уведомлений (всем / по ролям).
            Route::post('/admin/notifications/broadcast', [\App\Http\Controllers\Api\NotificationController::class, 'broadcast'])->middleware('throttle:20,1');

            // Аудит-лог (просмотр всех действий).
            Route::get('/admin/audit-log', [\App\Http\Controllers\Api\AdminAuditController::class, 'index']);

            // i18n-переопределения.
            Route::get('/admin/translations', [\App\Http\Controllers\Api\AdminTranslationController::class, 'index']);
            Route::post('/admin/translations', [\App\Http\Controllers\Api\AdminTranslationController::class, 'store']);
            Route::delete('/admin/translations/{id}', [\App\Http\Controllers\Api\AdminTranslationController::class, 'destroy'])->whereNumber('id');

            // Система: кэш и планировщик.
            Route::post('/admin/ops/cache/clear', [\App\Http\Controllers\Api\AdminOpsController::class, 'clearCache'])->middleware('throttle:30,1');
            Route::get('/admin/ops/scheduled', [\App\Http\Controllers\Api\AdminOpsController::class, 'scheduledTasks']);

            // Медиа-библиотека.
            Route::get('/admin/media', [\App\Http\Controllers\Api\AdminMediaController::class, 'index']);
            Route::post('/admin/media', [\App\Http\Controllers\Api\AdminMediaController::class, 'upload'])->middleware('throttle:60,1');
            Route::delete('/admin/media', [\App\Http\Controllers\Api\AdminMediaController::class, 'destroy']);
        });

        Route::middleware(['role:admin,backoffice,support,finance,head,calculations,corrections,education', 'restrict.education', 'restrict.head', 'restrict.support', 'restrict.corrections', 'throttle:2400,1'])->group(function () {
        Route::get('/admin/dashboard', [\App\Http\Controllers\Api\AdminDashboardController::class, 'index']);
        Route::get('/admin/export/{type}', [\App\Http\Controllers\Api\ExportController::class, 'export'])->middleware('throttle:10,1');
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::put('/admin/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy']);
        Route::get('/admin/users/{id}/login-history', [AdminUserController::class, 'loginHistory'])->whereNumber('id');
        Route::get('/admin/login-log', [AdminUserController::class, 'loginLog']);

        // Сегменты партнёров (сохранённые фильтры) — доступны staff-страницам.
        Route::get('/admin/user-segments', [\App\Http\Controllers\Api\AdminUserSegmentController::class, 'index']);
        Route::post('/admin/user-segments', [\App\Http\Controllers\Api\AdminUserSegmentController::class, 'store']);
        Route::delete('/admin/user-segments/{id}', [\App\Http\Controllers\Api\AdminUserSegmentController::class, 'destroy'])->whereNumber('id');

        Route::get('/admin/partners', [\App\Http\Controllers\Api\AdminDataController::class, 'partners']);
        Route::get('/admin/partners/lookup', [\App\Http\Controllers\Api\AdminDataController::class, 'partnerLookup']);
        Route::post('/admin/partners', [\App\Http\Controllers\Api\AdminDataController::class, 'storePartner']);
        Route::post('/admin/partners/bulk', [\App\Http\Controllers\Api\AdminDataController::class, 'bulkPartners'])->middleware('throttle:10,1');
        Route::get('/admin/partners/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'showPartner'])->whereNumber('id');
        Route::put('/admin/partners/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'updatePartner'])->whereNumber('id');
        Route::post('/admin/partners/{id}/status', [\App\Http\Controllers\Api\AdminDataController::class, 'changePartnerStatus'])->whereNumber('id');
        Route::post('/admin/partners/{id}/status-override', [\App\Http\Controllers\Api\AdminDataController::class, 'overridePartnerStatus'])->whereNumber('id');
        Route::get('/admin/partners/{id}/status-history', [\App\Http\Controllers\Api\AdminDataController::class, 'partnerStatusHistory'])->whereNumber('id');
        Route::get('/admin/partners/{id}/change-log', [\App\Http\Controllers\Api\AdminDataController::class, 'partnerChangeLog'])->whereNumber('id');
        Route::delete('/admin/partners/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'deletePartner'])->whereNumber('id');
        Route::get('/admin/partner-statuses', [\App\Http\Controllers\Api\AdminDataController::class, 'partnerStatuses']);
        Route::get('/admin/clients', [\App\Http\Controllers\Api\AdminDataController::class, 'clients']);
        Route::get('/admin/clients/check-duplicates', [\App\Http\Controllers\Api\AdminDataController::class, 'checkClientDuplicates']);
        Route::get('/admin/consultants/{id}/chain', [\App\Http\Controllers\Api\AdminDataController::class, 'consultantChain'])->whereNumber('id');
        Route::post('/admin/clients', [\App\Http\Controllers\Api\AdminDataController::class, 'storeClient']);
        Route::put('/admin/clients/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'updateClient'])->whereNumber('id');
        Route::delete('/admin/clients/{id}', [\App\Http\Controllers\Api\AdminDataController::class, 'deleteClient'])->whereNumber('id');
        Route::get('/admin/requisites', [\App\Http\Controllers\Api\AdminDataController::class, 'requisites']);
        Route::post('/admin/requisites/bulk', [\App\Http\Controllers\Api\AdminDataController::class, 'bulkRequisites'])->middleware('throttle:10,1');
        Route::get('/admin/requisites/{id}/documents', [\App\Http\Controllers\Api\AdminDataController::class, 'requisiteDocuments'])->whereNumber('id');
        Route::get('/admin/requisites/{id}/partner', [\App\Http\Controllers\Api\AdminDataController::class, 'requisitePartner'])->whereNumber('id');
        Route::post('/admin/requisites/{id}/check-inn', [\App\Http\Controllers\Api\AdminDataController::class, 'checkRequisiteInn'])->whereNumber('id')->middleware('throttle:60,1');
        Route::post('/admin/requisites/{id}/verify', [\App\Http\Controllers\Api\AdminDataController::class, 'verifyRequisites']);
        Route::get('/admin/acceptance', [\App\Http\Controllers\Api\AdminDataController::class, 'acceptance']);
        Route::get('/admin/contracts', [\App\Http\Controllers\Api\AdminDataController::class, 'contracts']);
        Route::get('/admin/contracts/check-number', [\App\Http\Controllers\Api\AdminDataController::class, 'checkContractNumber']);
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
        Route::get('/admin/contract-import/form-data', [\App\Http\Controllers\Api\ContractImportController::class, 'formData']);
        Route::get('/admin/contract-import/client-search', [\App\Http\Controllers\Api\ContractImportController::class, 'clientSearch']);
        Route::get('/admin/contract-import/programs/{productId}', [\App\Http\Controllers\Api\ContractImportController::class, 'programsByProduct'])->whereNumber('productId');
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

        // Permission groups — управление правами кабинетов через UI
        Route::get('/admin/permissions/groups',          [\App\Http\Controllers\Api\AdminPermissionsController::class, 'index']);
        Route::post('/admin/permissions/groups',         [\App\Http\Controllers\Api\AdminPermissionsController::class, 'store']);
        Route::patch('/admin/permissions/groups/{id}',   [\App\Http\Controllers\Api\AdminPermissionsController::class, 'update'])->whereNumber('id');
        Route::delete('/admin/permissions/groups/{id}',  [\App\Http\Controllers\Api\AdminPermissionsController::class, 'destroy'])->whereNumber('id');

        Route::get('/admin/transactions', [\App\Http\Controllers\Api\AdminFinanceController::class, 'transactions']);
        Route::post('/admin/finalize-month', [\App\Http\Controllers\Api\AdminFinanceController::class, 'finalizeMonth'])->middleware('role:admin,calculations');
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
        Route::patch('/admin/currencies/rates/{id}', [\App\Http\Controllers\Api\AdminFinanceController::class, 'updateCurrencyRate'])->whereNumber('id')->middleware('role:admin,calculations');
        Route::post('/admin/currencies/vat', [\App\Http\Controllers\Api\AdminFinanceController::class, 'addVatRate']);
        // Второй справочник курсов — для отчётов руководителей (нет пересчёта транзакций)
        Route::get('/admin/currencies/management-rates', [\App\Http\Controllers\Api\AdminFinanceController::class, 'managementCurrencies']);
        Route::post('/admin/currencies/management-rates', [\App\Http\Controllers\Api\AdminFinanceController::class, 'storeManagementCurrencyRate'])->middleware('role:admin,calculations');
        Route::patch('/admin/currencies/management-rates/{id}', [\App\Http\Controllers\Api\AdminFinanceController::class, 'updateManagementCurrencyRate'])->whereNumber('id')->middleware('role:admin,calculations');
        Route::post('/admin/currencies/management-rates/copy-from-main', [\App\Http\Controllers\Api\AdminFinanceController::class, 'copyManagementRatesFromMain'])->middleware('role:admin,calculations');
        Route::get('/admin/transaction-import/form-data', [\App\Http\Controllers\Api\TransactionImportController::class, 'formData']);
        Route::get('/admin/transaction-import/sheet-names', [\App\Http\Controllers\Api\TransactionImportController::class, 'sheetNames']);
        Route::post('/admin/transaction-import', [\App\Http\Controllers\Api\TransactionImportController::class, 'import'])->middleware('throttle:30,1');
        Route::post('/admin/transaction-import/from-sheets', [\App\Http\Controllers\Api\TransactionImportController::class, 'importFromSheets'])->middleware('throttle:30,1');

        // News CRUD (admin)
        Route::get('/admin/news', [\App\Http\Controllers\Api\WorkspaceController::class, 'newsList']);
        Route::post('/admin/news', [\App\Http\Controllers\Api\WorkspaceController::class, 'createNews']);
        Route::put('/admin/news/{id}', [\App\Http\Controllers\Api\WorkspaceController::class, 'updateNews']);
        Route::delete('/admin/news/{id}', [\App\Http\Controllers\Api\WorkspaceController::class, 'deleteNews']);

        // Roadmap CRUD (admin) — публичный list лежит в начале файла без auth.
        Route::get('/admin/roadmap', [\App\Http\Controllers\Api\RoadmapController::class, 'adminIndex']);
        Route::post('/admin/roadmap', [\App\Http\Controllers\Api\RoadmapController::class, 'store']);
        Route::put('/admin/roadmap/{id}', [\App\Http\Controllers\Api\RoadmapController::class, 'update'])->whereNumber('id');
        Route::delete('/admin/roadmap/{id}', [\App\Http\Controllers\Api\RoadmapController::class, 'destroy'])->whereNumber('id');
        Route::get('/admin/transaction-import/history', [\App\Http\Controllers\Api\TransactionImportController::class, 'history']);
        Route::get('/admin/transaction-import/check-duplicate', [\App\Http\Controllers\Api\TransactionImportController::class, 'checkDuplicate']);
        Route::post('/admin/transaction-import/{id}/rollback', [\App\Http\Controllers\Api\TransactionImportController::class, 'rollback'])->middleware('role:admin,calculations');
        Route::post('/admin/transaction-import/{id}/calculate', [\App\Http\Controllers\Api\TransactionImportController::class, 'calculateCommissions'])->middleware('role:admin,calculations');
        Route::get('/admin/transaction-import/{id}/errors.csv', [\App\Http\Controllers\Api\TransactionImportController::class, 'errorsCsv'])->whereNumber('id');
        Route::post('/admin/transactions/{id}/calculate', [\App\Http\Controllers\Api\TransactionImportController::class, 'calculateSingle'])->middleware('role:admin,calculations');
        Route::put('/admin/transactions/{id}', [\App\Http\Controllers\Api\TransactionImportController::class, 'update'])->whereNumber('id')->middleware('role:admin,calculations');
        Route::delete('/admin/transactions/{id}', [\App\Http\Controllers\Api\TransactionImportController::class, 'destroy'])->whereNumber('id')->middleware('role:admin,calculations');

        // Admin — Manual transaction entry (✅Транзакции.md)
        Route::get('/admin/manual-tx/contracts', [\App\Http\Controllers\Api\ManualTransactionController::class, 'searchContracts']);
        Route::get('/admin/manual-tx/lookups', [\App\Http\Controllers\Api\ManualTransactionController::class, 'suppliersAndProviders']);
        Route::post('/admin/manual-tx/drafts', [\App\Http\Controllers\Api\ManualTransactionController::class, 'createDrafts']);
        Route::get('/admin/manual-tx/drafts', [\App\Http\Controllers\Api\ManualTransactionController::class, 'listDrafts']);
        Route::patch('/admin/manual-tx/drafts/{id}', [\App\Http\Controllers\Api\ManualTransactionController::class, 'updateDraft'])->whereNumber('id');
        Route::delete('/admin/manual-tx/drafts/{id}', [\App\Http\Controllers\Api\ManualTransactionController::class, 'deleteDraft'])->whereNumber('id');
        Route::post('/admin/manual-tx/drafts/{id}/duplicate', [\App\Http\Controllers\Api\ManualTransactionController::class, 'duplicateDraft'])->whereNumber('id');
        Route::delete('/admin/manual-tx/drafts', [\App\Http\Controllers\Api\ManualTransactionController::class, 'clearDrafts']);
        Route::post('/admin/manual-tx/calc', [\App\Http\Controllers\Api\ManualTransactionController::class, 'calculateDrafts'])->middleware('role:admin,calculations');
        Route::post('/admin/manual-tx/fix', [\App\Http\Controllers\Api\ManualTransactionController::class, 'fixDrafts'])->middleware('role:admin,calculations');
        Route::get('/admin/manual-tx/products/{id}/rates', [\App\Http\Controllers\Api\ManualTransactionController::class, 'productRates'])->whereNumber('id');

        // Admin Monitoring (site status, error feed, queue control)
        Route::get('/admin/monitoring/status', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'status']);
        Route::get('/admin/monitoring/activity', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'activity']);
        Route::get('/admin/monitoring/errors', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'errors']);
        Route::get('/admin/monitoring/log/errors', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'logErrors']);
        Route::get('/admin/monitoring/log/download', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'downloadLog']);
        Route::post('/admin/monitoring/log/clear', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'clearLog']);
        Route::post('/admin/monitoring/jobs/{id}/retry', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'retryJob']);
        Route::delete('/admin/monitoring/jobs/{id}', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'forgetJob']);
        Route::post('/admin/monitoring/jobs/flush', [\App\Http\Controllers\Api\AdminMonitoringController::class, 'flushJobs']);

        // Admin Mail (SMTP settings, broadcast, templates, send log)
        Route::get('/admin/mail/settings', [\App\Http\Controllers\Api\AdminMailController::class, 'settings']);
        Route::put('/admin/mail/settings', [\App\Http\Controllers\Api\AdminMailController::class, 'updateSettings']);
        // Множественные SMTP-ящики (system / маркетинг / поддержка / …).
        Route::get('/admin/mail/mailboxes', [\App\Http\Controllers\Api\AdminMailController::class, 'mailboxes']);
        Route::post('/admin/mail/mailboxes', [\App\Http\Controllers\Api\AdminMailController::class, 'storeMailbox']);
        Route::put('/admin/mail/mailboxes/{id}', [\App\Http\Controllers\Api\AdminMailController::class, 'updateMailbox'])->whereNumber('id');
        Route::delete('/admin/mail/mailboxes/{id}', [\App\Http\Controllers\Api\AdminMailController::class, 'destroyMailbox'])->whereNumber('id');
        Route::post('/admin/mail/mailboxes/{id}/default', [\App\Http\Controllers\Api\AdminMailController::class, 'setDefaultMailbox'])->whereNumber('id');
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
        Route::post('/admin/finalize/preview', [\App\Http\Controllers\Api\AdminFinalizeController::class, 'preview'])->middleware('role:admin,calculations');
        // throttle 30/мин — admin/calculations часто перезапускают расчёт
        // после ручных правок (Транзакции / Пул / Карточка периода).
        // 5/мин ловило ложные 429 при обычной работе финдиректора.
        Route::post('/admin/finalize/apply', [\App\Http\Controllers\Api\AdminFinalizeController::class, 'apply'])->middleware(['role:admin,calculations', 'throttle:30,1']);

        // Admin — Pool (leader pool calc with manual «Участвует» moderation)
        Route::get('/admin/pool/participants', [\App\Http\Controllers\Api\AdminPoolController::class, 'participants']);
        Route::put('/admin/pool/participants', [\App\Http\Controllers\Api\AdminPoolController::class, 'toggleParticipant'])->middleware('role:admin,calculations');
        Route::post('/admin/pool/preview', [\App\Http\Controllers\Api\AdminPoolController::class, 'preview'])->middleware('role:admin,calculations');
        Route::post('/admin/pool/apply', [\App\Http\Controllers\Api\AdminPoolController::class, 'apply'])->middleware(['role:admin,calculations', 'throttle:10,1']);
        Route::get('/admin/pool/progress', [\App\Http\Controllers\Api\AdminPoolController::class, 'progress']);
        Route::post('/admin/pool/reopen', [\App\Http\Controllers\Api\AdminPoolController::class, 'reopen'])->middleware(['role:admin,calculations', 'throttle:5,1']);

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

        // Полноценная панель «Интеграции»: метрики 24h, журнал, тесты, replay.
        Route::get('/admin/integrations', [\App\Http\Controllers\Api\AdminIntegrationsController::class, 'index']);
        Route::get('/admin/integrations/events', [\App\Http\Controllers\Api\AdminIntegrationsController::class, 'events']);
        Route::get('/admin/integrations/events/{id}', [\App\Http\Controllers\Api\AdminIntegrationsController::class, 'eventShow'])->whereNumber('id');
        Route::post('/admin/integrations/events/{id}/replay', [\App\Http\Controllers\Api\AdminIntegrationsController::class, 'replay'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('/admin/integrations/{service}/test', [\App\Http\Controllers\Api\AdminIntegrationsController::class, 'test'])->middleware('throttle:30,1')->where('service', '[a-z_]+');
        Route::get('/admin/integrations/{service}/config', [\App\Http\Controllers\Api\AdminIntegrationsController::class, 'config']);
        Route::put('/admin/integrations/{service}/config', [\App\Http\Controllers\Api\AdminIntegrationsController::class, 'saveConfig']);
        Route::get('/admin/ops/settings', [\App\Http\Controllers\Api\AdminOpsController::class, 'settingsShow']);

        // Admin — Reports: Product Sales Matrix (MVP admin-only)
        Route::get('/admin/reports/sales-matrix', [\App\Http\Controllers\Api\ProductSalesMatrixController::class, 'index']);
        Route::get('/admin/reports/sales-matrix/fc', [\App\Http\Controllers\Api\ProductSalesMatrixController::class, 'fcMatrix']);
        Route::get('/admin/reports/sales-matrix/period', [\App\Http\Controllers\Api\ProductSalesMatrixController::class, 'quarterlyMatrix']);
        Route::get('/admin/reports/sales-matrix/forecast', [\App\Http\Controllers\Api\ProductSalesMatrixController::class, 'forecastMatrix']);
        Route::get('/admin/reports/sales-matrix/fact', [\App\Http\Controllers\Api\ProductSalesMatrixController::class, 'factMatrix']);
        Route::get('/admin/reports/sales-matrix/monthly', [\App\Http\Controllers\Api\ProductSalesMatrixController::class, 'monthly']);

        // Admin — Payment registry (spec ✅Реестр выплат.md)
        Route::get('/admin/payment-registry', [\App\Http\Controllers\Api\AdminPaymentRegistryController::class, 'index']);
        Route::get('/admin/payment-registry/{id}/requisites', [\App\Http\Controllers\Api\AdminPaymentRegistryController::class, 'requisites'])->whereNumber('id');
        Route::get('/admin/payment-registry/{id}/payments', [\App\Http\Controllers\Api\AdminPaymentRegistryController::class, 'listPayments'])->whereNumber('id');
        Route::post('/admin/payment-registry/{id}/payments', [\App\Http\Controllers\Api\AdminPaymentRegistryController::class, 'addPayment'])->whereNumber('id');
        Route::patch('/admin/payment-registry/payments/{paymentId}', [\App\Http\Controllers\Api\AdminPaymentRegistryController::class, 'updatePayment'])->whereNumber('paymentId');
        Route::delete('/admin/payment-registry/payments/{paymentId}', [\App\Http\Controllers\Api\AdminPaymentRegistryController::class, 'deletePayment'])->whereNumber('paymentId');

        // Admin — Period freeze (close/reopen reporting months)
        Route::get('/admin/periods', [\App\Http\Controllers\Api\AdminPeriodController::class, 'index']);
        // Admin-only (role + canFull('reports-access')). Троттл здесь — анти-
        // runaway, не анти-пользователь: при работе с 24 периодами прежние
        // лимиты (10/30 в минуту) ловили 429 у легитимного финменеджера.
        Route::post('/admin/periods/close', [\App\Http\Controllers\Api\AdminPeriodController::class, 'close'])->middleware('throttle:60,1');
        Route::post('/admin/periods/reopen', [\App\Http\Controllers\Api\AdminPeriodController::class, 'reopen'])->middleware('throttle:60,1');
        Route::post('/admin/periods/visibility', [\App\Http\Controllers\Api\AdminPeriodController::class, 'setVisibility'])->middleware('throttle:120,1');
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
        Route::get('/admin/products/references', [\App\Http\Controllers\Api\AdminProductController::class, 'references']);
        Route::get('/admin/products', [\App\Http\Controllers\Api\AdminProductController::class, 'index']);
        Route::post('/admin/products', [\App\Http\Controllers\Api\AdminProductController::class, 'store']);
        Route::put('/admin/products/{id}', [\App\Http\Controllers\Api\AdminProductController::class, 'update']);
        Route::delete('/admin/products/{id}', [\App\Http\Controllers\Api\AdminProductController::class, 'destroy']);
        Route::post('/admin/products/{id}/toggle-publish', [\App\Http\Controllers\Api\AdminProductController::class, 'togglePublish'])->whereNumber('id');
        Route::post('/admin/products/{id}/image', [\App\Http\Controllers\Api\AdminProductController::class, 'uploadImage'])->whereNumber('id');
        Route::get('/admin/products/{id}/programs', [\App\Http\Controllers\Api\AdminProductController::class, 'programs']);
        Route::post('/admin/products/{id}/programs', [\App\Http\Controllers\Api\AdminProductController::class, 'storeProgram']);
        Route::put('/admin/products/{id}/programs/{programId}', [\App\Http\Controllers\Api\AdminProductController::class, 'updateProgram']);
        Route::delete('/admin/products/{id}/programs/{programId}', [\App\Http\Controllers\Api\AdminProductController::class, 'destroyProgram']);

        // Audit-driven catalog (products_catalog + programs_catalog) — drop-in
        // replacement for the legacy /admin/products endpoints used by
        // resources/js/pages/Admin/Products.vue. Same response shape (camelCase
        // keys, programCount, publishStatus, …) so the page needs no template
        // changes.
        Route::get('/admin/products-catalog/types',          [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'types']);
        Route::get('/admin/products-catalog/references',     [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'references']);
        Route::get('/admin/products-catalog',                [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'indexProducts']);
        Route::post('/admin/products-catalog',               [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'storeProduct']);
        Route::get('/admin/products-catalog/{id}',           [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'showProduct'])->whereNumber('id');
        Route::put('/admin/products-catalog/{id}',           [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'updateProduct'])->whereNumber('id');
        Route::delete('/admin/products-catalog/{id}',        [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'destroyProduct'])->whereNumber('id');
        Route::post('/admin/products-catalog/{id}/toggle-publish', [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'togglePublish'])->whereNumber('id');
        Route::post('/admin/products-catalog/{id}/image',    [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'uploadImage'])->whereNumber('id');
        Route::get('/admin/products-catalog/{id}/programs',  [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'programs'])->whereNumber('id');
        Route::post('/admin/products-catalog/{id}/programs', [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'storeProgram'])->whereNumber('id');
        Route::put('/admin/products-catalog/{id}/programs/{programId}',    [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'updateProgram'])->whereNumber('id')->whereNumber('programId');
        Route::delete('/admin/products-catalog/{id}/programs/{programId}', [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'destroyProgram'])->whereNumber('id')->whereNumber('programId');
        Route::get('/admin/programs-catalog/{id}',           [\App\Http\Controllers\Api\AdminProductCatalogController::class, 'showProgram'])->whereNumber('id');

        // Admin Education CRUD
        Route::get('/admin/instructions', [\App\Http\Controllers\Api\InstructionController::class, 'adminList']);
        Route::post('/admin/instructions', [\App\Http\Controllers\Api\InstructionController::class, 'adminStore']);
        Route::put('/admin/instructions/{id}', [\App\Http\Controllers\Api\InstructionController::class, 'adminUpdate'])->whereNumber('id');
        Route::delete('/admin/instructions/{id}', [\App\Http\Controllers\Api\InstructionController::class, 'adminDestroy'])->whereNumber('id');

        // Анкеты партнёров (для куратора обучения и общего ознакомления)
        Route::get('/admin/partners/questionnaires', [\App\Http\Controllers\Api\AdminQuestionnaireController::class, 'index']);
        Route::get('/admin/partners/questionnaires/export', [\App\Http\Controllers\Api\AdminQuestionnaireController::class, 'export']);
        Route::get('/admin/partners/{id}/questionnaire', [\App\Http\Controllers\Api\AdminQuestionnaireController::class, 'show'])->whereNumber('id');

        Route::get('/admin/education/analytics', [\App\Http\Controllers\Api\AdminEducationController::class, 'analytics']);
        Route::get('/admin/education/analytics/export', [\App\Http\Controllers\Api\AdminEducationController::class, 'analyticsExport']);
        Route::get('/admin/education/categories', [\App\Http\Controllers\Api\AdminEducationController::class, 'categories']);
        Route::post('/admin/education/categories', [\App\Http\Controllers\Api\AdminEducationController::class, 'storeCategory']);
        Route::put('/admin/education/categories/{id}', [\App\Http\Controllers\Api\AdminEducationController::class, 'updateCategory'])->whereNumber('id');
        Route::delete('/admin/education/categories/{id}', [\App\Http\Controllers\Api\AdminEducationController::class, 'destroyCategory'])->whereNumber('id');
        Route::get('/admin/education/product-options', [\App\Http\Controllers\Api\AdminEducationController::class, 'productOptions']);
        Route::get('/admin/education/program-options', [\App\Http\Controllers\Api\AdminEducationController::class, 'programOptions']);
        Route::get('/admin/education/courses', [\App\Http\Controllers\Api\AdminEducationController::class, 'courses']);
        Route::post('/admin/education/courses', [\App\Http\Controllers\Api\AdminEducationController::class, 'storeCourse']);
        Route::put('/admin/education/courses/{id}', [\App\Http\Controllers\Api\AdminEducationController::class, 'updateCourse']);
        Route::post('/admin/education/courses/{id}/move', [\App\Http\Controllers\Api\AdminEducationController::class, 'moveCourse'])->whereNumber('id');

        // Knowledge Base (admin CRUD для роли education)
        Route::get('/admin/kb/tree', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'tree']);
        Route::post('/admin/kb/sections', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'storeSection']);
        Route::put('/admin/kb/sections/{id}', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'updateSection'])->whereNumber('id');
        Route::delete('/admin/kb/sections/{id}', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'destroySection'])->whereNumber('id');
        Route::post('/admin/kb/sections/{id}/move', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'moveSection'])->whereNumber('id');
        Route::get('/admin/kb/sections/{id}/articles', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'articles'])->whereNumber('id');
        Route::get('/admin/kb/articles/{id}', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'showArticle'])->whereNumber('id');
        Route::post('/admin/kb/articles', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'storeArticle']);
        Route::put('/admin/kb/articles/{id}', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'updateArticle'])->whereNumber('id');
        Route::delete('/admin/kb/articles/{id}', [\App\Http\Controllers\Api\AdminKnowledgeBaseController::class, 'destroyArticle'])->whereNumber('id');

        // Курация домашних заданий (роль education + admin)
        Route::get('/admin/kb/homework', [\App\Http\Controllers\Api\HomeworkController::class, 'queue']);
        Route::post('/admin/kb/homework/{id}/review', [\App\Http\Controllers\Api\HomeworkController::class, 'review'])->whereNumber('id');
        Route::delete('/admin/education/courses/{id}', [\App\Http\Controllers\Api\AdminEducationController::class, 'destroyCourse']);
        Route::get('/admin/education/courses/{id}/lessons', [\App\Http\Controllers\Api\AdminEducationController::class, 'lessons'])->whereNumber('id');
        Route::post('/admin/education/courses/{id}/lessons', [\App\Http\Controllers\Api\AdminEducationController::class, 'storeLesson'])->whereNumber('id');
        Route::put('/admin/education/courses/{id}/lessons/{lessonId}', [\App\Http\Controllers\Api\AdminEducationController::class, 'updateLesson'])->whereNumber('id')->whereNumber('lessonId');
        Route::delete('/admin/education/courses/{id}/lessons/{lessonId}', [\App\Http\Controllers\Api\AdminEducationController::class, 'destroyLesson'])->whereNumber('id')->whereNumber('lessonId');
        Route::get('/admin/education/courses/{id}/tests', [\App\Http\Controllers\Api\AdminEducationController::class, 'tests'])->whereNumber('id');
        Route::post('/admin/education/courses/{id}/tests', [\App\Http\Controllers\Api\AdminEducationController::class, 'storeTest'])->whereNumber('id');
        Route::post('/admin/education/courses/{id}/tests/reorder', [\App\Http\Controllers\Api\AdminEducationController::class, 'reorderTests'])->whereNumber('id');
        Route::put('/admin/education/courses/{id}/tests/{testId}', [\App\Http\Controllers\Api\AdminEducationController::class, 'updateTest'])->whereNumber('id')->whereNumber('testId');
        Route::delete('/admin/education/courses/{id}/tests/{testId}', [\App\Http\Controllers\Api\AdminEducationController::class, 'destroyTest'])->whereNumber('id')->whereNumber('testId');
        }); // end role:staff
    });


    // Документы партнёра (паспорта/заявления) — публичный signed-роут.
    // Подпись (URL::temporarySignedRoute) выдаётся бэком в /documents и
    // /documents/upload только владельцу. Файлы лежат в private storage
    // (local), браузер ходит сюда без Bearer-токена.
    Route::get('/documents/{consultantId}/{type}', [\App\Http\Controllers\Api\DocumentController::class, 'download'])
        ->whereNumber('consultantId')
        ->name('documents.download')
        ->middleware('signed');

    // Скачивание вложений чата — публичный signed-роут.
    // Подпись (URL::temporarySignedRoute) выдаётся бэком уже после
    // авторизации в getMessages, имеет короткий expiry. Браузер при
    // клике по ссылке не передаёт Authorization Bearer — поэтому
    // обычный auth:sanctum middleware тут не подходит.
    Route::get('/chat/messages/{messageId}/attachment', [\App\Http\Controllers\Api\ChatController::class, 'downloadAttachment'])
        ->whereNumber('messageId')
        ->name('chat.attachment')
        ->middleware('signed');
});
