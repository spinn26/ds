import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    // === Guest ===
    { path: '/login', component: () => import('../pages/Auth/Login.vue'), meta: { guest: true } },
    { path: '/register', component: () => import('../pages/Auth/Register.vue'), meta: { guest: true } },
    { path: '/forgot-password', component: () => import('../pages/Auth/ForgotPassword.vue'), meta: { guest: true } },
    { path: '/reset-password', component: () => import('../pages/Auth/ResetPassword.vue'), meta: { guest: true } },
    { path: '/maintenance', component: () => import('../pages/Maintenance.vue'), meta: { guest: true } },

    // === Partner SPA + Admin management pages ===
    {
        path: '/',
        component: () => import('../layouts/MainLayout.vue'),
        meta: { auth: true },
        children: [
            // Workspace = main page for all roles
            { path: '', component: () => import('../pages/Workspace.vue') },
            // Partner pages
            { path: 'dashboard', component: () => import('../pages/Dashboard.vue') },
            { path: 'terminated', component: () => import('../pages/Terminated.vue') },
            { path: 'education', component: () => import('../pages/Education.vue') },
            { path: 'education/courses/:id', component: () => import('../pages/EducationCourse.vue') },
            { path: 'education/courses/:id/lessons/:lid', component: () => import('../pages/EducationLesson.vue') },
            { path: 'education/courses/:id/test', component: () => import('../pages/EducationTest.vue') },
            { path: 'education/kb', component: () => import('../pages/EducationKb.vue') },
            { path: 'education/kb/sections/:id', component: () => import('../pages/EducationKbSection.vue') },
            { path: 'education/kb/articles/:id', component: () => import('../pages/EducationKbArticle.vue') },
            { path: 'referrals', component: () => import('../pages/Referrals.vue') },
            { path: 'finance/report', component: () => import('../pages/Finance/Report.vue') },
            { path: 'finance/calculator', component: () => import('../pages/Finance/Calculator.vue') },
            { path: 'clients', component: () => import('../pages/Clients/ClientList.vue') },
            { path: 'contracts', component: () => import('../pages/Contracts/MyContracts.vue') },
            { path: 'contracts/team', component: () => import('../pages/Contracts/TeamContracts.vue') },
            { path: 'structure', component: () => import('../pages/Structure.vue') },
            { path: 'my-payments', component: () => import('../pages/MyPayments.vue') },
            { path: 'products', component: () => import('../pages/Products.vue') },
            { path: 'tasks', component: () => import('../pages/Tasks/TasksHome.vue'), meta: { staff: true } },
            { path: 'insmart-widget', component: () => import('../pages/InsmartWidget.vue') },
            { path: 'contests', component: () => import('../pages/Contests.vue') },
            { path: 'chat', component: () => import('../pages/Chat/PartnerChat.vue') },
            { path: 'communication', redirect: '/chat' },
            { path: 'help', component: () => import('../pages/Help.vue') },
            { path: 'instructions', component: () => import('../pages/Instructions.vue') },
            { path: 'profile', component: () => import('../pages/Profile.vue') },
            { path: 'page/:slug', component: () => import('../pages/ContentPageView.vue') },

            // Staff management pages (inside main layout, staff-only)
            // Единый Рабочий стол: и «Главная» (/), и пункт меню «Рабочий стол»
            // (/manage/workspace) рендерят один и тот же Workspace.vue.
            // Компонент сам подсказывает блоки под роль (admin / staff / consultant).
            { path: 'manage/workspace', component: () => import('../pages/Workspace.vue'), meta: { staff: true } },
            { path: 'manage/org-structure', component: () => import('../pages/Manage/OrgStructure.vue'), meta: { staff: true } },
            { path: 'manage/periods', component: () => import('../pages/Admin/Periods.vue'), meta: { staff: true } },
            { path: 'manage/periods/:ym', component: () => import('../pages/Admin/PeriodCard.vue'), meta: { staff: true } },
            { path: 'manage/contracts', component: () => import('../pages/Admin/ContractManager.vue'), meta: { staff: true } },
            { path: 'manage/contracts/upload', component: () => import('../pages/Admin/ContractUpload.vue'), meta: { staff: true } },
            { path: 'manage/partners', component: () => import('../pages/Admin/Partners.vue'), meta: { staff: true } },
            { path: 'manage/partners/statuses', component: () => import('../pages/Admin/PartnerStatuses.vue'), meta: { staff: true } },
            { path: 'manage/clients', component: () => import('../pages/Admin/Clients.vue'), meta: { staff: true } },
            { path: 'manage/acceptance', component: () => import('../pages/Admin/Acceptance.vue'), meta: { staff: true } },
            { path: 'manage/requisites', component: () => import('../pages/Admin/Requisites.vue'), meta: { staff: true } },
            { path: 'manage/bank-changes', component: () => import('../pages/Admin/BankChanges.vue'), meta: { staff: true } },
            { path: 'manage/transfers', component: () => import('../pages/Admin/Transfers.vue'), meta: { staff: true } },
            { path: 'manage/permissions', component: () => import('../pages/Admin/Permissions.vue'), meta: { staff: true } },
            { path: 'manage/instructions', component: () => import('../pages/Admin/Instructions.vue'), meta: { staff: true } },
            { path: 'manage/documentation', component: () => import('../pages/Admin/Documentation.vue'), meta: { staff: true } },
            { path: 'manage/transactions/import', component: () => import('../pages/Admin/TransactionImport.vue'), meta: { staff: true } },
            { path: 'manage/transactions', component: () => import('../pages/Admin/Transactions.vue'), meta: { staff: true } },
            { path: 'manage/commissions', component: () => import('../pages/Admin/Commissions.vue'), meta: { staff: true } },
            { path: 'manage/pool', component: () => import('../pages/Admin/Pool.vue'), meta: { staff: true } },
            { path: 'manage/qualifications', component: () => import('../pages/Admin/Qualifications.vue'), meta: { staff: true } },
            { path: 'manage/charges', component: () => import('../pages/Admin/Charges.vue'), meta: { staff: true } },
            { path: 'manage/payments', component: () => import('../pages/Admin/PaymentRegistry.vue'), meta: { staff: true } },
            { path: 'manage/payments-legacy', component: () => import('../pages/Admin/Payments.vue'), meta: { staff: true } },
            { path: 'manage/chat', component: () => import('../pages/Chat/StaffChat.vue'), meta: { staff: true } },
            { path: 'manage/support', component: () => import('../pages/Manage/TechSupportDesk.vue'), meta: { staff: true } },
            { path: 'manage/chat/analytics', component: () => import('../pages/Chat/Analytics.vue'), meta: { staff: true } },
            { path: 'manage/reports', component: () => import('../pages/Admin/Reports.vue'), meta: { staff: true } },
            { path: 'manage/reports/sales-matrix', component: () => import('../pages/Admin/SalesMatrix.vue'), meta: { staff: true } },
            { path: 'manage/currencies', component: () => import('../pages/Admin/Currencies.vue'), meta: { staff: true } },
            { path: 'manage/management-currencies', component: () => import('../pages/Admin/ManagementCurrencies.vue'), meta: { staff: true } },
            { path: 'manage/products', component: () => import('../pages/Admin/Products.vue'), meta: { staff: true } },
            { path: 'manage/products-preview', component: () => import('../pages/Admin/ProductsPreview.vue'), meta: { staff: true } },
            { path: 'manage/education', component: () => import('../pages/Admin/EducationConstructor.vue'), meta: { staff: true } },
            { path: 'manage/education-legacy', component: () => import('../pages/Admin/Education.vue'), meta: { staff: true } },
            { path: 'manage/kb', component: () => import('../pages/Admin/KbConstructor.vue'), meta: { staff: true } },
            { path: 'manage/homework', component: () => import('../pages/Admin/HomeworkQueue.vue'), meta: { staff: true } },
            { path: 'manage/education/categories', component: () => import('../pages/Admin/EducationCategories.vue'), meta: { staff: true } },
            { path: 'manage/education/analytics', component: () => import('../pages/Admin/EducationAnalytics.vue'), meta: { staff: true } },
            { path: 'manage/partner-questionnaires', component: () => import('../pages/Admin/PartnerQuestionnaires.vue'), meta: { staff: true } },
            // VPN-инструкция для сотрудников. Намеренно НЕ в меню — доступ
            // только по прямой ссылке /manage/vpn (staff-only).
            { path: 'manage/vpn', component: () => import('../pages/Manage/VpnSetup.vue'), meta: { staff: true } },

            // Head (руководитель) — аналитика/дашборды клонируются в /manage/*
            // чтобы не открывать доступ в /admin/. Переиспользуют те же компоненты.
            { path: 'manage/owner-dashboard', component: () => import('../pages/Admin/OwnerDashboard.vue'), meta: { staff: true } },
            { path: 'manage/reconciliation', component: () => import('../pages/Admin/Reconciliation.vue'), meta: { staff: true } },
            { path: 'manage/anomalies', component: () => import('../pages/Admin/Anomalies.vue'), meta: { staff: true } },
            // Воронка партнёров объединена в дашборд руководителя.
            { path: 'manage/funnel', redirect: '/manage/owner-dashboard' },
            { path: 'manage/cohorts', component: () => import('../pages/Admin/Cohorts.vue'), meta: { staff: true } },
            { path: 'manage/contests', component: () => import('../pages/Admin/Contests.vue'), meta: { staff: true } },
            { path: 'status', component: () => import('../pages/SystemStatus.vue') },
            { path: 'manage/system-status', component: () => import('../pages/Admin/SystemStatus.vue'), meta: { staff: true, admin: true } },
            { path: 'forbidden', component: () => import('../pages/Forbidden.vue') },
        ],
    },

    // === Admin panel (only Users) ===
    {
        path: '/admin',
        component: () => import('../layouts/AdminLayout.vue'),
        meta: { auth: true, admin: true },
        children: [
            { path: '', redirect: '/admin/dashboard' },
            { path: 'dashboard', component: () => import('../pages/Admin/Dashboard.vue') },
            { path: 'news', component: () => import('../pages/Admin/News.vue') },
            { path: 'roadmap', component: () => import('../pages/Admin/Roadmap.vue') },
            { path: 'users', component: () => import('../pages/Admin/Users.vue') },
            { path: 'partners', component: () => import('../pages/Admin/Partners.vue') },
            { path: 'partners/statuses', component: () => import('../pages/Admin/PartnerStatuses.vue') },
            { path: 'clients', component: () => import('../pages/Admin/Clients.vue') },
            { path: 'hidden-clients', component: () => import('../pages/Admin/HiddenClients.vue') },
            { path: 'contracts', component: () => import('../pages/Admin/ContractManager.vue') },
            { path: 'acceptance', component: () => import('../pages/Admin/Acceptance.vue') },
            { path: 'requisites', component: () => import('../pages/Admin/Requisites.vue') },
            { path: 'transfers', component: () => import('../pages/Admin/Transfers.vue') },
            { path: 'transactions/import', component: () => import('../pages/Admin/TransactionImport.vue') },
            { path: 'transactions', component: () => import('../pages/Admin/Transactions.vue') },
            { path: 'commissions', component: () => import('../pages/Admin/Commissions.vue') },
            { path: 'pool', component: () => import('../pages/Admin/Pool.vue') },
            { path: 'qualifications', component: () => import('../pages/Admin/Qualifications.vue') },
            { path: 'charges', component: () => import('../pages/Admin/Charges.vue') },
            { path: 'payments', component: () => import('../pages/Admin/Payments.vue') },
            { path: 'products', component: () => import('../pages/Admin/Products.vue') },
            { path: 'education', component: () => import('../pages/Admin/Education.vue') },
            { path: 'contests', component: () => import('../pages/Admin/Contests.vue') },
            { path: 'reports', component: () => import('../pages/Admin/Reports.vue') },
            { path: 'currencies', component: () => import('../pages/Admin/Currencies.vue') },
            { path: 'references', component: () => import('../pages/Admin/References.vue') },
            { path: 'references/:catalog', component: () => import('../pages/Admin/ReferenceDetail.vue') },
            { path: 'mail', component: () => import('../pages/Admin/Mail.vue') },
            { path: 'monitoring', component: () => import('../pages/Admin/Monitoring.vue') },
            { path: 'activity', component: () => import('../pages/Admin/Activity.vue') },

            // New admin sections — owner dashboard + analytics + ops tools
            { path: 'owner-dashboard', component: () => import('../pages/Admin/OwnerDashboard.vue') },
            { path: 'reconciliation', component: () => import('../pages/Admin/Reconciliation.vue') },
            { path: 'anomalies', component: () => import('../pages/Admin/Anomalies.vue') },
            { path: 'calendar', component: () => import('../pages/Admin/OpsCalendar.vue') },
            { path: 'bulk-ops', component: () => import('../pages/Admin/BulkOps.vue') },
            // Воронка партнёров объединена в дашборд руководителя.
            { path: 'funnel', redirect: '/admin/owner-dashboard' },
            { path: 'cohorts', component: () => import('../pages/Admin/Cohorts.vue') },
            { path: 'triggers', component: () => import('../pages/Admin/Triggers.vue') },
            { path: 'integrations', component: () => import('../pages/Admin/Integrations.vue') },
            { path: 'settings', component: () => import('../pages/Admin/Settings.vue') },
            { path: 'design', component: () => import('../pages/Admin/Design.vue') },
            { path: 'custom-fields', component: () => import('../pages/Admin/CustomFields.vue') },
            { path: 'announcements', component: () => import('../pages/Admin/Announcements.vue') },
            { path: 'feature-flags', component: () => import('../pages/Admin/FeatureFlags.vue') },
            { path: 'content-pages', component: () => import('../pages/Admin/ContentPages.vue') },
            { path: 'system', component: () => import('../pages/Admin/SystemOps.vue') },
            { path: 'media', component: () => import('../pages/Admin/MediaLibrary.vue') },
            { path: 'export-center', component: () => import('../pages/Admin/ExportCenter.vue') },
            { path: 'login-log', component: () => import('../pages/Admin/LoginLog.vue') },
            { path: 'notifications-broadcast', component: () => import('../pages/Admin/NotificationBroadcast.vue') },
            { path: 'audit-log', component: () => import('../pages/Admin/AuditLog.vue') },
            { path: 'translations', component: () => import('../pages/Admin/Translations.vue') },
            { path: 'qualification-matrix', component: () => import('../pages/Admin/QualificationMatrix.vue') },
            { path: 'webhooks', component: () => import('../pages/Admin/Webhooks.vue') },
            { path: 'menu-builder', component: () => import('../pages/Admin/MenuBuilder.vue') },
            // API-ключи и токены перенесены во вкладку «API ключи»
            // раздела Интеграции (единая точка настройки внешних
            // сервисов). Старый путь редиректит для бэкап-ссылок.
            { path: 'api-keys', redirect: '/admin/integrations?tab=api-keys' },
        ],
    },

    { path: '/not-found', component: () => import('../pages/NotFound.vue'), meta: { auth: true } },
    { path: '/:pathMatch(.*)*', redirect: '/not-found' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.initialized) await auth.fetchUser();

    // Освежаем права из БД при навигации (троттл внутри) — чтобы правки
    // «Группы и права» применялись к активной сессии без релогина. Не
    // await'им: навигацию не тормозим, до ответа работает текущий снимок.
    auth.maybeRefreshPermissions();

    if (to.meta.auth && !auth.user) return '/login';
    if (to.meta.guest && auth.user) return '/';
    // Send unauthorised users to an explicit 403 instead of bouncing them
    // to '/', which produces a silent "nothing happened" UX.
    // /admin/* доступен только админу. Для других staff (finance, calculations,
    // backoffice, …) есть клоны в /manage/* — редирект туда, если такой
    // путь существует и у пользователя есть права. Иначе — forbidden.
    if (to.meta.admin && !auth.isAdmin) {
        if (auth.isStaff && to.path.startsWith('/admin/')) {
            const manageEquiv = to.path.replace(/^\/admin\//, '/manage/');
            // Точечные алиасы: /admin/payment-registry → /manage/payments.
            const aliases = { '/admin/payment-registry': '/manage/payments' };
            const target = aliases[to.path] || manageEquiv;
            return target;
        }
        return '/forbidden';
    }
    if (to.meta.staff && !auth.isStaff) return '/forbidden';

    // Onboarding questionnaire: any non-staff user must fill it before the cabinet.
    // Allow /, /profile and /help so the dialog can be shown and identity edited.
    // This check takes precedence over the "registered -> /education" rule below —
    // otherwise a just-registered user ping-pongs between / and /education.
    if (
        auth.user
        && !auth.isStaff
        && auth.user.questionnaireCompleted === false
        && !['/', '/profile', '/help'].includes(to.path)
    ) {
        return '/';
    }

    // Registered users (questionnaire done) → only education
    if (
        auth.user?.role === 'registered'
        && auth.user?.questionnaireCompleted === true
        && !['education', 'profile', 'help'].some(p => to.path.includes(p))
    ) {
        return '/education';
    }

    // Terminated/Excluded → blocked cabinet (only communication, profile, terminated page)
    if (auth.isConsultant && (auth.isTerminated || auth.isExcluded)) {
        const allowedPaths = ['terminated', 'profile', 'help'];
        if (!allowedPaths.some(p => to.path.includes(p))) {
            return '/terminated';
        }
    }
});

export default router;
