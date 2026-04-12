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
            // Partner pages
            { path: '', component: () => import('../pages/Dashboard.vue') },
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
            { path: 'communication', component: () => import('../pages/Communication.vue') },
            { path: 'help', component: () => import('../pages/Help.vue') },
            { path: 'profile', component: () => import('../pages/Profile.vue') },

            // Admin management pages (inside main layout, admin-only)
            { path: 'manage/contracts', component: () => import('../pages/Admin/ContractManager.vue'), meta: { admin: true } },
            { path: 'manage/partners', component: () => import('../pages/Admin/Partners.vue'), meta: { admin: true } },
            { path: 'manage/partners/statuses', component: () => import('../pages/Admin/PartnerStatuses.vue'), meta: { admin: true } },
            { path: 'manage/clients', component: () => import('../pages/Admin/Clients.vue'), meta: { admin: true } },
            { path: 'manage/acceptance', component: () => import('../pages/Admin/Acceptance.vue'), meta: { admin: true } },
            { path: 'manage/requisites', component: () => import('../pages/Admin/Requisites.vue'), meta: { admin: true } },
            { path: 'manage/transfers', component: () => import('../pages/Admin/Transfers.vue'), meta: { admin: true } },
            { path: 'manage/transactions/import', component: () => import('../pages/Admin/TransactionImport.vue'), meta: { admin: true } },
            { path: 'manage/transactions', component: () => import('../pages/Admin/Transactions.vue'), meta: { admin: true } },
            { path: 'manage/commissions', component: () => import('../pages/Admin/Commissions.vue'), meta: { admin: true } },
            { path: 'manage/pool', component: () => import('../pages/Admin/Pool.vue'), meta: { admin: true } },
            { path: 'manage/qualifications', component: () => import('../pages/Admin/Qualifications.vue'), meta: { admin: true } },
            { path: 'manage/charges', component: () => import('../pages/Admin/Charges.vue'), meta: { admin: true } },
            { path: 'manage/payments', component: () => import('../pages/Admin/Payments.vue'), meta: { admin: true } },
            { path: 'manage/reports', component: () => import('../pages/Admin/Reports.vue'), meta: { admin: true } },
            { path: 'manage/currencies', component: () => import('../pages/Admin/Currencies.vue'), meta: { admin: true } },
        ],
    },

    // === Admin panel (only Users) ===
    {
        path: '/admin',
        component: () => import('../layouts/AdminLayout.vue'),
        meta: { auth: true, admin: true },
        children: [
            { path: '', redirect: '/admin/users' },
            { path: 'users', component: () => import('../pages/Admin/Users.vue') },
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

    if (auth.user?.role === 'registered' && !['education', 'profile', 'help'].some(p => to.path.includes(p))) {
        return '/education';
    }
});

export default router;
