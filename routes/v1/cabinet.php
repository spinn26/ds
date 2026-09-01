<?php

/**
 * v1 — кабинет: роуты, доступные любому авторизованному пользователю
 * (партнёру и сотруднику). Дашборд, клиенты, контракты, структура,
 * профиль, чат, обучение, продукты, финансы партнёра.
 *
 * Файл подключается через require из routes/api.php внутри группы
 * Route::middleware(['auth:sanctum', 'maintenance']) — префикс v1
 * и оба middleware наследуются автоматически.
 */

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CommunicationController;
use App\Http\Controllers\Api\ContestController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\StructureController;
use Illuminate\Support\Facades\Route;

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

// Личный виджет Workspace: заметка-scratchpad. TODO-список (/my-tasks)
// удалён вместе с модулем «Задачи» (2026-08-14).
Route::get('/my-note', [\App\Http\Controllers\Api\UserDashboardController::class, 'getNote']);
Route::put('/my-note', [\App\Http\Controllers\Api\UserDashboardController::class, 'saveNote']);

// Presence для «Кто онлайн» + метрики «Мой день».
Route::put('/me/heartbeat', [\App\Http\Controllers\Api\UserDashboardController::class, 'heartbeat']);
Route::get('/staff/online', [\App\Http\Controllers\Api\UserDashboardController::class, 'whoOnline']);
Route::get('/my-day', [\App\Http\Controllers\Api\UserDashboardController::class, 'myDay']);

// Per spec ✅Написать собственику — тикет в платформенный чат собственнику (Ламакин)
Route::post('/founder-message', [\App\Http\Controllers\Api\FounderMessageController::class, 'send'])
    ->middleware('throttle:5,1'); // антиспам: 5 сообщений в минуту с пользователя
Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);
Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);

// Кастомные пункты меню для текущего пользователя (выдача для layouts).
Route::get('/menu/published', [\App\Http\Controllers\Api\AdminMenuController::class, 'published']);

// ───────── Оргструктура компании (только staff; правка — admin) ─────────
// Модуль «Задачи и Проекты» удалён (2026-08-14) вместе с таблицами;
// из меню он был убран ещё 2026-07-16. Группа осталась ради оргструктуры.
Route::middleware(['role:admin,backoffice,support,finance,head,calculations,corrections,education,invest', 'restrict.invest'])->group(function () {
Route::get('/org/departments', [\App\Http\Controllers\Api\OrgStructureController::class, 'index']);
Route::post('/org/departments', [\App\Http\Controllers\Api\OrgStructureController::class, 'store']);
Route::put('/org/departments/{id}', [\App\Http\Controllers\Api\OrgStructureController::class, 'update'])->whereNumber('id');
Route::delete('/org/departments/{id}', [\App\Http\Controllers\Api\OrgStructureController::class, 'destroy'])->whereNumber('id');
Route::post('/org/departments/{id}/members', [\App\Http\Controllers\Api\OrgStructureController::class, 'addMembers'])->whereNumber('id');
Route::delete('/org/departments/{id}/members/{user}', [\App\Http\Controllers\Api\OrgStructureController::class, 'removeMember'])->whereNumber('id')->whereNumber('user');
Route::get('/org/employees/{id}', [\App\Http\Controllers\Api\OrgStructureController::class, 'employee'])->whereNumber('id');
Route::put('/org/employees/{id}/position', [\App\Http\Controllers\Api\OrgStructureController::class, 'setPosition'])->whereNumber('id');
Route::get('/org/users', [\App\Http\Controllers\Api\OrgStructureController::class, 'searchUsers']);
}); // конец staff-группы (оргструктура)

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
// Динамика личных продаж: помесячно за год или по дням внутри месяца.
// Открывается кликом по иконке карточки объёмов на дашборде.
Route::get('/dashboard/dynamics', [DashboardController::class, 'dynamics']);
Route::get('/status-levels', [DashboardController::class, 'statusLevels']);

Route::get('/clients', [ClientController::class, 'index']);

// Комментарии к карточке партнёра (Structure.vue) — служебные заметки
// staff о партнёрах. Только для сотрудников: партнёрам/инвесторам
// закрыто (приватность; ранее было доступно любому авторизованному).
Route::middleware(['role:admin,backoffice,support,finance,head,calculations,corrections,education,invest', 'restrict.invest'])->group(function () {
    Route::get('/partner-comments/{consultantId}', [\App\Http\Controllers\Api\PartnerCommentsController::class, 'index'])->whereNumber('consultantId');
    Route::post('/partner-comments', [\App\Http\Controllers\Api\PartnerCommentsController::class, 'store']);
    Route::delete('/partner-comments/{id}', [\App\Http\Controllers\Api\PartnerCommentsController::class, 'destroy'])->whereNumber('id');
});

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
// Смена банковских реквизитов с доп. проверкой (запрос «было/стало»).
Route::post('/profile/bank-requisites/change-request', [\App\Http\Controllers\Api\BankRequisiteChangeController::class, 'store']);
Route::get('/profile/agreement-documents', [ProfileController::class, 'agreementDocuments']);
Route::post('/profile/accept-offer', [ProfileController::class, 'acceptOffer']);
// Самовосстановление после терминации. Throttle — чтобы кнопкой из
// блокирующего окна нельзя было молотить (гарды и лимит попыток — в
// PartnerStatusService::selfReinstate).
Route::post('/profile/reinstate', [ProfileController::class, 'reinstate'])
    ->middleware('throttle:5,60');
// Шаг «наставник» сразу после восстановления: остаться или перейти по
// реф-коду. Окно ограничено по времени и состоянию в контроллере.
Route::post('/profile/reinstate/mentor', [ProfileController::class, 'reinstateMentor'])
    ->middleware('throttle:20,60');
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

