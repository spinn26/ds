import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    // === Guest ===
    { path: '/login', component: () => import('../pages/Auth/Login.vue'), meta: { guest: true } },
    { path: '/register', component: () => import('../pages/Auth/Register.vue'), meta: { guest: true } },

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
            { path: 'referrals', component: () => import('../pages/Referrals.vue') },
            { path: 'finance/report', component: () => import('../pages/Finance/Report.vue') },
            { path: 'finance/calculator', component: () => import('../pages/Finance/Calculator.vue') },
            { path: 'clients', component: () => import('../pages/Clients/ClientList.vue') },
            { path: 'contracts', component: () => import('../pages/Contracts/MyContracts.vue') },
            { path: 'contracts/team', component: () => import('../pages/Contracts/TeamContracts.vue') },
            { path: 'structure', component: () => import('../pages/Structure.vue') },
            { path: 'products', component: () => import('../pages/Products.vue') },
            { path: 'contests', component: () => import('../pages/Contests.vue') },
            { path: 'chat', component: () => import('../pages/Chat/PartnerChat.vue') },
            { path: 'communication', redirect: '/chat' },
            { path: 'help', component: () => import('../pages/Help.vue') },
            { path: 'profile', component: () => import('../pages/Profile.vue') },

            // Staff management pages (inside main layout, staff-only)
            { path: 'manage/contracts', component: () => import('../pages/Admin/ContractManager.vue'), meta: { staff: true } },
            { path: 'manage/contracts/upload', component: () => import('../pages/Admin/ContractUpload.vue'), meta: { staff: true } },
            { path: 'manage/partners', component: () => import('../pages/Admin/Partners.vue'), meta: { staff: true } },
            { path: 'manage/partners/statuses', component: () => import('../pages/Admin/PartnerStatuses.vue'), meta: { staff: true } },
            { path: 'manage/clients', component: () => import('../pages/Admin/Clients.vue'), meta: { staff: true } },
            { path: 'manage/acceptance', component: () => import('../pages/Admin/Acceptance.vue'), meta: { staff: true } },
            { path: 'manage/requisites', component: () => import('../pages/Admin/Requisites.vue'), meta: { staff: true } },
            { path: 'manage/transfers', component: () => import('../pages/Admin/Transfers.vue'), meta: { staff: true } },
            { path: 'manage/transactions/import', component: () => import('../pages/Admin/TransactionImport.vue'), meta: { staff: true } },
            { path: 'manage/transactions', component: () => import('../pages/Admin/Transactions.vue'), meta: { staff: true } },
            { path: 'manage/commissions', component: () => import('../pages/Admin/Commissions.vue'), meta: { staff: true } },
            { path: 'manage/pool', component: () => import('../pages/Admin/Pool.vue'), meta: { staff: true } },
            { path: 'manage/qualifications', component: () => import('../pages/Admin/Qualifications.vue'), meta: { staff: true } },
            { path: 'manage/charges', component: () => import('../pages/Admin/Charges.vue'), meta: { staff: true } },
            { path: 'manage/payments', component: () => import('../pages/Admin/Payments.vue'), meta: { staff: true } },
            { path: 'manage/chat', component: () => import('../pages/Chat/StaffChat.vue'), meta: { staff: true } },
            { path: 'manage/reports', component: () => import('../pages/Admin/Reports.vue'), meta: { staff: true } },
            { path: 'manage/currencies', component: () => import('../pages/Admin/Currencies.vue'), meta: { staff: true } },
            { path: 'manage/products', component: () => import('../pages/Admin/Products.vue'), meta: { staff: true } },
            { path: 'manage/contests', component: () => import('../pages/Admin/Contests.vue'), meta: { staff: true } },
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
            { path: 'users', component: () => import('../pages/Admin/Users.vue') },
            { path: 'partners', component: () => import('../pages/Admin/Partners.vue') },
            { path: 'partners/statuses', component: () => import('../pages/Admin/PartnerStatuses.vue') },
            { path: 'clients', component: () => import('../pages/Admin/Clients.vue') },
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
        ],
    },

    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.initialized) await auth.fetchUser();

    if (to.meta.auth && !auth.user) return '/login';
    if (to.meta.guest && auth.user) return '/';
    if (to.meta.admin && !auth.isAdmin) return '/';
    if (to.meta.staff && !auth.isStaff) return '/';

    // Registered users → only education
    if (auth.user?.role === 'registered' && !['education', 'profile', 'help'].some(p => to.path.includes(p))) {
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
